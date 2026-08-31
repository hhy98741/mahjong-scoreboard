<?php

declare(strict_types=1);

// Seeds the "Hong Kong Standard" ruleset. Idempotent: only inserts if no
// ruleset with that name exists yet, and never touches an existing one - the
// owner is expected to edit these values to match their house rules.

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

use App\Repo\Db;

$config = require __DIR__ . '/../config/config.php';
$pdo = Db::connect($config);

const RULESET_NAME = 'Hong Kong Standard';

// docs/01-data-model.md § Seed data - banded doubling table, reference PDF p.10.
const POINTS = [
    0 => 1, 1 => 2, 2 => 4, 3 => 8, 4 => 16, 5 => 16, 6 => 16,
    7 => 32, 8 => 32, 9 => 32, 10 => 64, 11 => 64, 12 => 64, 13 => 64,
];

$existing = $pdo->prepare('SELECT id FROM rulesets WHERE name = ?');
$existing->execute([RULESET_NAME]);
$existingId = $existing->fetchColumn();

if ($existingId !== false) {
    echo "Ruleset '" . RULESET_NAME . "' already exists (id={$existingId}); leaving it untouched.\n";
    exit(0);
}

try {
    $pdo->beginTransaction();

    $pdo->prepare(
        'INSERT INTO rulesets (name, table_max_faan, payment_rule, penalty_default, is_default)
         VALUES (?, 13, \'hk_standard\', 128, 1)'
    )->execute([RULESET_NAME]);

    $rulesetId = (int) $pdo->lastInsertId();

    $insertPoint = $pdo->prepare(
        'INSERT INTO ruleset_points (ruleset_id, faan, base_points) VALUES (?, ?, ?)'
    );
    foreach (POINTS as $faan => $basePoints) {
        $insertPoint->execute([$rulesetId, $faan, $basePoints]);
    }

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    fwrite(STDERR, "Seed failed: {$e->getMessage()}\n");
    exit(1);
}

echo "Seeded ruleset '" . RULESET_NAME . "' (id={$rulesetId}) with " . count(POINTS) . " points rows.\n";
