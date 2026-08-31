<?php

declare(strict_types=1);

// Stub. Grows into the full replay-based integrity checker from
// docs/02-scoring-engine.md § Replay once there is a scoring engine and
// games to replay (Phase 5).

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
Db::connect($config);

echo "verify: no checks implemented yet (stub).\n";
