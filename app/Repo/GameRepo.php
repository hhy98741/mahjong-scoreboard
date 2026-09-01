<?php

declare(strict_types=1);

namespace App\Repo;

use PDO;

/**
 * Pure data access for `games` + `game_seats`. Validation and the
 * eight-step hand transaction live in Service\GameService — this class
 * only reads and writes rows.
 */
final class GameRepo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function hasInProgressGame(): bool
    {
        return ((int) $this->pdo->query("SELECT COUNT(*) FROM games WHERE status = 'in_progress'")->fetchColumn()) > 0;
    }

    public function findCurrentId(): ?int
    {
        $stmt = $this->pdo->query("SELECT id FROM games WHERE status = 'in_progress' LIMIT 1");
        $id = $stmt->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /** @return array{id:int, name:?string, ruleset_id:?int, ruleset_snapshot:array<string,mixed>, status:string, player_count:int, min_faan:int, max_faan:int, starting_points:int, started_at:string, ended_at:?string, seats:array<int,int>}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, ruleset_id, ruleset_snapshot, status, player_count, min_faan, max_faan, starting_points, started_at, ended_at
             FROM games WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * SELECT ... FOR UPDATE — must be called inside an open transaction.
     * Row-locks the game for the duration of the eight-step hand transaction
     * (docs-initial-build/03-api.md § POST /api/games/{id}/hands, step 1).
     *
     * @return array{id:int, name:?string, ruleset_id:?int, ruleset_snapshot:array<string,mixed>, status:string, player_count:int, min_faan:int, max_faan:int, starting_points:int, started_at:string, ended_at:?string, seats:array<int,int>}|null
     */
    public function lockForUpdate(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, name, ruleset_id, ruleset_snapshot, status, player_count, min_faan, max_faan, starting_points, started_at, ended_at
             FROM games WHERE id = ? FOR UPDATE'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string,mixed> $rulesetSnapshot
     * @param array<int,int> $seats wind_index => player_id
     */
    public function create(
        ?int $rulesetId,
        array $rulesetSnapshot,
        ?string $name,
        int $playerCount,
        int $minFaan,
        int $maxFaan,
        int $startingPoints,
        array $seats,
        ?int $createdByUserId
    ): int {
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO games (name, ruleset_id, ruleset_snapshot, player_count, min_faan, max_faan, starting_points, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                $name,
                $rulesetId,
                json_encode($rulesetSnapshot, JSON_THROW_ON_ERROR),
                $playerCount,
                $minFaan,
                $maxFaan,
                $startingPoints,
                $createdByUserId,
            ]);
            $gameId = (int) $this->pdo->lastInsertId();

            $seatStmt = $this->pdo->prepare('INSERT INTO game_seats (game_id, wind_index, player_id) VALUES (?, ?, ?)');
            foreach ($seats as $windIndex => $playerId) {
                $seatStmt->execute([$gameId, $windIndex, $playerId]);
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }

        return $gameId;
    }

    public function updateName(int $id, ?string $name): void
    {
        $this->pdo->prepare('UPDATE games SET name = ? WHERE id = ?')->execute([$name, $id]);
    }

    public function endGame(int $id, string $status): void
    {
        $this->pdo->prepare("UPDATE games SET status = ?, ended_at = NOW() WHERE id = ?")->execute([$status, $id]);
    }

    /** Undo of the hand that completed a game (docs-initial-build/02-scoring-engine.md § Undo). */
    public function reopen(int $id): void
    {
        $this->pdo->prepare("UPDATE games SET status = 'in_progress', ended_at = NULL WHERE id = ?")->execute([$id]);
    }

    /** Cascades to game_seats, hands, hand_scores via FK ON DELETE CASCADE. */
    public function hardDelete(int $id): void
    {
        $this->pdo->prepare('DELETE FROM games WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array{status?:string, from?:string, to?:string, player_id?:int, player_count?:int, limit?:int, offset?:int} $filters
     * @return list<int>
     */
    public function listIds(array $filters): array
    {
        $where = [];
        $params = [];
        $joins = '';

        if (isset($filters['player_id'])) {
            $joins = 'JOIN game_seats gs_f ON gs_f.game_id = g.id AND gs_f.player_id = ?';
            $params[] = $filters['player_id'];
        }
        if (isset($filters['status'])) {
            $where[] = 'g.status = ?';
            $params[] = $filters['status'];
        }
        if (isset($filters['from'])) {
            $where[] = 'g.started_at >= ?';
            $params[] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $where[] = 'g.started_at <= ?';
            $params[] = $filters['to'];
        }
        if (isset($filters['player_count'])) {
            $where[] = 'g.player_count = ?';
            $params[] = $filters['player_count'];
        }

        // LIMIT/OFFSET are cast to int and interpolated directly: MariaDB's
        // native (non-emulated) prepares reject them bound as placeholders.
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $sql = "SELECT g.id FROM games g {$joins}";
        if ($where !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= " ORDER BY g.started_at DESC, g.id DESC LIMIT {$limit} OFFSET {$offset}";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<int,int> player_id => SUM(points_delta) across the game's hands */
    public function totals(int $gameId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT hs.player_id, SUM(hs.points_delta) AS total
             FROM hand_scores hs JOIN hands h ON h.id = hs.hand_id
             WHERE h.game_id = ? GROUP BY hs.player_id'
        );
        $stmt->execute([$gameId]);

        $totals = [];
        foreach ($stmt->fetchAll() as $row) {
            $totals[(int) $row['player_id']] = (int) $row['total'];
        }

        return $totals;
    }

    /**
     * @param array<string,mixed> $row
     * @return array{id:int, name:?string, ruleset_id:?int, ruleset_snapshot:array<string,mixed>, status:string, player_count:int, min_faan:int, max_faan:int, starting_points:int, started_at:string, ended_at:?string, seats:array<int,int>}
     */
    private function hydrate(array $row): array
    {
        $id = (int) $row['id'];

        $seatStmt = $this->pdo->prepare('SELECT wind_index, player_id FROM game_seats WHERE game_id = ? ORDER BY wind_index');
        $seatStmt->execute([$id]);
        $seats = [];
        foreach ($seatStmt->fetchAll() as $seatRow) {
            $seats[(int) $seatRow['wind_index']] = (int) $seatRow['player_id'];
        }

        return [
            'id' => $id,
            'name' => $row['name'] !== null ? (string) $row['name'] : null,
            'ruleset_id' => $row['ruleset_id'] !== null ? (int) $row['ruleset_id'] : null,
            'ruleset_snapshot' => json_decode((string) $row['ruleset_snapshot'], true, flags: JSON_THROW_ON_ERROR),
            'status' => (string) $row['status'],
            'player_count' => (int) $row['player_count'],
            'min_faan' => (int) $row['min_faan'],
            'max_faan' => (int) $row['max_faan'],
            'starting_points' => (int) $row['starting_points'],
            'started_at' => (string) $row['started_at'],
            'ended_at' => $row['ended_at'] !== null ? (string) $row['ended_at'] : null,
            'seats' => $seats,
        ];
    }
}
