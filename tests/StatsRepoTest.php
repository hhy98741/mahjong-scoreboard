<?php

declare(strict_types=1);

namespace Tests;

use App\Repo\Db;
use App\Repo\GameRepo;
use App\Repo\PlayerRepo;
use App\Repo\StatsRepo;
use App\Service\GameService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * docs-initial-build/PLAN.md Phase 8's reconciliation test (docs-initial-build/06-history-reports.md §
 * Implementation notes): "the sum of all players' net points over any range
 * is exactly 0." Plays a small scripted game through the real GameService
 * (win / draw / penalty / self-pick+bao, so every outcome shape feeds
 * hand_scores), then checks Repo\StatsRepo::leaderboard's net_points column
 * both reconciles to zero and ties out against a manual
 * `SUM(points_delta)` query — the same tie-out the human "Done when" check
 * in docs-initial-build/PLAN.md Phase 8 asks for.
 *
 * Requires a reachable local database — skips itself if unreachable, same
 * as GamesIntegrationTest.
 */
final class StatsRepoTest extends TestCase
{
    private static PDO $pdo;
    private static GameService $service;
    private static GameRepo $games;
    private static PlayerRepo $players;
    private static StatsRepo $stats;

    public static function setUpBeforeClass(): void
    {
        $config = require __DIR__ . '/../config/config.php';

        try {
            self::$pdo = Db::connect($config);
            self::$pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('No local database reachable: ' . $e->getMessage());
        }

        self::$service = new GameService(self::$pdo);
        self::$games = new GameRepo(self::$pdo);
        self::$players = new PlayerRepo(self::$pdo);
        self::$stats = new StatsRepo(self::$pdo);
    }

    protected function setUp(): void
    {
        // A stray in_progress game left over from an interrupted run would
        // 409 this test's createGame() call — clear it defensively, as
        // GamesIntegrationTest does.
        $currentId = self::$games->findCurrentId();
        if ($currentId !== null) {
            self::$games->hardDelete($currentId);
        }
    }

    public function testLeaderboardReconcilesAndTiesOutAgainstManualSum(): void
    {
        $rulesetId = $this->findHongKongStandardRulesetId();
        $playerIds = $this->makePlayers(4);
        [$ann, $ben, $cal, $dee] = $playerIds;

        $seats = [
            ['wind' => 0, 'player_id' => $ann],
            ['wind' => 1, 'player_id' => $ben],
            ['wind' => 2, 'player_id' => $cal],
            ['wind' => 3, 'player_id' => $dee],
        ];

        $created = self::$service->createGame([
            'ruleset_id' => $rulesetId,
            'name' => 'StatsRepoTest game',
            'player_count' => 4,
            'min_faan' => 0,
            'max_faan' => 13,
            'seats' => $seats,
        ], null);
        $gameId = $created['game']['id'];

        try {
            // #1 dealer (Ann) wins by discard off Ben — dealer stays.
            self::$service->recordHand($gameId, [
                'outcome' => 'win',
                'winner_player_id' => $ann,
                'faan' => 3,
                'win_type' => 'discard',
                'discarder_player_id' => $ben,
            ], null);

            // #2 draw — everyone's delta is 0, dealer stays.
            self::$service->recordHand($gameId, ['outcome' => 'draw'], null);

            // #3 penalty — Cal offends, everyone else receives 128.
            self::$service->recordHand($gameId, [
                'outcome' => 'penalty',
                'offender_player_id' => $cal,
                'penalty_per_player' => 128,
            ], null);

            // #4 self-pick with bao — Dee wins, names Ben liable for everything.
            self::$service->recordHand($gameId, [
                'outcome' => 'win',
                'winner_player_id' => $dee,
                'faan' => 5,
                'win_type' => 'self_pick',
                'discarder_player_id' => null,
                'liable_player_id' => $ben,
            ], null);

            $result = self::$service->assemblePayload($gameId);
            $from = date('Y-m-d H:i:s', strtotime($result['game']['started_at']) - 60);
            $to = date('Y-m-d H:i:s', time() + 60);

            $filters = [
                'from' => $from,
                'to' => $to,
                'player_count' => 4,
                'player_ids' => $playerIds,
            ];
            $leaderboard = self::$stats->leaderboard($filters);

            $this->assertCount(4, $leaderboard, 'expected one leaderboard row per seated player');

            // Reconciliation: net points across the range sum to exactly zero.
            $sumNetPoints = array_sum(array_column($leaderboard, 'net_points'));
            $this->assertSame(0, $sumNetPoints, 'sum of net_points across the leaderboard must reconcile to zero');

            // Tie out each row against a manual SUM(points_delta) query.
            foreach ($leaderboard as $row) {
                $playerId = $row['player']['id'];
                $manualSum = $this->manualNetPoints($gameId, $playerId);
                $this->assertSame(
                    $manualSum,
                    $row['net_points'],
                    "leaderboard net_points for player {$playerId} does not match manual SUM(points_delta)"
                );
            }

            // Also confirm every row played exactly 4 hands of this one game.
            foreach ($leaderboard as $row) {
                $this->assertSame(1, $row['games']);
                $this->assertSame(4, $row['hands']);
            }

            // Hands won: Ann (dealer win, #1) and Dee (self-pick, #4) = 1 each.
            $byId = [];
            foreach ($leaderboard as $row) {
                $byId[$row['player']['id']] = $row;
            }
            $this->assertSame(1, $byId[$ann]['hands_won']);
            $this->assertSame(1, $byId[$dee]['hands_won']);
            $this->assertSame(0, $byId[$ben]['hands_won']);
            $this->assertSame(0, $byId[$cal]['hands_won']);

            // Games won: whoever has the highest total after 4 hands.
            $maxTotal = max(array_column($leaderboard, 'net_points'));
            foreach ($leaderboard as $row) {
                $expectedWon = $row['net_points'] === $maxTotal ? 1 : 0;
                $this->assertSame($expectedWon, $row['games_won'], "games_won mismatch for player {$row['player']['id']}");
                $this->assertSame($expectedWon, $row['game_win_rate'], "game_win_rate mismatch for player {$row['player']['id']}");
            }

            // Player-detail cumulative series ends at the same net_points figure.
            $annDetail = self::$stats->playerDetail($ann, $filters);
            $this->assertNotNull($annDetail);
            $lastPoint = end($annDetail['points_over_time']);
            $this->assertSame($byId[$ann]['net_points'], $lastPoint['cumulative']);
            $this->assertSame([['faan' => 3, 'count' => 1]], $annDetail['faan_histogram']);
        } finally {
            self::$games->hardDelete($gameId);
            foreach ($playerIds as $playerId) {
                self::$players->softDelete($playerId);
            }
        }
    }

    /**
     * docs-initial-build/06-history-reports.md § Implementation notes reconciliation
     * requirement, applied to the flow matrix specifically (docs-initial-build/03-api.md
     * § GET /api/stats/flow): "attributed flows must sum to zero" — for
     * every player in scope, points received via the matrix minus points
     * paid via the matrix must equal their leaderboard net_points, and the
     * grand total of (received - paid) across all players must be exactly
     * zero. A scripted game exercising all three attributing outcomes (a
     * discard win, a penalty, a self-pick+bao win) plus one draw, which must
     * contribute nothing to either the matrix or the reconciliation.
     */
    public function testFlowMatrixReconcilesToZero(): void
    {
        $rulesetId = $this->findHongKongStandardRulesetId();
        $playerIds = $this->makePlayers(4);
        [$ann, $ben, $cal, $dee] = $playerIds;

        $seats = [
            ['wind' => 0, 'player_id' => $ann],
            ['wind' => 1, 'player_id' => $ben],
            ['wind' => 2, 'player_id' => $cal],
            ['wind' => 3, 'player_id' => $dee],
        ];

        $created = self::$service->createGame([
            'ruleset_id' => $rulesetId,
            'name' => 'StatsRepoTest flow game',
            'player_count' => 4,
            'min_faan' => 0,
            'max_faan' => 13,
            'seats' => $seats,
        ], null);
        $gameId = $created['game']['id'];

        try {
            // #1 dealer (Ann) wins by discard off Ben — attributes Ben's loss to Ann.
            self::$service->recordHand($gameId, [
                'outcome' => 'win',
                'winner_player_id' => $ann,
                'faan' => 3,
                'win_type' => 'discard',
                'discarder_player_id' => $ben,
            ], null);

            // #2 draw — must not appear in the matrix at all.
            self::$service->recordHand($gameId, ['outcome' => 'draw'], null);

            // #3 penalty — Cal offends, attributes Cal's loss to Ann/Ben/Dee.
            self::$service->recordHand($gameId, [
                'outcome' => 'penalty',
                'offender_player_id' => $cal,
                'penalty_per_player' => 128,
            ], null);

            // #4 self-pick with bao — Dee wins, Ben named liable for the whole
            // hand, so Dee's gain attributes entirely to Ben, not Ann/Cal.
            self::$service->recordHand($gameId, [
                'outcome' => 'win',
                'winner_player_id' => $dee,
                'faan' => 5,
                'win_type' => 'self_pick',
                'discarder_player_id' => null,
                'liable_player_id' => $ben,
            ], null);

            $result = self::$service->assemblePayload($gameId);
            $from = date('Y-m-d H:i:s', strtotime($result['game']['started_at']) - 60);
            $to = date('Y-m-d H:i:s', time() + 60);

            $filters = [
                'from' => $from,
                'to' => $to,
                'player_count' => 4,
                'player_ids' => $playerIds,
            ];

            $flow = self::$stats->flow($filters);
            $players = $flow['players'];
            $matrix = $flow['matrix'];

            $this->assertCount(4, $players, 'every seated player attributed at least one transfer');
            $this->assertCount(4, $matrix);
            foreach ($matrix as $row) {
                $this->assertCount(4, $row);
            }

            $idsInOrder = array_column($players, 'id');
            $this->assertSame($idsInOrder, array_values(array_unique($idsInOrder)), 'no duplicate players in the matrix');
            $sortedIds = $idsInOrder;
            sort($sortedIds);
            $this->assertSame($sortedIds, $idsInOrder, 'players must be ordered by id ascending');

            foreach ($idsInOrder as $i => $id) {
                $this->assertSame(0, $matrix[$i][$i], "diagonal must be zero for player {$id}");
            }

            $leaderboard = self::$stats->leaderboard($filters);
            $netPointsById = [];
            foreach ($leaderboard as $row) {
                $netPointsById[$row['player']['id']] = $row['net_points'];
            }

            // Reconciliation: for every player, received - paid via the
            // matrix equals their leaderboard net_points exactly.
            $grandTotal = 0;
            foreach ($idsInOrder as $i => $id) {
                $paid = array_sum($matrix[$i]);
                $received = 0;
                foreach ($matrix as $row) {
                    $received += $row[$i];
                }
                $net = $received - $paid;
                $this->assertSame(
                    $netPointsById[$id],
                    $net,
                    "flow-derived net for player {$id} does not reconcile against leaderboard net_points"
                );
                $grandTotal += $net;
            }

            // The grand total of attributed flows must sum to exactly zero.
            $this->assertSame(0, $grandTotal, 'attributed flows must sum to zero across all players');

            // Sanity check the concrete numbers for this scripted game
            // (docs-initial-build/02-scoring-engine.md Part 1, seeded points 3->8, 5->16,
            // N=4): hand #1 is plain case A (discard, no bao) — the
            // discarder Ben pays 2B=16, and Cal/Dee each pay the "others"
            // share B=8, all attributed to Ann. Hand #3's penalty adds 128
            // from Cal to each of the other three. Hand #4 is case D
            // (self-pick + bao) — only the liable player Ben pays
            // 2(N-1)B=96 to Dee; Ann and Cal pay nothing on that hand.
            $index = array_flip($idsInOrder);
            $this->assertSame(16, $matrix[$index[$ben]][$index[$ann]], 'Ben -> Ann discard-win transfer (discarder share)');
            $this->assertSame(136, $matrix[$index[$cal]][$index[$ann]], 'Cal -> Ann: 8 (discard "others" share) + 128 (penalty)');
            $this->assertSame(8, $matrix[$index[$dee]][$index[$ann]], 'Dee -> Ann discard-win transfer (others share)');
            $this->assertSame(128, $matrix[$index[$cal]][$index[$ben]], 'Cal -> Ben penalty transfer');
            $this->assertSame(128, $matrix[$index[$cal]][$index[$dee]], 'Cal -> Dee penalty transfer only — the bao win never touches Cal');
            $this->assertSame(96, $matrix[$index[$ben]][$index[$dee]], 'bao win attributes Dee\'s whole gain to liable player Ben');
            $this->assertSame(0, $matrix[$index[$ann]][$index[$dee]] ?? 0, 'bao win must not attribute anything to Ann');
        } finally {
            self::$games->hardDelete($gameId);
            foreach ($playerIds as $playerId) {
                self::$players->softDelete($playerId);
            }
        }
    }

    /**
     * docs-initial-build/06-history-reports.md #6 (seat luck), backing GET /api/stats/seats
     * (docs-initial-build/03-api.md § GET /api/stats/seats): group by the wind actually
     * held when the hand was played, not by chair, and break out dealer win
     * rate separately. Reuses the same four-hand script as the flow test
     * (win-by-dealer, draw, penalty, self-pick+bao by a non-dealer): per
     * docs-initial-build/02-scoring-engine.md lines 199 and 217, the deal stays with Ann
     * through all four hands (a win by the dealer keeps the deal; a draw
     * keeps the deal; a penalty is a dead hand and keeps the deal; a
     * non-dealer win only rotates the deal starting the *next* hand), so
     * every player holds exactly one wind for the whole game — Ann is
     * permanently East (and dealer throughout), Ben South, Cal West, Dee
     * North — making each wind bucket reconcile 1:1 against that single
     * player's leaderboard row.
     */
    public function testSeatsGroupsByWindHeldAndTiesOutAgainstLeaderboard(): void
    {
        $rulesetId = $this->findHongKongStandardRulesetId();
        $playerIds = $this->makePlayers(4);
        [$ann, $ben, $cal, $dee] = $playerIds;

        $seats = [
            ['wind' => 0, 'player_id' => $ann],
            ['wind' => 1, 'player_id' => $ben],
            ['wind' => 2, 'player_id' => $cal],
            ['wind' => 3, 'player_id' => $dee],
        ];

        $created = self::$service->createGame([
            'ruleset_id' => $rulesetId,
            'name' => 'StatsRepoTest seats game',
            'player_count' => 4,
            'min_faan' => 0,
            'max_faan' => 13,
            'seats' => $seats,
        ], null);
        $gameId = $created['game']['id'];

        try {
            // #1 dealer (Ann) wins by discard off Ben — dealer stays.
            self::$service->recordHand($gameId, [
                'outcome' => 'win',
                'winner_player_id' => $ann,
                'faan' => 3,
                'win_type' => 'discard',
                'discarder_player_id' => $ben,
            ], null);

            // #2 draw — dealer stays.
            self::$service->recordHand($gameId, ['outcome' => 'draw'], null);

            // #3 penalty — a dead hand, dealer stays.
            self::$service->recordHand($gameId, [
                'outcome' => 'penalty',
                'offender_player_id' => $cal,
                'penalty_per_player' => 128,
            ], null);

            // #4 self-pick with bao — Dee (non-dealer) wins; the deal would
            // rotate starting hand #5, but this hand was still played with
            // Ann as dealer.
            self::$service->recordHand($gameId, [
                'outcome' => 'win',
                'winner_player_id' => $dee,
                'faan' => 5,
                'win_type' => 'self_pick',
                'discarder_player_id' => null,
                'liable_player_id' => $ben,
            ], null);

            $result = self::$service->assemblePayload($gameId);
            $from = date('Y-m-d H:i:s', strtotime($result['game']['started_at']) - 60);
            $to = date('Y-m-d H:i:s', time() + 60);

            $filters = [
                'from' => $from,
                'to' => $to,
                'player_count' => 4,
                'player_ids' => $playerIds,
            ];

            $seatRows = self::$stats->seats($filters);
            $this->assertCount(4, $seatRows, 'all four winds occur when the dealer never rotates');

            $byWind = [];
            foreach ($seatRows as $row) {
                $byWind[$row['wind_index']] = $row;
            }
            $this->assertSame([0, 1, 2, 3], array_keys($byWind), 'wind_index ascending, 0..3 all present');
            $this->assertSame(['East', 'South', 'West', 'North'], array_column($seatRows, 'wind_name'));

            $leaderboard = self::$stats->leaderboard($filters);
            $byPlayer = [];
            foreach ($leaderboard as $row) {
                $byPlayer[$row['player']['id']] = $row;
            }

            // Ann = East (wind 0) throughout, Ben = South (1), Cal = West
            // (2), Dee = North (3) — each wind's totals must tie out exactly
            // against that one player's leaderboard row.
            $windToPlayer = [0 => $ann, 1 => $ben, 2 => $cal, 3 => $dee];
            foreach ($windToPlayer as $wind => $playerId) {
                $this->assertSame(4, $byWind[$wind]['hands'], "wind {$wind} should have all 4 hands");
                $this->assertSame(
                    $byPlayer[$playerId]['net_points'],
                    $byWind[$wind]['net_points'],
                    "wind {$wind} net_points must tie out against player {$playerId}'s leaderboard net_points"
                );
                $this->assertSame(
                    $byPlayer[$playerId]['hands_won'],
                    $byWind[$wind]['hands_won'],
                    "wind {$wind} hands_won must tie out against player {$playerId}'s leaderboard hands_won"
                );
                $this->assertSame($byWind[$wind]['hands_won'] / 4, $byWind[$wind]['win_rate']);
            }

            // Dealer win rate: wind_index 0 is always the dealer bucket. Ann
            // won hand #1 as dealer, so her win_rate is the dealer win rate.
            $this->assertSame(1, $byWind[0]['hands_won']);
            $this->assertSame(0.25, $byWind[0]['win_rate']);

            // Dee (North, wind 3) won hand #4 via self-pick.
            $this->assertSame(1, $byWind[3]['hands_won']);
            $this->assertSame(0.25, $byWind[3]['win_rate']);

            // Ben and Cal never won a hand in this script.
            $this->assertSame(0, $byWind[1]['hands_won']);
            $this->assertSame(0, $byWind[2]['hands_won']);

            // Grand reconciliation: net points summed across wind buckets
            // reconcile to zero, same as the leaderboard.
            $this->assertSame(0, array_sum(array_column($seatRows, 'net_points')));
        } finally {
            self::$games->hardDelete($gameId);
            foreach ($playerIds as $playerId) {
                self::$players->softDelete($playerId);
            }
        }
    }

    /**
     * docs-initial-build/06-history-reports.md #6: "at fewer than four players some winds
     * never occur, so group by the wind actually held rather than assuming
     * four buckets." A 2-player game at East+South only ever occupies wind
     * buckets 0 and 1 — this asserts GET /api/stats/seats never invents rows
     * for West/North just because they exist as enum values.
     */
    public function testSeatsOmitsWindsThatNeverOccurAtLowerPlayerCounts(): void
    {
        $rulesetId = $this->findHongKongStandardRulesetId();
        $playerIds = $this->makePlayers(2);
        [$ann, $ben] = $playerIds;

        $created = self::$service->createGame([
            'ruleset_id' => $rulesetId,
            'name' => 'StatsRepoTest seats 2p game',
            'player_count' => 2,
            'min_faan' => 0,
            'max_faan' => 13,
            'seats' => [
                ['wind' => 0, 'player_id' => $ann],
                ['wind' => 1, 'player_id' => $ben],
            ],
        ], null);
        $gameId = $created['game']['id'];

        try {
            // Dealer (Ann) wins, dealer stays — both hands played at the
            // same wind assignment.
            self::$service->recordHand($gameId, [
                'outcome' => 'win',
                'winner_player_id' => $ann,
                'faan' => 3,
                'win_type' => 'discard',
                'discarder_player_id' => $ben,
            ], null);
            self::$service->recordHand($gameId, ['outcome' => 'draw'], null);

            $result = self::$service->assemblePayload($gameId);
            $from = date('Y-m-d H:i:s', strtotime($result['game']['started_at']) - 60);
            $to = date('Y-m-d H:i:s', time() + 60);

            $seatRows = self::$stats->seats([
                'from' => $from,
                'to' => $to,
                'player_count' => 2,
                'player_ids' => $playerIds,
            ]);

            $winds = array_column($seatRows, 'wind_index');
            $this->assertSame([0, 1], $winds, 'only East and South occur at this 2-player seat pair');
            $this->assertSame(['East', 'South'], array_column($seatRows, 'wind_name'));
        } finally {
            self::$games->hardDelete($gameId);
            foreach ($playerIds as $playerId) {
                self::$players->softDelete($playerId);
            }
        }
    }

    /**
     * Regression test for a real bug found while building this report: `gs.wind_index`
     * and `h.dealer_wind_index` are both `tinyint unsigned` columns, so
     * `gs.wind_index - h.dealer_wind_index` underflows in MariaDB's unsigned
     * arithmetic whenever a chair's wind_index is less than the current
     * dealer's — e.g. chair 0 once the deal has rotated to chair 1 — and
     * `MOD(..., 4)` on that huge wrapped value threw "BIGINT UNSIGNED value
     * is out of range" instead of returning 3. StatsRepo::seats() now casts
     * both operands to SIGNED before subtracting. Every other test in this
     * file scripts the dealer staying at chair 0 the whole game (a dealer
     * win, a draw, or a penalty all keep the deal), so none of them could
     * have caught this — this one deliberately lets a non-dealer win rotate
     * the deal from chair 0 to chair 1 between hand #1 and hand #2.
     */
    public function testSeatsSurvivesDealerRotationPastChairZero(): void
    {
        $rulesetId = $this->findHongKongStandardRulesetId();
        $playerIds = $this->makePlayers(4);
        [$ann, $ben, $cal, $dee] = $playerIds;

        $created = self::$service->createGame([
            'ruleset_id' => $rulesetId,
            'name' => 'StatsRepoTest seats rotation game',
            'player_count' => 4,
            'min_faan' => 0,
            'max_faan' => 13,
            'seats' => [
                ['wind' => 0, 'player_id' => $ann],
                ['wind' => 1, 'player_id' => $ben],
                ['wind' => 2, 'player_id' => $cal],
                ['wind' => 3, 'player_id' => $dee],
            ],
        ], null);
        $gameId = $created['game']['id'];

        try {
            // #1 dealer_wind_index=0. Ben (chair 1, non-dealer) wins by
            // discard off Cal — a non-dealer win rotates the deal to chair 1
            // starting hand #2.
            self::$service->recordHand($gameId, [
                'outcome' => 'win',
                'winner_player_id' => $ben,
                'faan' => 3,
                'win_type' => 'discard',
                'discarder_player_id' => $cal,
            ], null);

            // #2 dealer_wind_index=1. Ann sits at chair 0, so her wind_held
            // is (0 - 1 + 4) % 4 = 3 — the exact subtraction that underflows
            // unsigned columns. The new dealer (Ben, chair 1) wins by
            // discard off Dee — dealer stays, irrelevant to this bug.
            self::$service->recordHand($gameId, [
                'outcome' => 'win',
                'winner_player_id' => $ben,
                'faan' => 3,
                'win_type' => 'discard',
                'discarder_player_id' => $dee,
            ], null);

            $result = self::$service->assemblePayload($gameId);
            $from = date('Y-m-d H:i:s', strtotime($result['game']['started_at']) - 60);
            $to = date('Y-m-d H:i:s', time() + 60);

            $seatRows = self::$stats->seats([
                'from' => $from,
                'to' => $to,
                'player_count' => 4,
                'player_ids' => $playerIds,
            ]);

            $byWind = [];
            foreach ($seatRows as $row) {
                $byWind[$row['wind_index']] = $row;
            }
            $this->assertSame([0, 1, 2, 3], array_keys($byWind));

            // Ruleset base_points for faan=3 is 8 (docs-initial-build/02-scoring-engine.md
            // Part 1, N=4 case A, no bao): discarder pays 2B=16, the other
            // two players pay B=8 each, all to the winner.
            //
            // Hand #1 (dealer chair 0): Ann=East(0,-8), Ben=South(1,+32),
            // Cal=West(2,-16), Dee=North(3,-8).
            // Hand #2 (dealer chair 1): Ben=East(0,+32), Cal=South(1,-8),
            // Dee=West(2,-16), Ann=North(3,-8).
            $this->assertSame(2, $byWind[0]['hands']);
            $this->assertSame(1, $byWind[0]['hands_won']); // Ben's hand #2 win
            $this->assertSame(0.5, $byWind[0]['win_rate']);
            $this->assertSame(24, $byWind[0]['net_points']); // Ann -8 + Ben +32

            $this->assertSame(2, $byWind[1]['hands']);
            $this->assertSame(1, $byWind[1]['hands_won']); // Ben's hand #1 win
            $this->assertSame(0.5, $byWind[1]['win_rate']);
            $this->assertSame(24, $byWind[1]['net_points']); // Ben +32 + Cal -8

            $this->assertSame(2, $byWind[2]['hands']);
            $this->assertSame(0, $byWind[2]['hands_won']);
            $this->assertSame(0, $byWind[2]['win_rate']);
            $this->assertSame(-32, $byWind[2]['net_points']); // Cal -16 + Dee -16

            $this->assertSame(2, $byWind[3]['hands']);
            $this->assertSame(0, $byWind[3]['hands_won']);
            $this->assertSame(0, $byWind[3]['win_rate']);
            $this->assertSame(-16, $byWind[3]['net_points']); // Dee -8 + Ann -8

            $this->assertSame(0, array_sum(array_column($seatRows, 'net_points')));
        } finally {
            self::$games->hardDelete($gameId);
            foreach ($playerIds as $playerId) {
                self::$players->softDelete($playerId);
            }
        }
    }

    /**
     * docs-initial-build/06-history-reports.md #7 (streaks and records), backing
     * GET /api/stats/records (docs-initial-build/03-api.md § GET /api/stats/records). A
     * single six-hand game engineered so every one of the eight keys has a
     * distinct, hand-calculable expected value:
     *
     *   #1 Ann (dealer) discard-wins off Ben,  faan=3 (B=8)  -> Ann +32
     *   #2 Ann (dealer) discard-wins off Cal,  faan=6 (B=16) -> Ann +64
     *   #3 Ben           discard-wins off Ann,  faan=2 (B=4)  -> Ben +16 (dealer stays
     *      Ann for this hand; rotates to Ben only starting #4)
     *   #4 Dee (self-pick, no bao), faan=4 (B=16) -> Dee +96 (2(N-1)B); dealer is Ben
     *   #5 Cal (new dealer) discard-wins off Dee, faan=8 (B=32) -> Cal +128
     *   #6 Cal (dealer) discard-wins off Ben,      faan=9 (B=32) -> Cal +128
     *
     * Running per-player cumulative totals after each hand:
     *   Ann:  32   96   88   56   24   -8
     *   Ben: -16  -32  -16  -48  -80 -144
     *   Cal:  -8  -40  -44  -76   52  180
     *   Dee:  -8  -24  -28   68    4  -28
     * (each column sums to zero, per the hand_scores invariant.)
     *
     * Cal ends the game on top (180) despite trailing Dee by 144 right after
     * hand #4 (Dee 68 vs Cal -76) — the single largest gap the eventual
     * winner ever faced, so that is the comeback record. Cal is also the
     * only player to reach a 4-hand drought (hands #1-4, before winning #5),
     * peaking at hand #4 — the same hand as the comeback, coincidentally.
     * Ann's back-to-back wins on #1-#2 (length 2, ending at #2) are the
     * longest win streak: Cal's own #5-#6 pair ties at length 2 but is
     * reached later, and the record keeps the first hand to reach a given
     * length, not the last. Ann/Cal/Dee's dealer tenure covers hands #1-#3
     * (2 defences, recorded at #3); Ben's and Cal's later tenures (#4 alone,
     * #5-#6 = 1 defence) never catch up. All six hands land on the same
     * calendar day, so the single game is also its own best/worst night:
     * Cal (+180) best, Ben (-144) worst.
     */
    public function testRecordsBoard(): void
    {
        $rulesetId = $this->findHongKongStandardRulesetId();
        $playerIds = $this->makePlayers(4);
        [$ann, $ben, $cal, $dee] = $playerIds;

        $created = self::$service->createGame([
            'ruleset_id' => $rulesetId,
            'name' => 'StatsRepoTest records game',
            'player_count' => 4,
            'min_faan' => 0,
            'max_faan' => 13,
            'seats' => [
                ['wind' => 0, 'player_id' => $ann],
                ['wind' => 1, 'player_id' => $ben],
                ['wind' => 2, 'player_id' => $cal],
                ['wind' => 3, 'player_id' => $dee],
            ],
        ], null);
        $gameId = $created['game']['id'];

        try {
            // GameService::buildPayload lists hands newest-first (the
            // scoreboard's own convention), so the just-recorded hand is
            // element 0, not the last one.
            $lastHandId = function (array $payload): int {
                return (int) $payload['hands'][0]['id'];
            };

            $r1 = self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $ann, 'faan' => 3,
                'win_type' => 'discard', 'discarder_player_id' => $ben,
            ], null);
            $hand2 = self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $ann, 'faan' => 6,
                'win_type' => 'discard', 'discarder_player_id' => $cal,
            ], null);
            $hand2Id = $lastHandId($hand2);

            $hand3 = self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $ben, 'faan' => 2,
                'win_type' => 'discard', 'discarder_player_id' => $ann,
            ], null);
            $hand3Id = $lastHandId($hand3);

            $hand4 = self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $dee, 'faan' => 4,
                'win_type' => 'self_pick', 'discarder_player_id' => null, 'liable_player_id' => null,
            ], null);
            $hand4Id = $lastHandId($hand4);

            $hand5 = self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $cal, 'faan' => 8,
                'win_type' => 'discard', 'discarder_player_id' => $dee,
            ], null);
            $hand5Id = $lastHandId($hand5);

            $hand6 = self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $cal, 'faan' => 9,
                'win_type' => 'discard', 'discarder_player_id' => $ben,
            ], null);

            $result = self::$service->assemblePayload($gameId);
            $from = date('Y-m-d H:i:s', strtotime($result['game']['started_at']) - 60);
            $to = date('Y-m-d H:i:s', time() + 60);

            $filters = ['from' => $from, 'to' => $to, 'player_count' => 4, 'player_ids' => $playerIds];
            $records = self::$stats->records($filters);

            $this->assertSame(['biggest_hand_points', 'biggest_hand_faan', 'longest_win_streak', 'longest_drought',
                'biggest_comeback', 'most_dealer_defences', 'best_night', 'worst_night'], array_keys($records));

            $this->assertSame($cal, $records['biggest_hand_points']['player']['id']);
            $this->assertSame(128, $records['biggest_hand_points']['points']);
            $this->assertSame($gameId, $records['biggest_hand_points']['game_id']);
            $this->assertSame($hand5Id, $records['biggest_hand_points']['hand_id'], 'the first 128-point hand (#5), not the tied #6');

            $this->assertSame($cal, $records['biggest_hand_faan']['player']['id']);
            $this->assertSame(9, $records['biggest_hand_faan']['faan']);

            $this->assertSame($ann, $records['longest_win_streak']['player']['id']);
            $this->assertSame(2, $records['longest_win_streak']['length']);
            $this->assertSame($hand2Id, $records['longest_win_streak']['hand_id']);

            $this->assertSame($cal, $records['longest_drought']['player']['id']);
            $this->assertSame(4, $records['longest_drought']['length']);
            $this->assertSame($hand4Id, $records['longest_drought']['hand_id']);

            $this->assertSame($cal, $records['biggest_comeback']['player']['id']);
            $this->assertSame(144, $records['biggest_comeback']['deficit']);
            $this->assertSame($hand4Id, $records['biggest_comeback']['hand_id']);

            $this->assertSame($ann, $records['most_dealer_defences']['player']['id']);
            $this->assertSame(2, $records['most_dealer_defences']['defences']);
            $this->assertSame($hand3Id, $records['most_dealer_defences']['hand_id']);

            $today = date('Y-m-d');
            $this->assertSame($cal, $records['best_night']['player']['id']);
            $this->assertSame(180, $records['best_night']['net_points']);
            $this->assertSame($today, $records['best_night']['date']);
            $this->assertSame([$gameId], $records['best_night']['game_ids']);

            $this->assertSame($ben, $records['worst_night']['player']['id']);
            $this->assertSame(-144, $records['worst_night']['net_points']);
            $this->assertSame([$gameId], $records['worst_night']['game_ids']);
        } finally {
            self::$games->hardDelete($gameId);
            foreach ($playerIds as $playerId) {
                self::$players->softDelete($playerId);
            }
        }
    }

    /**
     * GET /api/stats/records with no data in scope must return all eight
     * keys as null rather than omitting them or erroring.
     */
    public function testRecordsBoardIsAllNullWhenNothingInScope(): void
    {
        $records = self::$stats->records(['from' => '2000-01-01', 'to' => '2000-01-02', 'player_count' => 4]);

        $this->assertSame([
            'biggest_hand_points' => null,
            'biggest_hand_faan' => null,
            'longest_win_streak' => null,
            'longest_drought' => null,
            'biggest_comeback' => null,
            'most_dealer_defences' => null,
            'best_night' => null,
            'worst_night' => null,
        ], $records);
    }

    /**
     * docs-initial-build/06-history-reports.md #8 (feeder stats), backing GET
     * /api/stats/feeders (docs-initial-build/03-api.md § GET /api/stats/feeders): per
     * player, as the discarder, hands dealt into, points paid, and discard
     * rate vs. the table average. Two discard-win hands (Ben feeds Ann on
     * #1, Cal feeds Dee on #5) among five total, with a draw/penalty/bao
     * self-pick in between that must NOT count as a discard.
     */
    public function testFeederStatsCountsDiscardsAndComputesTableAverage(): void
    {
        $rulesetId = $this->findHongKongStandardRulesetId();
        $playerIds = $this->makePlayers(4);
        [$ann, $ben, $cal, $dee] = $playerIds;

        $created = self::$service->createGame([
            'ruleset_id' => $rulesetId,
            'name' => 'StatsRepoTest feeders game',
            'player_count' => 4,
            'min_faan' => 0,
            'max_faan' => 13,
            'seats' => [
                ['wind' => 0, 'player_id' => $ann],
                ['wind' => 1, 'player_id' => $ben],
                ['wind' => 2, 'player_id' => $cal],
                ['wind' => 3, 'player_id' => $dee],
            ],
        ], null);
        $gameId = $created['game']['id'];

        try {
            // #1 Ann (dealer) wins by discard off Ben — Ben's first feed.
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $ann, 'faan' => 3,
                'win_type' => 'discard', 'discarder_player_id' => $ben,
            ], null);

            // #2 draw — no discarder at all, must not count toward anyone.
            self::$service->recordHand($gameId, ['outcome' => 'draw'], null);

            // #3 penalty — Cal offends, but a penalty has no discarder either.
            self::$service->recordHand($gameId, [
                'outcome' => 'penalty', 'offender_player_id' => $cal, 'penalty_per_player' => 128,
            ], null);

            // #4 self-pick with bao — Ben is liable but never named as a
            // discarder (there isn't one on a self-pick); must not count.
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $dee, 'faan' => 5,
                'win_type' => 'self_pick', 'discarder_player_id' => null, 'liable_player_id' => $ben,
            ], null);

            // #5 Dee wins by discard off Cal — Cal's first feed.
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $dee, 'faan' => 3,
                'win_type' => 'discard', 'discarder_player_id' => $cal,
            ], null);

            $result = self::$service->assemblePayload($gameId);
            $from = date('Y-m-d H:i:s', strtotime($result['game']['started_at']) - 60);
            $to = date('Y-m-d H:i:s', time() + 60);
            $filters = ['from' => $from, 'to' => $to, 'player_count' => 4, 'player_ids' => $playerIds];

            $feeders = self::$stats->feeders($filters);

            $this->assertEqualsWithDelta(0.1, $feeders['table_avg_discard_rate'], 1e-9, '2 discards over 20 seated hands');
            $this->assertCount(4, $feeders['players']);

            $byId = [];
            foreach ($feeders['players'] as $row) {
                $byId[$row['player']['id']] = $row;
            }

            foreach ([$ann, $ben, $cal, $dee] as $pid) {
                $this->assertSame(5, $byId[$pid]['hands'], "player {$pid} was seated for all 5 hands");
            }

            $this->assertSame(1, $byId[$ben]['discards']);
            $this->assertSame(16, $byId[$ben]['points_paid'], 'discarder share of a faan-3 win, base_points 8 -> 2*8');
            $this->assertEqualsWithDelta(0.2, $byId[$ben]['discard_rate'], 1e-9);
            $this->assertEqualsWithDelta(0.1, $byId[$ben]['vs_table_avg'], 1e-9);

            $this->assertSame(1, $byId[$cal]['discards']);
            $this->assertSame(16, $byId[$cal]['points_paid']);
            $this->assertEqualsWithDelta(0.2, $byId[$cal]['discard_rate'], 1e-9);

            $this->assertSame(0, $byId[$ann]['discards']);
            $this->assertSame(0, $byId[$ann]['points_paid']);
            $this->assertEqualsWithDelta(0.0, $byId[$ann]['discard_rate'], 1e-9);
            $this->assertEqualsWithDelta(-0.1, $byId[$ann]['vs_table_avg'], 1e-9);

            $this->assertSame(0, $byId[$dee]['discards']);

            // Sort: discard_rate desc, ties by discards desc then player id
            // asc — Ben was created before Cal, Ann before Dee.
            $order = array_column($feeders['players'], 'player');
            $order = array_column($order, 'id');
            $this->assertSame([$ben, $cal, $ann, $dee], $order);

            // Reconciliation: points_paid for the discarder equals the
            // negation of their own points_delta on exactly the hands where
            // they are named h.discarder_player_id.
            $stmt = self::$pdo->prepare(
                "SELECT COALESCE(SUM(hs.points_delta), 0)
                 FROM hands h JOIN hand_scores hs ON hs.hand_id = h.id AND hs.player_id = h.discarder_player_id
                 WHERE h.game_id = ? AND h.discarder_player_id = ?"
            );
            foreach ([$ann, $ben, $cal, $dee] as $pid) {
                $stmt->execute([$gameId, $pid]);
                $this->assertSame((int) -$stmt->fetchColumn(), $byId[$pid]['points_paid'], "points_paid must reconcile for player {$pid}");
            }
        } finally {
            self::$games->hardDelete($gameId);
            foreach ($playerIds as $playerId) {
                self::$players->softDelete($playerId);
            }
        }
    }

    /**
     * `?player_ids=` narrows the population itself (same convention as
     * seats(), unlike records()): a discard by a player left out of the
     * filter must vanish from everyone's numbers, including the table
     * average — not just from the response's row list.
     */
    public function testFeederStatsPlayerIdsFilterNarrowsPopulation(): void
    {
        $rulesetId = $this->findHongKongStandardRulesetId();
        $playerIds = $this->makePlayers(4);
        [$ann, $ben, $cal, $dee] = $playerIds;

        $created = self::$service->createGame([
            'ruleset_id' => $rulesetId,
            'name' => 'StatsRepoTest feeders filter game',
            'player_count' => 4,
            'min_faan' => 0,
            'max_faan' => 13,
            'seats' => [
                ['wind' => 0, 'player_id' => $ann],
                ['wind' => 1, 'player_id' => $ben],
                ['wind' => 2, 'player_id' => $cal],
                ['wind' => 3, 'player_id' => $dee],
            ],
        ], null);
        $gameId = $created['game']['id'];

        try {
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $ann, 'faan' => 3,
                'win_type' => 'discard', 'discarder_player_id' => $ben,
            ], null);
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $ben, 'faan' => 3,
                'win_type' => 'discard', 'discarder_player_id' => $cal,
            ], null);

            $result = self::$service->assemblePayload($gameId);
            $from = date('Y-m-d H:i:s', strtotime($result['game']['started_at']) - 60);
            $to = date('Y-m-d H:i:s', time() + 60);

            // Only Ann and Ben in scope: Cal's feed of Ben must disappear
            // entirely, including from the table average's denominator.
            $filters = ['from' => $from, 'to' => $to, 'player_count' => 4, 'player_ids' => [$ann, $ben]];
            $feeders = self::$stats->feeders($filters);

            $this->assertCount(2, $feeders['players']);
            $byId = [];
            foreach ($feeders['players'] as $row) {
                $byId[$row['player']['id']] = $row;
            }
            $this->assertArrayNotHasKey($cal, $byId);
            $this->assertArrayNotHasKey($dee, $byId);
            $this->assertSame(2, $byId[$ann]['hands']);
            $this->assertSame(2, $byId[$ben]['hands']);
            $this->assertSame(1, $byId[$ben]['discards'], "Ben's own feed of Ann is still in scope");
            $this->assertSame(0, $byId[$ann]['discards'], "Cal's feed of Ben is out of scope, not attributed to Ann");
            $this->assertEqualsWithDelta(0.25, $feeders['table_avg_discard_rate'], 1e-9, '1 discard over 4 seated hands, Cal excluded entirely');
        } finally {
            self::$games->hardDelete($gameId);
            foreach ($playerIds as $playerId) {
                self::$players->softDelete($playerId);
            }
        }
    }

    /**
     * Empty scope must return an empty player list and a null table
     * average, not a division-by-zero error.
     */
    public function testFeederStatsEmptyScope(): void
    {
        $feeders = self::$stats->feeders(['from' => '2000-01-01', 'to' => '2000-01-02', 'player_count' => 4]);

        $this->assertSame(['table_avg_discard_rate' => null, 'players' => []], $feeders);
    }

    /**
     * docs-initial-build/06-history-reports.md #9: self-pick vs discard as a share of each
     * player's wins, a table-wide draw rate, and 包 (bao) kept split by win
     * type — a discard bao always names the discarder as liable (rule 16),
     * a self-pick bao names a different, already-on-the-hook player (rule
     * 5b).
     */
    public function testWinTypesSplitsByOutcomeAndBao(): void
    {
        $rulesetId = $this->findHongKongStandardRulesetId();
        $playerIds = $this->makePlayers(4);
        [$ann, $ben, $cal, $dee] = $playerIds;

        $created = self::$service->createGame([
            'ruleset_id' => $rulesetId,
            'name' => 'StatsRepoTest win-types game',
            'player_count' => 4,
            'min_faan' => 0,
            'max_faan' => 13,
            'seats' => [
                ['wind' => 0, 'player_id' => $ann],
                ['wind' => 1, 'player_id' => $ben],
                ['wind' => 2, 'player_id' => $cal],
                ['wind' => 3, 'player_id' => $dee],
            ],
        ], null);
        $gameId = $created['game']['id'];

        try {
            // #1 Ann self-picks, no bao.
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $ann, 'faan' => 3,
                'win_type' => 'self_pick', 'discarder_player_id' => null,
            ], null);

            // #2 Ben wins by discard off Cal, no bao.
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $ben, 'faan' => 3,
                'win_type' => 'discard', 'discarder_player_id' => $cal,
            ], null);

            // #3 draw — counts toward the table-wide draw rate only.
            self::$service->recordHand($gameId, ['outcome' => 'draw'], null);

            // #4 Cal wins by discard off Dee, WITH bao — Dee is liable
            // (rule 16: liable_player_id = discarder_player_id).
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $cal, 'faan' => 5,
                'win_type' => 'discard', 'discarder_player_id' => $dee, 'liable_player_id' => $dee,
            ], null);

            // #5 Dee self-picks, WITH bao naming Ben — Ben was already on
            // the hook before this hand, and is not the winner (rule 5b).
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $dee, 'faan' => 4,
                'win_type' => 'self_pick', 'discarder_player_id' => null, 'liable_player_id' => $ben,
            ], null);

            // #6 penalty — Ann offends; counts toward every seated player's
            // "hands" denominator but no player's win-type split.
            self::$service->recordHand($gameId, [
                'outcome' => 'penalty', 'offender_player_id' => $ann, 'penalty_per_player' => 64,
            ], null);

            $result = self::$service->assemblePayload($gameId);
            $from = date('Y-m-d H:i:s', strtotime($result['game']['started_at']) - 60);
            $to = date('Y-m-d H:i:s', time() + 60);
            $filters = ['from' => $from, 'to' => $to, 'player_count' => 4, 'player_ids' => $playerIds];

            $winTypes = self::$stats->winTypes($filters);

            $this->assertEqualsWithDelta(1 / 6, $winTypes['table_draw_rate'], 1e-9, '1 draw over 6 hands');
            $this->assertCount(4, $winTypes['players']);

            $byId = [];
            foreach ($winTypes['players'] as $row) {
                $byId[$row['player']['id']] = $row;
            }

            foreach ([$ann, $ben, $cal, $dee] as $pid) {
                $this->assertSame(6, $byId[$pid]['hands'], "player {$pid} was seated for all 6 hands");
                $this->assertSame(1, $byId[$pid]['wins'], "player {$pid} won exactly one hand");
            }

            $this->assertSame(1, $byId[$ann]['self_pick_wins']);
            $this->assertSame(0, $byId[$ann]['discard_wins']);
            $this->assertEqualsWithDelta(1.0, $byId[$ann]['self_pick_win_share'], 1e-9);
            $this->assertSame(['liable' => 0, 'won' => 0], $byId[$ann]['discard_bao']);
            $this->assertSame(['liable' => 0, 'won' => 0], $byId[$ann]['self_pick_bao']);

            $this->assertSame(0, $byId[$ben]['self_pick_wins']);
            $this->assertSame(1, $byId[$ben]['discard_wins']);
            $this->assertEqualsWithDelta(0.0, $byId[$ben]['self_pick_win_share'], 1e-9);
            $this->assertSame(['liable' => 0, 'won' => 0], $byId[$ben]['discard_bao']);
            $this->assertSame(['liable' => 1, 'won' => 0], $byId[$ben]['self_pick_bao'], 'Ben was named liable on #5 but did not win it');

            $this->assertSame(0, $byId[$cal]['self_pick_wins']);
            $this->assertSame(1, $byId[$cal]['discard_wins']);
            $this->assertSame(['liable' => 0, 'won' => 1], $byId[$cal]['discard_bao'], 'Cal won the discard-bao hand #4');
            $this->assertSame(['liable' => 0, 'won' => 0], $byId[$cal]['self_pick_bao']);

            $this->assertSame(1, $byId[$dee]['self_pick_wins']);
            $this->assertSame(0, $byId[$dee]['discard_wins']);
            $this->assertSame(['liable' => 1, 'won' => 0], $byId[$dee]['discard_bao'], 'Dee was the discarder/liable player on bao hand #4');
            $this->assertSame(['liable' => 0, 'won' => 1], $byId[$dee]['self_pick_bao'], 'Dee won the self-pick-bao hand #5');

            // Sort: self_pick_win_share desc (Ann/Dee tie at 1.0, Ben/Cal
            // tie at 0.0), ties broken by wins desc (all tied at 1), then
            // player id asc — Ann before Dee, Ben before Cal.
            $order = array_column(array_column($winTypes['players'], 'player'), 'id');
            $this->assertSame([$ann, $dee, $ben, $cal], $order);
        } finally {
            self::$games->hardDelete($gameId);
            foreach ($playerIds as $playerId) {
                self::$players->softDelete($playerId);
            }
        }
    }

    /**
     * `?player_ids=` narrows the population itself (same convention as
     * seats()/feeders()), but each bao role is narrowed independently
     * rather than as a matched payer/receiver pair the way flow() requires
     * both sides in filter: a player still in scope keeps their own liable
     * or won count even when the other side of that hand is filtered out.
     */
    public function testWinTypesPlayerIdsFilterNarrowsPopulationIndependently(): void
    {
        $rulesetId = $this->findHongKongStandardRulesetId();
        $playerIds = $this->makePlayers(4);
        [$ann, $ben, $cal, $dee] = $playerIds;

        $created = self::$service->createGame([
            'ruleset_id' => $rulesetId,
            'name' => 'StatsRepoTest win-types filter game',
            'player_count' => 4,
            'min_faan' => 0,
            'max_faan' => 13,
            'seats' => [
                ['wind' => 0, 'player_id' => $ann],
                ['wind' => 1, 'player_id' => $ben],
                ['wind' => 2, 'player_id' => $cal],
                ['wind' => 3, 'player_id' => $dee],
            ],
        ], null);
        $gameId = $created['game']['id'];

        try {
            // #1 Ann self-picks, no bao.
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $ann, 'faan' => 3,
                'win_type' => 'self_pick', 'discarder_player_id' => null,
            ], null);

            // #2 Ben wins by discard off Cal (out of filter), WITH bao.
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $ben, 'faan' => 3,
                'win_type' => 'discard', 'discarder_player_id' => $cal, 'liable_player_id' => $cal,
            ], null);

            // #3 Cal wins by discard off Dee — both out of filter, must not
            // appear in Ann's or Ben's rows at all.
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $cal, 'faan' => 2,
                'win_type' => 'discard', 'discarder_player_id' => $dee,
            ], null);

            // #4 Dee self-picks, WITH bao naming Ben — Ben (in filter)
            // should still be credited as liable even though the winner
            // Dee is out of filter.
            self::$service->recordHand($gameId, [
                'outcome' => 'win', 'winner_player_id' => $dee, 'faan' => 4,
                'win_type' => 'self_pick', 'discarder_player_id' => null, 'liable_player_id' => $ben,
            ], null);

            self::$service->recordHand($gameId, ['outcome' => 'draw'], null);

            $result = self::$service->assemblePayload($gameId);
            $from = date('Y-m-d H:i:s', strtotime($result['game']['started_at']) - 60);
            $to = date('Y-m-d H:i:s', time() + 60);

            $filters = ['from' => $from, 'to' => $to, 'player_count' => 4, 'player_ids' => [$ann, $ben]];
            $winTypes = self::$stats->winTypes($filters);

            $this->assertCount(2, $winTypes['players']);
            $byId = [];
            foreach ($winTypes['players'] as $row) {
                $byId[$row['player']['id']] = $row;
            }
            $this->assertArrayNotHasKey($cal, $byId);
            $this->assertArrayNotHasKey($dee, $byId);

            $this->assertSame(1, $byId[$ann]['self_pick_wins']);

            $this->assertSame(1, $byId[$ben]['discard_wins']);
            $this->assertSame(['liable' => 0, 'won' => 1], $byId[$ben]['discard_bao'], 'Ben won the bao hand #2 even though the liable discarder Cal is out of filter');
            $this->assertSame(['liable' => 1, 'won' => 0], $byId[$ben]['self_pick_bao'], 'Ben stays liable on hand #4 even though winner Dee is out of filter');

            // 1 draw over 5 hands, unaffected by the player_ids narrowing.
            $this->assertEqualsWithDelta(1 / 5, $winTypes['table_draw_rate'], 1e-9);
        } finally {
            self::$games->hardDelete($gameId);
            foreach ($playerIds as $playerId) {
                self::$players->softDelete($playerId);
            }
        }
    }

    /**
     * Empty scope must return an empty player list and a null draw rate,
     * not a division-by-zero error.
     */
    public function testWinTypesEmptyScope(): void
    {
        $winTypes = self::$stats->winTypes(['from' => '2000-01-01', 'to' => '2000-01-02', 'player_count' => 4]);

        $this->assertSame(['table_draw_rate' => null, 'players' => []], $winTypes);
    }

    private function manualNetPoints(int $gameId, int $playerId): int
    {
        $stmt = self::$pdo->prepare(
            'SELECT COALESCE(SUM(hs.points_delta), 0)
             FROM hand_scores hs JOIN hands h ON h.id = hs.hand_id
             WHERE h.game_id = ? AND hs.player_id = ?'
        );
        $stmt->execute([$gameId, $playerId]);

        return (int) $stmt->fetchColumn();
    }

    /** @return list<int> */
    private function makePlayers(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $name = 'ST-' . $n . 'p-' . $i . '-' . bin2hex(random_bytes(4));
            $ids[] = self::$players->create($name, null)['id'];
        }

        return $ids;
    }

    private function findHongKongStandardRulesetId(): int
    {
        $id = self::$pdo->query("SELECT id FROM rulesets WHERE name = 'Hong Kong Standard'")->fetchColumn();
        if ($id === false) {
            $this->markTestSkipped("Seed ruleset 'Hong Kong Standard' not found — run php bin/seed.php first.");
        }

        return (int) $id;
    }
}
