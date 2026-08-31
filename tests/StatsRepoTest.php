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
 * docs/PLAN.md Phase 8's reconciliation test (docs/06-history-reports.md §
 * Implementation notes): "the sum of all players' net points over any range
 * is exactly 0." Plays a small scripted game through the real GameService
 * (win / draw / penalty / self-pick+bao, so every outcome shape feeds
 * hand_scores), then checks Repo\StatsRepo::leaderboard's net_points column
 * both reconciles to zero and ties out against a manual
 * `SUM(points_delta)` query — the same tie-out the human "Done when" check
 * in docs/PLAN.md Phase 8 asks for.
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
