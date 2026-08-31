<?php

declare(strict_types=1);

namespace App\Repo;

use PDO;

/**
 * Read-only aggregate queries backing docs/06-history-reports.md Tier 1, plus
 * the two Tier 2 shapes docs/03-api.md § Stats pulls into this same table
 * (flow, seats) so the endpoint list matches the spec even though their UI
 * is Phase 10. Never cached (06-history-reports.md § Implementation notes).
 *
 * Every method takes the same filter shape:
 *   from?: string              games.started_at >=
 *   to?: string                games.started_at <=
 *   player_ids?: list<int>     restrict to these players (both sides of a
 *                              flow pair must be in the list when set)
 *   player_count?: int|'all'   defaults to 4 upstream (D25); 'all' blends
 *                              deliberately across counts
 *   include_abandoned?: bool   default false
 *
 * Games in scope are always 'completed' and 'in_progress'; 'abandoned' is
 * added only when include_abandoned is true.
 */
final class StatsRepo
{
    private const WIND_NAMES = ['East', 'South', 'West', 'North'];

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function leaderboard(array $filters): array
    {
        [$scopeSql, $scopeParams] = $this->scopeCte($filters);
        $playerFilter = $this->playerIdsFilter($filters, 'gs.player_id');

        $sql = "{$scopeSql}
            SELECT gs.player_id,
                   COUNT(DISTINCT gs.game_id) AS games,
                   COUNT(hs.hand_id) AS hands,
                   COALESCE(SUM(hs.points_delta), 0) AS net_points,
                   SUM(CASE WHEN h.outcome = 'win' AND h.winner_player_id = gs.player_id THEN 1 ELSE 0 END) AS hands_won
            FROM game_seats gs
            JOIN scoped_games sg ON sg.id = gs.game_id
            JOIN hands h ON h.game_id = gs.game_id
            JOIN hand_scores hs ON hs.hand_id = h.id AND hs.player_id = gs.player_id
            {$playerFilter['sql']}
            GROUP BY gs.player_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...$scopeParams, ...$playerFilter['params']]);

        $base = [];
        foreach ($stmt->fetchAll() as $row) {
            $base[(int) $row['player_id']] = [
                'games' => (int) $row['games'],
                'hands' => (int) $row['hands'],
                'net_points' => (int) $row['net_points'],
                'hands_won' => (int) $row['hands_won'],
            ];
        }

        if ($base === []) {
            return [];
        }

        $gamesWon = $this->gamesWonByPlayer($filters);
        $faanStats = $this->faanStatsByPlayer($filters);
        $players = $this->playerPayloads(array_keys($base));

        $rows = [];
        foreach ($base as $pid => $agg) {
            $rows[] = $this->leaderboardRow($pid, $agg, $gamesWon[$pid] ?? 0, $faanStats[$pid] ?? [], $players[$pid] ?? null);
        }

        // Default sort: game win % descending, per docs/06-history-reports.md
        // "Which ranking is the ranking" — the frontend may re-sort by any
        // column, but this is the sort the leaderboard opens on.
        usort($rows, static function (array $a, array $b): int {
            $rateCompare = ($b['game_win_rate'] ?? -1) <=> ($a['game_win_rate'] ?? -1);
            return $rateCompare !== 0 ? $rateCompare : $b['games'] <=> $a['games'];
        });

        return $rows;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<string,mixed>|null null if the player does not exist at all
     */
    public function playerDetail(int $playerId, array $filters): ?array
    {
        $scopedFilters = $filters;
        $scopedFilters['player_ids'] = [$playerId];

        $rows = $this->leaderboard($scopedFilters);
        $row = $rows[0] ?? null;

        if ($row === null) {
            $player = $this->playerPayloads([$playerId])[$playerId] ?? null;
            if ($player === null) {
                return null;
            }
            $row = $this->leaderboardRow($playerId, ['games' => 0, 'hands' => 0, 'net_points' => 0, 'hands_won' => 0], 0, [], $player);
        }

        [$scopeSql, $scopeParams] = $this->scopeCte($filters);

        $seriesSql = "{$scopeSql}
            SELECT sg.id AS game_id, h.hand_number, hs.points_delta
            FROM scoped_games sg
            JOIN games g ON g.id = sg.id
            JOIN hands h ON h.game_id = sg.id
            JOIN hand_scores hs ON hs.hand_id = h.id AND hs.player_id = ?
            ORDER BY g.started_at ASC, sg.id ASC, h.hand_number ASC";
        $stmt = $this->pdo->prepare($seriesSql);
        $stmt->execute([...$scopeParams, $playerId]);

        $series = [];
        $cumulative = 0;
        foreach ($stmt->fetchAll() as $r) {
            $cumulative += (int) $r['points_delta'];
            $series[] = [
                'game_id' => (int) $r['game_id'],
                'hand_number' => (int) $r['hand_number'],
                'cumulative' => $cumulative,
            ];
        }

        $histSql = "{$scopeSql}
            SELECT h.faan, COUNT(*) AS count
            FROM hands h
            JOIN scoped_games sg ON sg.id = h.game_id
            WHERE h.outcome = 'win' AND h.winner_player_id = ?
            GROUP BY h.faan
            ORDER BY h.faan";
        $stmt2 = $this->pdo->prepare($histSql);
        $stmt2->execute([...$scopeParams, $playerId]);

        $histogram = [];
        foreach ($stmt2->fetchAll() as $r) {
            $histogram[] = ['faan' => (int) $r['faan'], 'count' => (int) $r['count']];
        }

        $row['points_over_time'] = $series;
        $row['faan_histogram'] = $histogram;

        return $row;
    }

    /**
     * N x N matrix of net points transferred from the row player to the
     * column player (docs/06-history-reports.md #5). Draws contribute
     * nothing; a win attributes each loser's negative delta to the winner;
     * a penalty attributes each recipient's positive delta to the offender.
     *
     * @param array<string,mixed> $filters
     * @return array{players: list<array<string,mixed>>, matrix: list<list<int>>}
     */
    public function flow(array $filters): array
    {
        [$scopeSql, $scopeParams] = $this->scopeCte($filters);

        $sql = "{$scopeSql}
            SELECT h.id AS hand_id, h.outcome, h.winner_player_id, h.offender_player_id,
                   hs.player_id, hs.points_delta
            FROM hands h
            JOIN scoped_games sg ON sg.id = h.game_id
            JOIN hand_scores hs ON hs.hand_id = h.id
            WHERE h.outcome IN ('win', 'penalty')";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($scopeParams);

        $byHand = [];
        foreach ($stmt->fetchAll() as $r) {
            $handId = (int) $r['hand_id'];
            $byHand[$handId]['outcome'] ??= (string) $r['outcome'];
            $byHand[$handId]['winner'] ??= $r['winner_player_id'] !== null ? (int) $r['winner_player_id'] : null;
            $byHand[$handId]['offender'] ??= $r['offender_player_id'] !== null ? (int) $r['offender_player_id'] : null;
            $byHand[$handId]['deltas'][(int) $r['player_id']] = (int) $r['points_delta'];
        }

        $playerIdsFilter = null;
        if (!empty($filters['player_ids'])) {
            $playerIdsFilter = array_map(intval(...), $filters['player_ids']);
        }

        $flow = []; // payer => receiver => amount
        $involved = [];
        foreach ($byHand as $hand) {
            if ($hand['outcome'] === 'win') {
                $winner = $hand['winner'];
                if ($winner === null) {
                    continue;
                }
                foreach ($hand['deltas'] as $pid => $delta) {
                    if ($pid === $winner || $delta >= 0) {
                        continue;
                    }
                    $this->addFlow($flow, $involved, $pid, $winner, -$delta, $playerIdsFilter);
                }
            } else { // penalty
                $offender = $hand['offender'];
                if ($offender === null) {
                    continue;
                }
                foreach ($hand['deltas'] as $pid => $delta) {
                    if ($pid === $offender || $delta <= 0) {
                        continue;
                    }
                    $this->addFlow($flow, $involved, $offender, $pid, $delta, $playerIdsFilter);
                }
            }
        }

        $ids = array_keys($involved);
        sort($ids);
        $players = $this->playerPayloads($ids);

        $matrix = [];
        foreach ($ids as $rowId) {
            $rowValues = [];
            foreach ($ids as $colId) {
                $rowValues[] = $rowId === $colId ? 0 : ($flow[$rowId][$colId] ?? 0);
            }
            $matrix[] = $rowValues;
        }

        return [
            'players' => array_map(static fn (int $id): array => $players[$id], $ids),
            'matrix' => $matrix,
        ];
    }

    /**
     * Net points and win rate grouped by the wind actually held when the
     * hand was played (docs/06-history-reports.md #6): does East really win
     * more? Aggregated across the whole population in scope, not per
     * player — `?player_ids=` narrows which players' hands count, it does
     * not return one row per player. wind_index 0 doubles as the dealer
     * bucket, so its win_rate is the dealer win rate.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>>
     */
    public function seats(array $filters): array
    {
        [$scopeSql, $scopeParams] = $this->scopeCte($filters);
        $playerFilter = $this->playerIdsFilter($filters, 'gs.player_id');

        $sql = "{$scopeSql}
            SELECT MOD(gs.wind_index - h.dealer_wind_index + 4, 4) AS wind_held,
                   COUNT(*) AS hands,
                   COALESCE(SUM(hs.points_delta), 0) AS net_points,
                   SUM(CASE WHEN h.outcome = 'win' AND h.winner_player_id = gs.player_id THEN 1 ELSE 0 END) AS hands_won
            FROM game_seats gs
            JOIN scoped_games sg ON sg.id = gs.game_id
            JOIN hands h ON h.game_id = gs.game_id
            JOIN hand_scores hs ON hs.hand_id = h.id AND hs.player_id = gs.player_id
            {$playerFilter['sql']}
            GROUP BY wind_held
            ORDER BY wind_held";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...$scopeParams, ...$playerFilter['params']]);

        $rows = [];
        foreach ($stmt->fetchAll() as $r) {
            $wind = (int) $r['wind_held'];
            $hands = (int) $r['hands'];
            $handsWon = (int) $r['hands_won'];
            $rows[] = [
                'wind_index' => $wind,
                'wind_name' => self::WIND_NAMES[$wind],
                'hands' => $hands,
                'net_points' => (int) $r['net_points'],
                'hands_won' => $handsWon,
                'win_rate' => $hands > 0 ? $handsWon / $hands : null,
            ];
        }

        return $rows;
    }

    /**
     * Per-hand cumulative totals for one game, for the replay chart
     * (docs/06-history-reports.md #2). Unscoped by the usual filters — a
     * single game either exists or it doesn't.
     *
     * @return array<string,mixed>|null null if the game does not exist
     */
    public function gameCurve(int $gameId): ?array
    {
        $exists = $this->pdo->prepare('SELECT id FROM games WHERE id = ?');
        $exists->execute([$gameId]);
        if ($exists->fetchColumn() === false) {
            return null;
        }

        $seatStmt = $this->pdo->prepare('SELECT wind_index, player_id FROM game_seats WHERE game_id = ? ORDER BY wind_index');
        $seatStmt->execute([$gameId]);
        $playerIds = array_map(static fn (array $r): int => (int) $r['player_id'], $seatStmt->fetchAll());
        $players = $this->playerPayloads($playerIds);

        $stmt = $this->pdo->prepare(
            'SELECT h.hand_number, h.round_wind, hs.player_id, hs.points_delta
             FROM hands h JOIN hand_scores hs ON hs.hand_id = h.id
             WHERE h.game_id = ? ORDER BY h.hand_number ASC'
        );
        $stmt->execute([$gameId]);

        $byHand = [];
        foreach ($stmt->fetchAll() as $r) {
            $hn = (int) $r['hand_number'];
            $byHand[$hn]['round_wind'] = (int) $r['round_wind'];
            $byHand[$hn]['deltas'][(int) $r['player_id']] = (int) $r['points_delta'];
        }
        ksort($byHand);

        $cumulative = array_fill_keys($playerIds, 0);
        $points = [];
        $roundBoundaries = [];
        $lastRound = null;
        foreach ($byHand as $hn => $data) {
            if ($lastRound !== null && $data['round_wind'] !== $lastRound) {
                $roundBoundaries[] = $hn;
            }
            $lastRound = $data['round_wind'];

            foreach ($data['deltas'] as $pid => $delta) {
                $cumulative[$pid] = ($cumulative[$pid] ?? 0) + $delta;
            }

            $totals = [];
            foreach ($cumulative as $pid => $total) {
                $totals[(string) $pid] = $total;
            }
            $points[] = ['hand_number' => $hn, 'totals' => (object) $totals];
        }

        return [
            'players' => array_map(static fn (int $id): array => $players[$id], $playerIds),
            'points' => $points,
            'round_boundaries' => $roundBoundaries,
        ];
    }

    /**
     * @param array<int,array<int,int>> $flow payer => receiver => amount, by reference
     * @param array<int,true> $involved by reference
     * @param list<int>|null $playerIdsFilter
     */
    private function addFlow(array &$flow, array &$involved, int $payer, int $receiver, int $amount, ?array $playerIdsFilter): void
    {
        if ($playerIdsFilter !== null && (!in_array($payer, $playerIdsFilter, true) || !in_array($receiver, $playerIdsFilter, true))) {
            return;
        }

        $flow[$payer][$receiver] = ($flow[$payer][$receiver] ?? 0) + $amount;
        $involved[$payer] = true;
        $involved[$receiver] = true;
    }

    /**
     * @param array{games:int, hands:int, net_points:int, hands_won:int} $agg
     * @param array{avg_faan?:float, best_hand?:array<string,mixed>} $faanStats
     * @param array<string,mixed>|null $player
     * @return array<string,mixed>
     */
    private function leaderboardRow(int $playerId, array $agg, int $gamesWon, array $faanStats, ?array $player): array
    {
        $games = $agg['games'];
        $hands = $agg['hands'];

        return [
            'player' => $player,
            'net_points' => $agg['net_points'],
            'games' => $games,
            'games_won' => $gamesWon,
            'game_win_rate' => $games > 0 ? $gamesWon / $games : null,
            'hands' => $hands,
            'hands_won' => $agg['hands_won'],
            'hand_win_rate' => $hands > 0 ? $agg['hands_won'] / $hands : null,
            'points_per_hand' => $hands > 0 ? $agg['net_points'] / $hands : null,
            'avg_faan' => $faanStats['avg_faan'] ?? null,
            'best_hand' => $faanStats['best_hand'] ?? null,
        ];
    }

    /**
     * Games where a player finished rank 1 within that game. Ties share
     * rank 1 (the same dense-rank convention GameService::buildPayload uses
     * for the live standings), so a tied game counts for every tied player.
     *
     * @param array<string,mixed> $filters
     * @return array<int,int> player_id => games won
     */
    private function gamesWonByPlayer(array $filters): array
    {
        [$scopeSql, $scopeParams] = $this->scopeCte($filters);
        $playerFilter = $this->playerIdsFilter($filters, 'gs.player_id');

        $sql = "{$scopeSql}
            SELECT sg.id AS game_id, gs.player_id,
                   COALESCE(SUM(hs.points_delta), 0) AS total
            FROM scoped_games sg
            JOIN game_seats gs ON gs.game_id = sg.id
            LEFT JOIN hands h ON h.game_id = sg.id
            LEFT JOIN hand_scores hs ON hs.hand_id = h.id AND hs.player_id = gs.player_id
            {$playerFilter['sql']}
            GROUP BY sg.id, gs.player_id";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([...$scopeParams, ...$playerFilter['params']]);

        $byGame = [];
        foreach ($stmt->fetchAll() as $row) {
            $byGame[(int) $row['game_id']][(int) $row['player_id']] = (int) $row['total'];
        }

        $wins = [];
        foreach ($byGame as $totals) {
            $max = max($totals);
            foreach ($totals as $pid => $total) {
                if ($total === $max) {
                    $wins[$pid] = ($wins[$pid] ?? 0) + 1;
                }
            }
        }

        return $wins;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int, array{avg_faan?:float, best_hand?:array<string,mixed>}>
     */
    private function faanStatsByPlayer(array $filters): array
    {
        [$scopeSql, $scopeParams] = $this->scopeCte($filters);

        $where = ["h.outcome = 'win'"];
        $params = $scopeParams;
        if (!empty($filters['player_ids'])) {
            $ids = array_map(intval(...), $filters['player_ids']);
            $where[] = 'h.winner_player_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        $whereSql = implode(' AND ', $where);

        $result = [];

        $avgSql = "{$scopeSql}
            SELECT h.winner_player_id AS player_id, AVG(h.faan) AS avg_faan
            FROM hands h JOIN scoped_games sg ON sg.id = h.game_id
            WHERE {$whereSql}
            GROUP BY h.winner_player_id";
        $stmt = $this->pdo->prepare($avgSql);
        $stmt->execute($params);
        foreach ($stmt->fetchAll() as $row) {
            $result[(int) $row['player_id']]['avg_faan'] = round((float) $row['avg_faan'], 2);
        }

        // One row per player: their highest faan win, earliest on a tie.
        $bestSql = "{$scopeSql}
            SELECT t.player_id, t.hand_id, t.game_id, t.faan FROM (
                SELECT h.winner_player_id AS player_id, h.id AS hand_id, h.game_id, h.faan,
                       ROW_NUMBER() OVER (PARTITION BY h.winner_player_id ORDER BY h.faan DESC, h.id ASC) AS rn
                FROM hands h JOIN scoped_games sg ON sg.id = h.game_id
                WHERE {$whereSql}
            ) t WHERE t.rn = 1";
        $stmt2 = $this->pdo->prepare($bestSql);
        $stmt2->execute($params);
        foreach ($stmt2->fetchAll() as $row) {
            $result[(int) $row['player_id']]['best_hand'] = [
                'hand_id' => (int) $row['hand_id'],
                'game_id' => (int) $row['game_id'],
                'faan' => (int) $row['faan'],
            ];
        }

        return $result;
    }

    /**
     * @param list<int> $ids
     * @return array<int, array{id:int, name:string, color:string, avatar_url:string}>
     */
    private function playerPayloads(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT id, name, color, avatar_path FROM players WHERE id IN ({$placeholders})");
        $stmt->execute(array_map(intval(...), $ids));

        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $out[(int) $row['id']] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'color' => (string) $row['color'],
                'avatar_url' => $row['avatar_path'] !== null ? '/' . $row['avatar_path'] : '/default.svg',
            ];
        }

        return $out;
    }

    /**
     * Builds the `WITH scoped_games AS (...)` CTE text every query above
     * prefixes itself with, applying the shared from/to/player_count/
     * include_abandoned filters. Each call returns fresh SQL text + params
     * in the order they must be bound — callers append any of their own
     * placeholders after these.
     *
     * @param array<string,mixed> $filters
     * @return array{0:string, 1:list<mixed>}
     */
    private function scopeCte(array $filters): array
    {
        $conditions = [];
        $params = [];

        $statuses = ['completed', 'in_progress'];
        if (!empty($filters['include_abandoned'])) {
            $statuses[] = 'abandoned';
        }
        $conditions[] = 'status IN (' . implode(',', array_fill(0, count($statuses), '?')) . ')';
        array_push($params, ...$statuses);

        if (isset($filters['from'])) {
            $conditions[] = 'started_at >= ?';
            $params[] = $filters['from'];
        }
        if (isset($filters['to'])) {
            $conditions[] = 'started_at <= ?';
            $params[] = $filters['to'];
        }

        // D25: defaults to 4 upstream in routes.php; 'all' blends deliberately.
        $playerCount = $filters['player_count'] ?? 4;
        if ($playerCount !== 'all') {
            $conditions[] = 'player_count = ?';
            $params[] = (int) $playerCount;
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);
        $sql = "WITH scoped_games AS (SELECT id FROM games {$where}) ";

        return [$sql, $params];
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{sql:string, params:list<int>}
     */
    private function playerIdsFilter(array $filters, string $column): array
    {
        if (empty($filters['player_ids'])) {
            return ['sql' => '', 'params' => []];
        }

        $ids = array_map(intval(...), $filters['player_ids']);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return ['sql' => "WHERE {$column} IN ({$placeholders})", 'params' => $ids];
    }
}
