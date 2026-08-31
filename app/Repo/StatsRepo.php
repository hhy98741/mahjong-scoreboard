<?php

declare(strict_types=1);

namespace App\Repo;

use PDO;

/**
 * Read-only aggregate queries backing docs/06-history-reports.md Tier 1, plus
 * the five Tier 2 shapes docs/03-api.md § Stats pulls into this same table
 * (flow, seats, records, feeders, win-types) so the endpoint list matches the
 * spec even though their UI is Phase 10. Never cached (06-history-reports.md
 * § Implementation notes).
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
            SELECT MOD(CAST(gs.wind_index AS SIGNED) - CAST(h.dealer_wind_index AS SIGNED) + 4, 4) AS wind_held,
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
     * The streaks-and-records board (docs/06-history-reports.md #7,
     * docs/03-api.md § GET /api/stats/records). Eight fixed keys, each null
     * when scope has no qualifying data. `?player_ids=` narrows the players
     * considered throughout (same convention as seats()) but never changes
     * what actually happened in a game — win streak, drought, dealer
     * defences and comeback are all computed from the FULL seated table so
     * a filtered-out player's hands still count against the filtered-in
     * ones; the filter only gates which player can be reported as the
     * record holder.
     *
     * @param array<string,mixed> $filters
     * @return array<string,mixed>
     */
    public function records(array $filters): array
    {
        [$scopeSql, $scopeParams] = $this->scopeCte($filters);
        $playerFilter = !empty($filters['player_ids']) ? array_map(intval(...), $filters['player_ids']) : null;

        $handSql = "{$scopeSql}
            SELECT sg.id AS game_id, g.started_at, h.id AS hand_id, h.hand_number, h.outcome,
                   h.winner_player_id, h.faan, h.dealer_wind_index
            FROM scoped_games sg
            JOIN games g ON g.id = sg.id
            JOIN hands h ON h.game_id = sg.id
            ORDER BY g.started_at ASC, sg.id ASC, h.hand_number ASC";
        $stmt = $this->pdo->prepare($handSql);
        $stmt->execute($scopeParams);
        $handRows = $stmt->fetchAll();

        $empty = [
            'biggest_hand_points' => null,
            'biggest_hand_faan' => null,
            'longest_win_streak' => null,
            'longest_drought' => null,
            'biggest_comeback' => null,
            'most_dealer_defences' => null,
            'best_night' => null,
            'worst_night' => null,
        ];

        if ($handRows === []) {
            return $empty;
        }

        $handIds = array_map(static fn (array $r): int => (int) $r['hand_id'], $handRows);
        $placeholders = implode(',', array_fill(0, count($handIds), '?'));
        $scoreStmt = $this->pdo->prepare("SELECT hand_id, player_id, points_delta FROM hand_scores WHERE hand_id IN ({$placeholders})");
        $scoreStmt->execute($handIds);
        $deltasByHand = [];
        foreach ($scoreStmt->fetchAll() as $r) {
            $deltasByHand[(int) $r['hand_id']][(int) $r['player_id']] = (int) $r['points_delta'];
        }

        $gameIds = array_values(array_unique(array_map(static fn (array $r): int => (int) $r['game_id'], $handRows)));
        $gamePlaceholders = implode(',', array_fill(0, count($gameIds), '?'));
        $seatStmt = $this->pdo->prepare("SELECT game_id, wind_index, player_id FROM game_seats WHERE game_id IN ({$gamePlaceholders})");
        $seatStmt->execute($gameIds);
        $seatsByGame = [];    // game_id => [wind_index => player_id]
        $playersByGame = [];  // game_id => list<player_id>
        foreach ($seatStmt->fetchAll() as $r) {
            $gameId = (int) $r['game_id'];
            $seatsByGame[$gameId][(int) $r['wind_index']] = (int) $r['player_id'];
            $playersByGame[$gameId][] = (int) $r['player_id'];
        }

        $byGame = [];
        foreach ($handRows as $r) {
            $byGame[(int) $r['game_id']][] = $r;
        }

        $best = $empty;
        $nightTotals = [];  // date => player_id => net_points
        $nightGames = [];   // date => player_id => list<game_id>

        foreach ($byGame as $gameId => $rows) {
            $seatedPlayers = $playersByGame[$gameId] ?? [];
            $wind = $seatsByGame[$gameId] ?? [];
            $result = $this->scanGameForRecords($gameId, $rows, $seatedPlayers, $wind, $deltasByHand, $playerFilter);

            foreach (['biggest_hand_points' => 'points', 'biggest_hand_faan' => 'faan', 'longest_win_streak' => 'length',
                      'longest_drought' => 'length', 'biggest_comeback' => 'deficit', 'most_dealer_defences' => 'defences'] as $key => $metric) {
                $candidate = $result[$key];
                if ($candidate !== null && ($best[$key] === null || $candidate[$metric] > $best[$key][$metric])) {
                    $best[$key] = $candidate;
                }
            }

            $date = substr((string) $rows[0]['started_at'], 0, 10);
            foreach ($seatedPlayers as $pid) {
                if ($playerFilter !== null && !in_array($pid, $playerFilter, true)) {
                    continue;
                }
                $total = 0;
                foreach ($rows as $r) {
                    $total += $deltasByHand[(int) $r['hand_id']][$pid] ?? 0;
                }
                $nightTotals[$date][$pid] = ($nightTotals[$date][$pid] ?? 0) + $total;
                $nightGames[$date][$pid][] = $gameId;
            }
        }

        $bestNight = null;
        $worstNight = null;
        foreach ($nightTotals as $date => $byPlayer) {
            foreach ($byPlayer as $pid => $net) {
                if ($bestNight === null || $net > $bestNight['net_points']) {
                    $bestNight = ['player_id' => $pid, 'date' => $date, 'net_points' => $net, 'game_ids' => $nightGames[$date][$pid]];
                }
                if ($worstNight === null || $net < $worstNight['net_points']) {
                    $worstNight = ['player_id' => $pid, 'date' => $date, 'net_points' => $net, 'game_ids' => $nightGames[$date][$pid]];
                }
            }
        }
        $best['best_night'] = $bestNight;
        $best['worst_night'] = $worstNight;

        $involved = [];
        foreach ($best as $record) {
            if ($record !== null) {
                $involved[$record['player_id']] = true;
            }
        }
        $players = $this->playerPayloads(array_keys($involved));

        $out = [];
        foreach ($best as $key => $record) {
            if ($record === null) {
                $out[$key] = null;
                continue;
            }
            $record['player'] = $players[$record['player_id']] ?? null;
            unset($record['player_id']);
            $out[$key] = $record;
        }

        return $out;
    }

    /**
     * One game's contribution to each records() bucket (except the night
     * totals, folded in by the caller). Returns null per key when this game
     * has nothing to offer that bucket.
     *
     * @param list<array<string,mixed>> $rows this game's hands, hand_number ascending
     * @param list<int> $seatedPlayers
     * @param array<int,int> $wind wind_index => player_id
     * @param array<int,array<int,int>> $deltasByHand hand_id => player_id => points_delta
     * @param list<int>|null $playerFilter
     * @return array<string,array<string,mixed>|null>
     */
    private function scanGameForRecords(int $gameId, array $rows, array $seatedPlayers, array $wind, array $deltasByHand, ?array $playerFilter): array
    {
        $allowed = static fn (int $pid): bool => $playerFilter === null || in_array($pid, $playerFilter, true);

        $streak = array_fill_keys($seatedPlayers, 0);
        $drought = array_fill_keys($seatedPlayers, 0);
        $cumulative = array_fill_keys($seatedPlayers, 0);
        $cumulativeHistory = [['hand_id' => null, 'totals' => $cumulative]];

        $bestHandPoints = null;
        $bestHandFaan = null;
        $bestStreak = null;
        $bestDrought = null;
        $bestDefences = null;

        $runDealerWind = null;
        $runLength = 0;

        foreach ($rows as $r) {
            $handId = (int) $r['hand_id'];
            $outcome = (string) $r['outcome'];
            $winner = $r['winner_player_id'] !== null ? (int) $r['winner_player_id'] : null;
            $faan = $r['faan'] !== null ? (int) $r['faan'] : null;
            $dealerWind = (int) $r['dealer_wind_index'];
            $deltas = $deltasByHand[$handId] ?? [];

            foreach ($seatedPlayers as $pid) {
                if ($outcome === 'win' && $winner === $pid) {
                    $streak[$pid]++;
                    $drought[$pid] = 0;
                    if ($allowed($pid) && ($bestStreak === null || $streak[$pid] > $bestStreak['length'])) {
                        $bestStreak = ['player_id' => $pid, 'game_id' => $gameId, 'hand_id' => $handId, 'length' => $streak[$pid]];
                    }
                } else {
                    $streak[$pid] = 0;
                    $drought[$pid]++;
                    if ($allowed($pid) && ($bestDrought === null || $drought[$pid] > $bestDrought['length'])) {
                        $bestDrought = ['player_id' => $pid, 'game_id' => $gameId, 'hand_id' => $handId, 'length' => $drought[$pid]];
                    }
                }
            }

            if ($outcome === 'win' && $winner !== null && $allowed($winner)) {
                $winnerDelta = $deltas[$winner] ?? 0;
                if ($bestHandPoints === null || $winnerDelta > $bestHandPoints['points']) {
                    $bestHandPoints = ['player_id' => $winner, 'game_id' => $gameId, 'hand_id' => $handId, 'points' => $winnerDelta];
                }
                if ($faan !== null && ($bestHandFaan === null || $faan > $bestHandFaan['faan'])) {
                    $bestHandFaan = ['player_id' => $winner, 'game_id' => $gameId, 'hand_id' => $handId, 'faan' => $faan];
                }
            }

            $runLength = $dealerWind === $runDealerWind ? $runLength + 1 : 1;
            $runDealerWind = $dealerWind;
            $dealerPid = $wind[$dealerWind] ?? null;
            if ($runLength >= 2 && $dealerPid !== null && $allowed($dealerPid)) {
                $defences = $runLength - 1;
                if ($bestDefences === null || $defences > $bestDefences['defences']) {
                    $bestDefences = ['player_id' => $dealerPid, 'game_id' => $gameId, 'hand_id' => $handId, 'defences' => $defences];
                }
            }

            foreach ($deltas as $pid => $delta) {
                if (array_key_exists($pid, $cumulative)) {
                    $cumulative[$pid] += $delta;
                }
            }
            $cumulativeHistory[] = ['hand_id' => $handId, 'totals' => $cumulative];
        }

        $bestComeback = null;
        $finalTotals = $cumulativeHistory[count($cumulativeHistory) - 1]['totals'];
        if ($finalTotals !== []) {
            $maxFinal = max($finalTotals);
            $winners = array_keys(array_filter($finalTotals, static fn (int $v): bool => $v === $maxFinal));
            foreach ($winners as $winnerPid) {
                if (!$allowed($winnerPid)) {
                    continue;
                }
                $peakDeficit = 0;
                $peakHandId = null;
                foreach ($cumulativeHistory as $snapshot) {
                    $others = $snapshot['totals'];
                    $winnerTotal = $others[$winnerPid] ?? 0;
                    unset($others[$winnerPid]);
                    if ($others === []) {
                        continue;
                    }
                    $deficit = max($others) - $winnerTotal;
                    if ($deficit > $peakDeficit) {
                        $peakDeficit = $deficit;
                        $peakHandId = $snapshot['hand_id'];
                    }
                }
                if ($peakDeficit > 0 && ($bestComeback === null || $peakDeficit > $bestComeback['deficit'])) {
                    $bestComeback = ['player_id' => $winnerPid, 'game_id' => $gameId, 'hand_id' => $peakHandId, 'deficit' => $peakDeficit];
                }
            }
        }

        return [
            'biggest_hand_points' => $bestHandPoints,
            'biggest_hand_faan' => $bestHandFaan,
            'longest_win_streak' => $bestStreak,
            'longest_drought' => $bestDrought,
            'biggest_comeback' => $bestComeback,
            'most_dealer_defences' => $bestDefences,
        ];
    }

    /**
     * Per player, as the discarder (docs/06-history-reports.md #8): hands
     * dealt into, points paid as discarder, and discard rate vs. the table
     * average. The complement of the win stats — nobody tracks this by
     * hand. One row per player seated on at least one hand in scope;
     * `?player_ids=` narrows the population itself (same convention as
     * seats(), unlike records()) — a player left out contributes no hands
     * and no discards to anyone's numbers, including the table average.
     *
     * @param array<string,mixed> $filters
     * @return array{table_avg_discard_rate: float|null, players: list<array<string,mixed>>}
     */
    public function feeders(array $filters): array
    {
        [$scopeSql, $scopeParams] = $this->scopeCte($filters);
        $playerFilter = $this->playerIdsFilter($filters, 'gs.player_id');

        // Population + denominator: every hand each in-scope player was
        // seated for, same "hands" definition leaderboard() uses.
        $handsSql = "{$scopeSql}
            SELECT gs.player_id, COUNT(hs.hand_id) AS hands
            FROM game_seats gs
            JOIN scoped_games sg ON sg.id = gs.game_id
            JOIN hands h ON h.game_id = gs.game_id
            JOIN hand_scores hs ON hs.hand_id = h.id AND hs.player_id = gs.player_id
            {$playerFilter['sql']}
            GROUP BY gs.player_id";
        $stmt = $this->pdo->prepare($handsSql);
        $stmt->execute([...$scopeParams, ...$playerFilter['params']]);

        $agg = [];
        foreach ($stmt->fetchAll() as $row) {
            $agg[(int) $row['player_id']] = ['hands' => (int) $row['hands'], 'discards' => 0, 'points_paid' => 0];
        }

        if ($agg === []) {
            return ['table_avg_discard_rate' => null, 'players' => []];
        }

        // Numerator: hands this player dealt into as the discarder, and
        // what their own points_delta was on each (negated to read as an
        // amount paid). A bao discard still counts as one dealt-into hand
        // (rule 16) — bao changes who pays, not who dealt.
        $discarderFilter = $this->playerIdsFilter($filters, 'h.discarder_player_id');
        $discardSql = "{$scopeSql}
            SELECT h.discarder_player_id AS player_id, COUNT(*) AS discards,
                   COALESCE(SUM(hs.points_delta), 0) AS raw_delta
            FROM hands h
            JOIN scoped_games sg ON sg.id = h.game_id
            JOIN hand_scores hs ON hs.hand_id = h.id AND hs.player_id = h.discarder_player_id
            WHERE h.outcome = 'win' AND h.win_type = 'discard'
            " . ($discarderFilter['sql'] !== '' ? 'AND ' . substr($discarderFilter['sql'], 6) : '') . "
            GROUP BY h.discarder_player_id";
        $stmt2 = $this->pdo->prepare($discardSql);
        $stmt2->execute([...$scopeParams, ...$discarderFilter['params']]);

        foreach ($stmt2->fetchAll() as $row) {
            $playerId = (int) $row['player_id'];
            if (!isset($agg[$playerId])) {
                continue; // discarder not in the filtered population (e.g. narrowed out by ?player_ids=)
            }
            $agg[$playerId]['discards'] = (int) $row['discards'];
            $agg[$playerId]['points_paid'] = -(int) $row['raw_delta'];
        }

        $totalDiscards = array_sum(array_column($agg, 'discards'));
        $totalHands = array_sum(array_column($agg, 'hands'));
        $tableAvg = $totalHands > 0 ? $totalDiscards / $totalHands : null;

        $players = $this->playerPayloads(array_keys($agg));

        $rows = [];
        foreach ($agg as $pid => $a) {
            $rate = $a['hands'] > 0 ? $a['discards'] / $a['hands'] : null;
            $rows[] = [
                'player' => $players[$pid] ?? null,
                'hands' => $a['hands'],
                'discards' => $a['discards'],
                'points_paid' => $a['points_paid'],
                'discard_rate' => $rate,
                'vs_table_avg' => ($rate !== null && $tableAvg !== null) ? $rate - $tableAvg : null,
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $rateCompare = ($b['discard_rate'] ?? -1) <=> ($a['discard_rate'] ?? -1);
            if ($rateCompare !== 0) {
                return $rateCompare;
            }
            $discardCompare = $b['discards'] <=> $a['discards'];
            return $discardCompare !== 0 ? $discardCompare : $a['player']['id'] <=> $b['player']['id'];
        });

        return ['table_avg_discard_rate' => $tableAvg, 'players' => $rows];
    }

    /**
     * Self-pick vs discard as a share of each player's wins, plus a
     * table-wide draw rate and 包 (bao) incidents (docs/06-history-
     * reports.md #9). Bao is kept split by win type rather than blended:
     * a discard bao always names the discarder as liable (rule 16), while
     * a self-pick bao names a *different* player already on the hook
     * (rule 5b) — the more interesting of the two. One row per player
     * seated on at least one hand in scope; `?player_ids=` narrows the
     * population itself (same convention as seats()/feeders()), and each
     * bao role (liable vs. won) is narrowed independently rather than as a
     * matched pair, since both are single-player counts, not a transfer.
     *
     * @param array<string,mixed> $filters
     * @return array{table_draw_rate: float|null, players: list<array<string,mixed>>}
     */
    public function winTypes(array $filters): array
    {
        [$scopeSql, $scopeParams] = $this->scopeCte($filters);
        $playerFilter = $this->playerIdsFilter($filters, 'gs.player_id');

        // Population + denominator: same "hands" definition leaderboard()
        // and feeders() use.
        $handsSql = "{$scopeSql}
            SELECT gs.player_id, COUNT(hs.hand_id) AS hands
            FROM game_seats gs
            JOIN scoped_games sg ON sg.id = gs.game_id
            JOIN hands h ON h.game_id = gs.game_id
            JOIN hand_scores hs ON hs.hand_id = h.id AND hs.player_id = gs.player_id
            {$playerFilter['sql']}
            GROUP BY gs.player_id";
        $stmt = $this->pdo->prepare($handsSql);
        $stmt->execute([...$scopeParams, ...$playerFilter['params']]);

        $agg = [];
        foreach ($stmt->fetchAll() as $row) {
            $agg[(int) $row['player_id']] = [
                'hands' => (int) $row['hands'],
                'self_pick_wins' => 0,
                'discard_wins' => 0,
                'discard_bao_liable' => 0,
                'discard_bao_won' => 0,
                'self_pick_bao_liable' => 0,
                'self_pick_bao_won' => 0,
            ];
        }

        // Table-wide draw rate: scope filters only, deliberately unaffected
        // by ?player_ids — a draw belongs to the whole table.
        $totalsSql = "{$scopeSql}
            SELECT COUNT(*) AS hands, SUM(CASE WHEN h.outcome = 'draw' THEN 1 ELSE 0 END) AS draws
            FROM hands h JOIN scoped_games sg ON sg.id = h.game_id";
        $totalsStmt = $this->pdo->prepare($totalsSql);
        $totalsStmt->execute($scopeParams);
        $totals = $totalsStmt->fetch();
        $totalHands = (int) $totals['hands'];
        $tableDrawRate = $totalHands > 0 ? (int) $totals['draws'] / $totalHands : null;

        if ($agg === []) {
            return ['table_draw_rate' => $tableDrawRate, 'players' => []];
        }

        $winSql = "{$scopeSql}
            SELECT h.winner_player_id AS player_id, h.win_type, COUNT(*) AS n
            FROM hands h JOIN scoped_games sg ON sg.id = h.game_id
            WHERE h.outcome = 'win'
            GROUP BY h.winner_player_id, h.win_type";
        $winStmt = $this->pdo->prepare($winSql);
        $winStmt->execute($scopeParams);
        foreach ($winStmt->fetchAll() as $row) {
            $pid = (int) $row['player_id'];
            if (!isset($agg[$pid])) {
                continue; // winner not in the filtered population
            }
            $key = $row['win_type'] === 'self_pick' ? 'self_pick_wins' : 'discard_wins';
            $agg[$pid][$key] = (int) $row['n'];
        }

        foreach (['discard' => 'discard_bao', 'self_pick' => 'self_pick_bao'] as $winType => $prefix) {
            $baoSql = "{$scopeSql}
                SELECT h.liable_player_id AS liable_id, h.winner_player_id AS winner_id, COUNT(*) AS n
                FROM hands h JOIN scoped_games sg ON sg.id = h.game_id
                WHERE h.outcome = 'win' AND h.win_type = ? AND h.liable_player_id IS NOT NULL
                GROUP BY h.liable_player_id, h.winner_player_id";
            $baoStmt = $this->pdo->prepare($baoSql);
            $baoStmt->execute([...$scopeParams, $winType]);
            foreach ($baoStmt->fetchAll() as $row) {
                $liableId = (int) $row['liable_id'];
                $winnerId = (int) $row['winner_id'];
                $n = (int) $row['n'];
                if (isset($agg[$liableId])) {
                    $agg[$liableId]["{$prefix}_liable"] += $n;
                }
                if (isset($agg[$winnerId])) {
                    $agg[$winnerId]["{$prefix}_won"] += $n;
                }
            }
        }

        $players = $this->playerPayloads(array_keys($agg));

        $rows = [];
        foreach ($agg as $pid => $a) {
            $wins = $a['self_pick_wins'] + $a['discard_wins'];
            $rows[] = [
                'player' => $players[$pid] ?? null,
                'hands' => $a['hands'],
                'wins' => $wins,
                'self_pick_wins' => $a['self_pick_wins'],
                'discard_wins' => $a['discard_wins'],
                'self_pick_win_share' => $wins > 0 ? $a['self_pick_wins'] / $wins : null,
                'discard_bao' => ['liable' => $a['discard_bao_liable'], 'won' => $a['discard_bao_won']],
                'self_pick_bao' => ['liable' => $a['self_pick_bao_liable'], 'won' => $a['self_pick_bao_won']],
            ];
        }

        usort($rows, static function (array $a, array $b): int {
            $shareCompare = ($b['self_pick_win_share'] ?? -1) <=> ($a['self_pick_win_share'] ?? -1);
            if ($shareCompare !== 0) {
                return $shareCompare;
            }
            $winsCompare = $b['wins'] <=> $a['wins'];
            return $winsCompare !== 0 ? $winsCompare : $a['player']['id'] <=> $b['player']['id'];
        });

        return ['table_draw_rate' => $tableDrawRate, 'players' => $rows];
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
