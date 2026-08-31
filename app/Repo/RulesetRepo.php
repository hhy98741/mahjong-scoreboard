<?php

declare(strict_types=1);

namespace App\Repo;

use App\Domain\DomainException;
use PDO;

final class RulesetRepo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int, name:string, table_max_faan:int, payment_rule:string, penalty_default:int, is_default:bool, points:array<string,int>}> */
    public function all(): array
    {
        $ids = array_map(intval(...), $this->pdo->query('SELECT id FROM rulesets ORDER BY name')->fetchAll(PDO::FETCH_COLUMN));

        return array_map(fn (int $id): array => $this->find($id), $ids);
    }

    /** @return array{id:int, name:string, table_max_faan:int, payment_rule:string, penalty_default:int, is_default:bool, points:array<string,int>}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, table_max_faan, payment_rule, penalty_default, is_default FROM rulesets WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<mixed, mixed> $points faan (numeric key or string) => base_points
     * @return array{id:int, name:string, table_max_faan:int, payment_rule:string, penalty_default:int, is_default:bool, points:array<string,int>}
     */
    public function create(string $name, int $tableMaxFaan, int $penaltyDefault, array $points): array
    {
        $normalized = $this->normalizePoints($tableMaxFaan, $points);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO rulesets (name, table_max_faan, penalty_default) VALUES (?, ?, ?)'
            );
            try {
                $stmt->execute([$name, $tableMaxFaan, $penaltyDefault]);
            } catch (\PDOException $e) {
                throw $this->translateUniqueViolation($e, $name);
            }

            $id = (int) $this->pdo->lastInsertId();
            $this->replacePoints($id, $normalized);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->find($id);
    }

    /**
     * Full replace: rulesets metadata plus every ruleset_points row. Shrinking
     * table_max_faan drops the now out-of-range rows (rule 11).
     *
     * @param array<mixed, mixed> $points
     * @return array{id:int, name:string, table_max_faan:int, payment_rule:string, penalty_default:int, is_default:bool, points:array<string,int>}
     */
    public function replace(int $id, string $name, int $tableMaxFaan, int $penaltyDefault, array $points): array
    {
        $normalized = $this->normalizePoints($tableMaxFaan, $points);

        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'UPDATE rulesets SET name = ?, table_max_faan = ?, penalty_default = ? WHERE id = ?'
            );
            try {
                $stmt->execute([$name, $tableMaxFaan, $penaltyDefault, $id]);
            } catch (\PDOException $e) {
                throw $this->translateUniqueViolation($e, $name);
            }

            $this->replacePoints($id, $normalized);
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $this->find($id);
    }

    public function delete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM rulesets WHERE id = ?')->execute([$id]);
    }

    // Guards DELETE /api/rulesets/{id} (docs/03-api.md § Rulesets): completed
    // games carry their own snapshot and are unaffected, so only an
    // in_progress reference blocks the delete.
    public function isReferencedByInProgressGame(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM games WHERE ruleset_id = ? AND status = 'in_progress'"
        );
        $stmt->execute([$id]);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    /**
     * @param array<mixed, mixed> $points
     * @return array<int, int> faan => base_points, keys exactly 0..$tableMaxFaan
     */
    private function normalizePoints(int $tableMaxFaan, array $points): array
    {
        if ($tableMaxFaan < 0 || $tableMaxFaan > 13) {
            throw new DomainException('table_max_faan must be between 0 and 13.');
        }

        $normalized = [];
        foreach ($points as $faan => $base) {
            if (!is_numeric($faan) || !is_numeric($base)) {
                throw new DomainException('points must map faan values to non-negative integers.');
            }
            $faanInt = (int) $faan;
            $baseInt = (int) $base;
            if ($baseInt < 0) {
                throw new DomainException('base_points must be 0 or greater.');
            }
            $normalized[$faanInt] = $baseInt;
        }

        $inRange = [];
        for ($faan = 0; $faan <= $tableMaxFaan; $faan++) {
            if (!array_key_exists($faan, $normalized)) {
                throw new DomainException("Missing points for faan {$faan}.");
            }
            $inRange[$faan] = $normalized[$faan];
        }

        return $inRange;
    }

    /** @param array<int, int> $points */
    private function replacePoints(int $rulesetId, array $points): void
    {
        $this->pdo->prepare('DELETE FROM ruleset_points WHERE ruleset_id = ?')->execute([$rulesetId]);

        $stmt = $this->pdo->prepare('INSERT INTO ruleset_points (ruleset_id, faan, base_points) VALUES (?, ?, ?)');
        foreach ($points as $faan => $base) {
            $stmt->execute([$rulesetId, $faan, $base]);
        }
    }

    private function translateUniqueViolation(\PDOException $e, string $name): \PDOException|DomainException
    {
        if ($e->getCode() === '23000') {
            return new DomainException("A ruleset named '{$name}' already exists.");
        }

        return $e;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:int, name:string, table_max_faan:int, payment_rule:string, penalty_default:int, is_default:bool, points:array<string,int>}
     */
    private function hydrate(array $row): array
    {
        $stmt = $this->pdo->prepare('SELECT faan, base_points FROM ruleset_points WHERE ruleset_id = ? ORDER BY faan');
        $stmt->execute([$row['id']]);

        $points = [];
        foreach ($stmt->fetchAll() as $p) {
            $points[(string) (int) $p['faan']] = (int) $p['base_points'];
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'table_max_faan' => (int) $row['table_max_faan'],
            'payment_rule' => (string) $row['payment_rule'],
            'penalty_default' => (int) $row['penalty_default'],
            'is_default' => (bool) $row['is_default'],
            'points' => $points,
        ];
    }
}
