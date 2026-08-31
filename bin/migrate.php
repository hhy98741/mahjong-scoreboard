<?php

declare(strict_types=1);

// Applies migrations/*.sql in filename order, tracked in schema_migrations.
// Idempotent: files already recorded there are skipped.
//
// MariaDB DDL statements auto-commit individually, so a transaction around a
// migration file cannot make it fully atomic - a failure partway through a
// file can leave earlier CREATE TABLEs in place even though the migration is
// not recorded as applied. This wraps each file in a transaction anyway (it
// still protects any DML a migration might contain) and reports clearly on
// failure so the fix can be applied by hand.

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

/**
 * Strip `-- ...` line comments, then split on `;`. The schema has no string
 * literals containing `--` or `;`, so this simple approach is safe here.
 *
 * @return list<string>
 */
function splitStatements(string $sql): array
{
    $lines = explode("\n", $sql);
    $stripped = array_map(static function (string $line): string {
        $pos = strpos($line, '--');
        return $pos === false ? $line : substr($line, 0, $pos);
    }, $lines);

    $statements = array_map('trim', explode(';', implode("\n", $stripped)));

    return array_values(array_filter($statements, static fn (string $s): bool => $s !== ''));
}

$migrationsDir = __DIR__ . '/../migrations';
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$tableExists = (bool) $pdo->query(
    "SELECT COUNT(*) FROM information_schema.tables
     WHERE table_schema = DATABASE() AND table_name = 'schema_migrations'"
)->fetchColumn();

$applied = $tableExists
    ? $pdo->query('SELECT filename FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)
    : [];

$pending = array_values(array_filter(
    $files,
    static fn (string $f): bool => !in_array(basename($f), $applied, true)
));

if ($pending === []) {
    echo "No pending migrations.\n";
    exit(0);
}

foreach ($pending as $file) {
    $name = basename($file);
    echo "Applying {$name}...\n";

    $statements = splitStatements((string) file_get_contents($file));

    try {
        $pdo->beginTransaction();
        foreach ($statements as $statement) {
            $pdo->exec($statement);
        }
        $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (?)')->execute([$name]);
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, "Migration {$name} failed: {$e->getMessage()}\n");
        exit(1);
    }

    echo "Applied {$name}.\n";
}
