<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\GameState;
use App\Repo\Db;
use App\Repo\GameRepo;
use App\Repo\PlayerRepo;
use App\Service\ConflictException;
use App\Service\GameService;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Wires Domain\Scoring / Domain\GameState to the database through
 * GameService — docs/PLAN.md Phase 5's integration test. Plays a scripted
 * 16+ hand game end to end at N=4, N=3, and N=2 (the N=2 run at a
 * non-default seat pair, East+South, so a hardcoded 4-chair assumption
 * cannot pass — see the owner's worked example in
 * docs/02-scoring-engine.md § two players at East and South).
 *
 * Each scenario asserts: final totals sum to zero after every hand, the
 * game auto-completes at exactly 4N hands, a second game cannot be started
 * while one is in progress (409/ConflictException), undo reopens a
 * completed game exactly one hand shorter, and bin/verify.php finds no
 * drift between the stored hand state and a from-scratch replay of the
 * game this test just played.
 *
 * Requires a reachable local database (config/config.php, the same one
 * `bun run serve:api` uses) — skips itself if that database is unreachable.
 */
final class GamesIntegrationTest extends TestCase
{
    private static PDO $pdo;
    private static GameService $service;
    private static GameRepo $games;
    private static PlayerRepo $players;

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
    }

    protected function setUp(): void
    {
        // A stray in_progress game left over from an interrupted run would
        // 409 every test in this class — clear it defensively.
        $currentId = self::$games->findCurrentId();
        if ($currentId !== null) {
            self::$games->hardDelete($currentId);
        }
    }

    public function testFourPlayerGame(): void
    {
        $this->playScriptedGame(4, [0, 1, 2, 3]);
    }

    public function testThreePlayerGame(): void
    {
        $this->playScriptedGame(3, [0, 1, 2]);
    }

    public function testTwoPlayerGameAtEastSouthNonDefaultSeatPair(): void
    {
        // The default 2-player fill is East+West (04-frontend.md); East+South
        // is the owner's worked example where the wind jumps East -> North
        // because both empty chairs sit on the same side. A test that only
        // ever exercised East+West could pass with a hardcoded "4" hiding
        // inside the wind arithmetic — this seat pair is the one that can't.
        $this->playScriptedGame(2, [0, 1]);
    }

    /** @param int[] $occupiedChairs */
    private function playScriptedGame(int $n, array $occupiedChairs): void
    {
        $rulesetId = $this->findHongKongStandardRulesetId();
        $playerIds = $this->makePlayers($n);

        $seats = [];
        foreach ($occupiedChairs as $i => $chair) {
            $seats[] = ['wind' => $chair, 'player_id' => $playerIds[$i]];
        }

        $created = self::$service->createGame([
            'ruleset_id' => $rulesetId,
            'name' => "Integration test N={$n}",
            'player_count' => $n,
            'min_faan' => 0,
            'max_faan' => 13,
            'seats' => $seats,
        ], null);

        $gameId = $created['game']['id'];
        $this->assertSame('in_progress', $created['game']['status']);
        $this->assertCount($n, $created['seats']);

        try {
            // Rule 14: a second game cannot start while this one is in progress.
            try {
                self::$service->createGame([
                    'ruleset_id' => $rulesetId,
                    'player_count' => $n,
                    'seats' => $seats,
                ], null);
                $this->fail('expected ConflictException — a game is already in progress');
            } catch (ConflictException) {
                // expected — this is the 409 the real API returns
            }

            $minHands = 4 * $n;
            $handNumber = 0;
            $result = $created;

            // Always a non-dealer win, so the deal rotates every hand — the
            // same construction GameStateTest's buildRotationHands() uses to
            // walk S6/S7/S10/S11/S13/S14, but through the real database now.
            while (!$result['state']['is_complete']) {
                $dealerChair = $result['state']['dealer_wind_index'];
                $winnerChair = GameState::nextDealer($dealerChair, $occupiedChairs);
                $discarderChair = GameState::nextDealer($winnerChair, $occupiedChairs);

                $result = self::$service->recordHand($gameId, [
                    'outcome' => 'win',
                    'winner_player_id' => $this->playerAtChair($seats, $winnerChair),
                    'faan' => 3,
                    'win_type' => 'discard',
                    'discarder_player_id' => $this->playerAtChair($seats, $discarderChair),
                ], null);

                $handNumber++;
                $this->assertSame(
                    0,
                    array_sum(array_column($result['seats'], 'total')),
                    "hand {$handNumber}: seat totals do not sum to zero"
                );
                $this->assertLessThanOrEqual($minHands, $handNumber, 'game did not complete at exactly 4N hands');
            }

            $this->assertSame($minHands, $handNumber, 'a fresh, always-rotating game should complete at exactly 4N hands (I3)');
            $this->assertSame('completed', $result['game']['status']);
            $this->assertCount($minHands, $result['hands']);

            $this->assertVerifyReportsNoDrift();

            // Undo the completing hand: reopens the game, exactly one hand shorter.
            $undone = self::$service->undoLastHand($gameId);
            $this->assertSame('in_progress', $undone['game']['status']);
            $this->assertFalse($undone['state']['is_complete']);
            $this->assertSame($handNumber, $undone['state']['next_hand_number']);
            $this->assertCount($minHands - 1, $undone['hands']);

            // Replay the same hand to re-complete it — proves undo and a
            // fresh replay of the remaining hands agree with each other.
            $dealerChair = $undone['state']['dealer_wind_index'];
            $winnerChair = GameState::nextDealer($dealerChair, $occupiedChairs);
            $discarderChair = GameState::nextDealer($winnerChair, $occupiedChairs);

            $redone = self::$service->recordHand($gameId, [
                'outcome' => 'win',
                'winner_player_id' => $this->playerAtChair($seats, $winnerChair),
                'faan' => 3,
                'win_type' => 'discard',
                'discarder_player_id' => $this->playerAtChair($seats, $discarderChair),
            ], null);
            $this->assertTrue($redone['state']['is_complete']);
            $this->assertSame('completed', $redone['game']['status']);
            $this->assertSame(
                0,
                array_sum(array_column($redone['seats'], 'total')),
                'seat totals do not sum to zero after redoing the completing hand'
            );

            $this->assertVerifyReportsNoDrift();
        } finally {
            self::$games->hardDelete($gameId);
            foreach ($playerIds as $playerId) {
                self::$players->softDelete($playerId);
            }
        }
    }

    /** Runs the actual bin/verify.php as a subprocess against the live database. */
    private function assertVerifyReportsNoDrift(): void
    {
        exec('php ' . escapeshellarg(__DIR__ . '/../bin/verify.php') . ' 2>&1', $output, $exitCode);
        $this->assertSame(0, $exitCode, "bin/verify.php reported a problem:\n" . implode("\n", $output));
    }

    /** @param list<array{wind:int, player_id:int}> $seats */
    private function playerAtChair(array $seats, int $chair): int
    {
        foreach ($seats as $seat) {
            if ($seat['wind'] === $chair) {
                return $seat['player_id'];
            }
        }

        throw new \RuntimeException("no seat at chair {$chair}");
    }

    /** @return list<int> */
    private function makePlayers(int $n): array
    {
        $ids = [];
        for ($i = 0; $i < $n; $i++) {
            $name = 'IT-' . $n . 'p-' . $i . '-' . bin2hex(random_bytes(4));
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
