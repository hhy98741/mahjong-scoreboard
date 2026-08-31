<?php

declare(strict_types=1);

// Replays every game and checks the integrity rules from
// docs-initial-build/01-data-model.md § Integrity rules (rules 1-15, plus 16) against a
// full GameState replay - the same replay GameService uses to answer every
// game-state request, so this is the authority "no drift" between stored
// hand state and a from-scratch replay actually means.
//
// Rules 9 and 10 are write-time operation constraints (hands may only be
// added to an in_progress game; only the last hand may ever be deleted) that
// the schema/GameService enforce at write time but that leave no separate
// trace to re-check afterwards, beyond what the replay-completion check
// below already covers. They are not re-checked here as standalone rules.

spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = substr($class, strlen('App\\'));
    $path = __DIR__ . '/../app/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Domain\DomainException;
use App\Domain\GameState;
use App\Repo\Db;

$config = require __DIR__ . '/../config/config.php';
$pdo = Db::connect($config);

/** @var list<string> $errors */
$errors = [];
$fail = static function (string $message) use (&$errors): void {
    $errors[] = $message;
};

/**
 * @param callable(string):void $fail
 */
function verifyRulesets(PDO $pdo, callable $fail): void
{
    foreach ($pdo->query('SELECT id, table_max_faan FROM rulesets')->fetchAll() as $ruleset) {
        $id = (int) $ruleset['id'];
        $tableMaxFaan = (int) $ruleset['table_max_faan'];

        if ($tableMaxFaan < 0 || $tableMaxFaan > 13) {
            $fail("ruleset {$id}: table_max_faan {$tableMaxFaan} out of range 0-13 (rule 11)");
        }

        $stmt = $pdo->prepare('SELECT faan FROM ruleset_points WHERE ruleset_id = ? ORDER BY faan');
        $stmt->execute([$id]);
        $faans = array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
        if ($faans !== range(0, $tableMaxFaan)) {
            $fail("ruleset {$id}: ruleset_points does not cover exactly faan 0..{$tableMaxFaan} with no gaps (rule 11)");
        }
    }
}

/**
 * @param callable(string):void $fail
 */
function verifyPlayers(PDO $pdo, callable $fail): void
{
    foreach ($pdo->query('SELECT id, color FROM players')->fetchAll() as $player) {
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', (string) $player['color'])) {
            $fail("player {$player['id']}: color '{$player['color']}' is not a #rrggbb hex value (rule 15)");
        }
    }
}

/**
 * @param callable(string):void $fail
 */
function verifyGame(PDO $pdo, int $gameId, callable $fail): void
{
    $gameStmt = $pdo->prepare(
        'SELECT status, player_count, min_faan, max_faan, ruleset_snapshot FROM games WHERE id = ?'
    );
    $gameStmt->execute([$gameId]);
    $game = $gameStmt->fetch();
    if ($game === false) {
        return;
    }

    $playerCount = (int) $game['player_count'];
    $minFaan = (int) $game['min_faan'];
    $maxFaan = (int) $game['max_faan'];
    $snapshot = json_decode((string) $game['ruleset_snapshot'], true);
    $tableMaxFaan = (int) ($snapshot['table_max_faan'] ?? 0);

    if ($minFaan < 0 || $minFaan > $maxFaan || $maxFaan > $tableMaxFaan) {
        $fail("game {$gameId}: min_faan/max_faan {$minFaan}/{$maxFaan} outside 0..{$tableMaxFaan} (rule 12)");
    }

    $seatStmt = $pdo->prepare('SELECT wind_index, player_id FROM game_seats WHERE game_id = ? ORDER BY wind_index');
    $seatStmt->execute([$gameId]);
    $seats = []; // wind_index => player_id
    foreach ($seatStmt->fetchAll() as $row) {
        $seats[(int) $row['wind_index']] = (int) $row['player_id'];
    }

    if ($playerCount < 2 || $playerCount > 4 || count($seats) !== $playerCount) {
        $fail("game {$gameId}: expected {$playerCount} game_seats rows, found " . count($seats) . ' (rule 1)');
    }
    if (count(array_unique($seats)) !== count($seats)) {
        $fail("game {$gameId}: game_seats has a duplicate player (rule 1)");
    }
    if (!array_key_exists(0, $seats)) {
        $fail("game {$gameId}: East (wind_index 0) is not occupied (rule 1b)");
    }

    $seatedIds = array_values($seats);

    $handStmt = $pdo->prepare(
        'SELECT id, hand_number, round_wind, dealer_wind_index, outcome, winner_player_id, faan, win_type,
                discarder_player_id, liable_player_id, base_points, offender_player_id, penalty_per_player
         FROM hands WHERE game_id = ? ORDER BY hand_number ASC'
    );
    $handStmt->execute([$gameId]);
    $hands = $handStmt->fetchAll();

    // Rule 2: hand_number contiguous from 1.
    foreach ($hands as $i => $hand) {
        if ((int) $hand['hand_number'] !== $i + 1) {
            $fail("game {$gameId}: hand_number is not contiguous from 1 (rule 2)");
            break;
        }
    }

    try {
        $state = GameState::initial($seats);
    } catch (DomainException $e) {
        $fail("game {$gameId}: seats invalid - {$e->getMessage()} (rule 1/1b)");
        return;
    }

    $seenComplete = false;

    foreach ($hands as $hand) {
        $handNumber = (int) $hand['hand_number'];

        if ($seenComplete) {
            $fail("game {$gameId} hand {$handNumber}: recorded after the game had already completed (rule 9)");
            continue;
        }

        // I4 / "no drift": the stored round_wind/dealer_wind_index (written
        // before this hand) must match what a from-scratch replay computes.
        if ((int) $hand['round_wind'] !== $state->roundWind || (int) $hand['dealer_wind_index'] !== $state->dealerWindIndex) {
            $fail(sprintf(
                'game %d hand %d: stored round_wind/dealer_wind_index (%d/%d) does not match replay (%d/%d) - drift',
                $gameId,
                $handNumber,
                (int) $hand['round_wind'],
                (int) $hand['dealer_wind_index'],
                $state->roundWind,
                $state->dealerWindIndex
            ));
        }
        if (!array_key_exists($state->dealerWindIndex, $seats)) {
            $fail("game {$gameId} hand {$handNumber}: dealer_wind_index {$state->dealerWindIndex} is not an occupied chair (rule 1c)");
        }

        $outcome = (string) $hand['outcome'];
        $winnerPlayerId = $hand['winner_player_id'] !== null ? (int) $hand['winner_player_id'] : null;
        $discarderPlayerId = $hand['discarder_player_id'] !== null ? (int) $hand['discarder_player_id'] : null;
        $liablePlayerId = $hand['liable_player_id'] !== null ? (int) $hand['liable_player_id'] : null;
        $offenderPlayerId = $hand['offender_player_id'] !== null ? (int) $hand['offender_player_id'] : null;
        $faan = $hand['faan'] !== null ? (int) $hand['faan'] : null;

        foreach (['winner' => $winnerPlayerId, 'discarder' => $discarderPlayerId, 'liable' => $liablePlayerId, 'offender' => $offenderPlayerId] as $role => $playerId) {
            if ($playerId !== null && !in_array($playerId, $seatedIds, true)) {
                $fail("game {$gameId} hand {$handNumber}: {$role}_player_id {$playerId} is not seated in this game (rule 3)");
            }
        }
        if ($discarderPlayerId !== null && $discarderPlayerId === $winnerPlayerId) {
            $fail("game {$gameId} hand {$handNumber}: discarder_player_id equals winner_player_id (rule 4)");
        }
        if ($liablePlayerId !== null && $liablePlayerId === $winnerPlayerId) {
            $fail("game {$gameId} hand {$handNumber}: liable_player_id equals winner_player_id (rule 5b)");
        }

        if ($outcome === 'win') {
            if ($winnerPlayerId === null || $faan === null || $hand['win_type'] === null || $hand['base_points'] === null) {
                $fail("game {$gameId} hand {$handNumber}: win hand missing winner_player_id/faan/win_type/base_points (rule 5)");
            }
            if ($hand['win_type'] === 'discard' && $discarderPlayerId === null) {
                $fail("game {$gameId} hand {$handNumber}: discard win has no discarder_player_id (rule 5)");
            }
            if ($hand['win_type'] === 'self_pick' && $discarderPlayerId !== null) {
                $fail("game {$gameId} hand {$handNumber}: self_pick win has a discarder_player_id set (rule 5)");
            }
            if ($hand['win_type'] === 'discard' && $liablePlayerId !== null && $liablePlayerId !== $discarderPlayerId) {
                $fail("game {$gameId} hand {$handNumber}: discard win's liable_player_id is not the discarder (rule 16)");
            }
            if ($faan !== null && ($faan < $minFaan || $faan > $maxFaan)) {
                $fail("game {$gameId} hand {$handNumber}: faan {$faan} outside the game's band {$minFaan}..{$maxFaan} (rule 8)");
            }
        } elseif ($outcome === 'draw') {
            foreach (['winner_player_id', 'faan', 'win_type', 'discarder_player_id', 'liable_player_id', 'base_points', 'offender_player_id', 'penalty_per_player'] as $col) {
                if ($hand[$col] !== null) {
                    $fail("game {$gameId} hand {$handNumber}: draw hand has a non-null {$col} (rule 6)");
                    break;
                }
            }
        } elseif ($outcome === 'penalty') {
            if ($offenderPlayerId === null || $hand['penalty_per_player'] === null) {
                $fail("game {$gameId} hand {$handNumber}: penalty hand missing offender_player_id/penalty_per_player (rule 7)");
            }
        }

        $scoreStmt = $pdo->prepare('SELECT player_id, points_delta FROM hand_scores WHERE hand_id = ?');
        $scoreStmt->execute([(int) $hand['id']]);
        $scores = [];
        foreach ($scoreStmt->fetchAll() as $row) {
            $scores[(int) $row['player_id']] = (int) $row['points_delta'];
        }

        if (count($scores) !== $playerCount) {
            $fail("game {$gameId} hand {$handNumber}: " . count($scores) . " hand_scores rows, expected {$playerCount} (rule 13)");
        }
        if (array_sum($scores) !== 0) {
            $fail("game {$gameId} hand {$handNumber}: hand_scores do not sum to zero (rule 13)");
        }
        if ($outcome === 'draw' && array_filter($scores, static fn (int $d): bool => $d !== 0) !== []) {
            $fail("game {$gameId} hand {$handNumber}: draw hand has a non-zero delta (rule 6)");
        }

        $state = $state->applyHand($outcome, $winnerPlayerId, $scores);
        if ($state->isComplete) {
            $seenComplete = true;
        }
    }

    if ($game['status'] === 'in_progress' && $state->isComplete) {
        $fail("game {$gameId}: replay says complete but status is still 'in_progress'");
    }
    if ($game['status'] === 'completed' && !$state->isComplete) {
        $fail("game {$gameId}: status is 'completed' but replay says not complete");
    }
}

verifyRulesets($pdo, $fail);
verifyPlayers($pdo, $fail);

// Rule 14: at most one in_progress game.
$inProgressCount = (int) $pdo->query("SELECT COUNT(*) FROM games WHERE status = 'in_progress'")->fetchColumn();
if ($inProgressCount > 1) {
    $fail("{$inProgressCount} games have status='in_progress'; at most one is allowed (rule 14)");
}

$gameIds = array_map(intval(...), $pdo->query('SELECT id FROM games ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
foreach ($gameIds as $gameId) {
    verifyGame($pdo, $gameId, $fail);
}

if ($errors === []) {
    echo 'verify: OK - ' . count($gameIds) . " game(s) checked, {$inProgressCount} in progress, no drift.\n";
    exit(0);
}

fwrite(STDERR, 'verify: FAILED - ' . count($errors) . " issue(s)\n");
foreach ($errors as $error) {
    fwrite(STDERR, " - {$error}\n");
}
exit(1);
