<?php

declare(strict_types=1);

namespace App\Repo;

use App\Domain\DomainException;
use PDO;

final class PlayerRepo
{
    // The four tile colors (D26 / 04-frontend.md § Color scheme), cycled by
    // creation order. Overridable per player afterwards.
    private const COLOR_CYCLE = ['#C1272D', '#1B8A4B', '#1F5FA8', '#B08A2E'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int, name:string, avatar_path:?string, color:string, is_active:bool}> */
    public function all(bool $includeInactive): array
    {
        $sql = 'SELECT id, name, avatar_path, color, is_active FROM players';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name';

        $stmt = $this->pdo->query($sql);

        return array_map($this->hydrate(...), $stmt->fetchAll());
    }

    /** @return array{id:int, name:string, avatar_path:?string, color:string, is_active:bool}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, name, avatar_path, color, is_active FROM players WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** @return array{id:int, name:string, avatar_path:?string, color:string, is_active:bool} */
    public function create(string $name, ?string $color): array
    {
        $resolvedColor = $color ?? $this->nextDefaultColor();

        $stmt = $this->pdo->prepare('INSERT INTO players (name, color) VALUES (?, ?)');
        try {
            $stmt->execute([$name, $resolvedColor]);
        } catch (\PDOException $e) {
            throw $this->translateUniqueViolation($e, $name);
        }

        return $this->find((int) $this->pdo->lastInsertId());
    }

    /** @return array{id:int, name:string, avatar_path:?string, color:string, is_active:bool} */
    public function update(int $id, ?string $name, ?string $color, ?bool $isActive): array
    {
        $fields = [];
        $params = [];

        if ($name !== null) {
            $fields[] = 'name = ?';
            $params[] = $name;
        }
        if ($color !== null) {
            $fields[] = 'color = ?';
            $params[] = $color;
        }
        if ($isActive !== null) {
            $fields[] = 'is_active = ?';
            $params[] = $isActive ? 1 : 0;
        }

        if ($fields !== []) {
            $params[] = $id;
            $stmt = $this->pdo->prepare('UPDATE players SET ' . implode(', ', $fields) . ' WHERE id = ?');
            try {
                $stmt->execute($params);
            } catch (\PDOException $e) {
                throw $this->translateUniqueViolation($e, $name ?? '');
            }
        }

        return $this->find($id);
    }

    public function updateAvatarPath(int $id, ?string $avatarPath): void
    {
        $this->pdo->prepare('UPDATE players SET avatar_path = ? WHERE id = ?')->execute([$avatarPath, $id]);
    }

    public function softDelete(int $id): void
    {
        $this->pdo->prepare('UPDATE players SET is_active = 0 WHERE id = ?')->execute([$id]);
    }

    // Guards DELETE /api/players/{id} (docs/03-api.md § Players): 409 if the
    // player is seated in the one game that may be in_progress.
    public function isSeatedInInProgressGame(int $id): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM game_seats gs
             JOIN games g ON g.id = gs.game_id
             WHERE gs.player_id = ? AND g.status = 'in_progress'"
        );
        $stmt->execute([$id]);

        return ((int) $stmt->fetchColumn()) > 0;
    }

    private function nextDefaultColor(): string
    {
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM players')->fetchColumn();

        return self::COLOR_CYCLE[$count % 4];
    }

    private function translateUniqueViolation(\PDOException $e, string $name): \PDOException|DomainException
    {
        if ($e->getCode() === '23000') {
            return new DomainException("A player named '{$name}' already exists.");
        }

        return $e;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:int, name:string, avatar_path:?string, color:string, is_active:bool}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'avatar_path' => $row['avatar_path'] !== null ? (string) $row['avatar_path'] : null,
            'color' => (string) $row['color'],
            'is_active' => (bool) $row['is_active'],
        ];
    }
}
