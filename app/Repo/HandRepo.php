<?php

declare(strict_types=1);

namespace App\Repo;

use PDO;

/**
 * Pure data access for the append-only `hands` + `hand_scores` log.
 * See docs/01-data-model.md: never UPDATE a row here; undo deletes the
 * highest hand_number.
 */
final class HandRepo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * Ascending by hand_number — the order GameState::replay expects.
     *
     * @return list<array{id:int, hand_number:int, round_wind:int, dealer_wind_index:int, outcome:string, winner_player_id:?int, faan:?int, win_type:?string, discarder_player_id:?int, liable_player_id:?int, base_points:?int, offender_player_id:?int, penalty_per_player:?int, note:?string, created_at:string, scores:array<int,int>}>
     */
    public function listForGame(int $gameId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, hand_number, round_wind, dealer_wind_index, outcome, winner_player_id, faan,
                    win_type, discarder_player_id, liable_player_id, base_points, offender_player_id,
                    penalty_per_player, note, created_at
             FROM hands WHERE game_id = ? ORDER BY hand_number ASC'
        );
        $stmt->execute([$gameId]);
        $hands = $stmt->fetchAll();

        if ($hands === []) {
            return [];
        }

        $handIds = array_map(static fn (array $h): int => (int) $h['id'], $hands);
        $placeholders = implode(',', array_fill(0, count($handIds), '?'));
        $scoreStmt = $this->pdo->prepare(
            "SELECT hand_id, player_id, points_delta FROM hand_scores WHERE hand_id IN ({$placeholders})"
        );
        $scoreStmt->execute($handIds);

        $scoresByHand = [];
        foreach ($scoreStmt->fetchAll() as $row) {
            $scoresByHand[(int) $row['hand_id']][(int) $row['player_id']] = (int) $row['points_delta'];
        }

        return array_map(
            fn (array $h): array => $this->hydrate($h, $scoresByHand[(int) $h['id']] ?? []),
            $hands
        );
    }

    public function maxHandNumber(int $gameId): int
    {
        $stmt = $this->pdo->prepare('SELECT COALESCE(MAX(hand_number), 0) FROM hands WHERE game_id = ?');
        $stmt->execute([$gameId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array{outcome:string, winner_player_id?:?int, faan?:?int, win_type?:?string, discarder_player_id?:?int, liable_player_id?:?int, base_points?:?int, offender_player_id?:?int, penalty_per_player?:?int, note?:?string} $hand
     * @param array<int,int> $deltas playerId => delta
     */
    public function insert(
        int $gameId,
        int $handNumber,
        int $roundWind,
        int $dealerWindIndex,
        array $hand,
        array $deltas,
        ?int $createdByUserId
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO hands (
                game_id, hand_number, round_wind, dealer_wind_index, outcome,
                winner_player_id, faan, win_type, discarder_player_id, liable_player_id, base_points,
                offender_player_id, penalty_per_player, note, created_by_user_id
             ) VALUES (?,?,?,?,?, ?,?,?,?,?,?, ?,?,?,?)'
        );
        $stmt->execute([
            $gameId, $handNumber, $roundWind, $dealerWindIndex, $hand['outcome'],
            $hand['winner_player_id'] ?? null, $hand['faan'] ?? null, $hand['win_type'] ?? null,
            $hand['discarder_player_id'] ?? null, $hand['liable_player_id'] ?? null, $hand['base_points'] ?? null,
            $hand['offender_player_id'] ?? null, $hand['penalty_per_player'] ?? null, $hand['note'] ?? null,
            $createdByUserId,
        ]);
        $handId = (int) $this->pdo->lastInsertId();

        $scoreStmt = $this->pdo->prepare('INSERT INTO hand_scores (hand_id, player_id, points_delta) VALUES (?, ?, ?)');
        foreach ($deltas as $playerId => $delta) {
            $scoreStmt->execute([$handId, $playerId, $delta]);
        }

        return $handId;
    }

    /** Deletes the highest hand_number row for the game; cascades to hand_scores. Caller checks maxHandNumber() > 0 first. */
    public function deleteLast(int $gameId): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM hands WHERE game_id = ? ORDER BY hand_number DESC LIMIT 1');
        $stmt->execute([$gameId]);
        $handId = $stmt->fetchColumn();
        if ($handId === false) {
            return;
        }

        $this->pdo->prepare('DELETE FROM hands WHERE id = ?')->execute([$handId]);
    }

    /**
     * @param array<string,mixed> $row
     * @param array<int,int> $scores
     * @return array{id:int, hand_number:int, round_wind:int, dealer_wind_index:int, outcome:string, winner_player_id:?int, faan:?int, win_type:?string, discarder_player_id:?int, liable_player_id:?int, base_points:?int, offender_player_id:?int, penalty_per_player:?int, note:?string, created_at:string, scores:array<int,int>}
     */
    private function hydrate(array $row, array $scores): array
    {
        return [
            'id' => (int) $row['id'],
            'hand_number' => (int) $row['hand_number'],
            'round_wind' => (int) $row['round_wind'],
            'dealer_wind_index' => (int) $row['dealer_wind_index'],
            'outcome' => (string) $row['outcome'],
            'winner_player_id' => $row['winner_player_id'] !== null ? (int) $row['winner_player_id'] : null,
            'faan' => $row['faan'] !== null ? (int) $row['faan'] : null,
            'win_type' => $row['win_type'] !== null ? (string) $row['win_type'] : null,
            'discarder_player_id' => $row['discarder_player_id'] !== null ? (int) $row['discarder_player_id'] : null,
            'liable_player_id' => $row['liable_player_id'] !== null ? (int) $row['liable_player_id'] : null,
            'base_points' => $row['base_points'] !== null ? (int) $row['base_points'] : null,
            'offender_player_id' => $row['offender_player_id'] !== null ? (int) $row['offender_player_id'] : null,
            'penalty_per_player' => $row['penalty_per_player'] !== null ? (int) $row['penalty_per_player'] : null,
            'note' => $row['note'] !== null ? (string) $row['note'] : null,
            'created_at' => (string) $row['created_at'],
            'scores' => $scores,
        ];
    }
}
