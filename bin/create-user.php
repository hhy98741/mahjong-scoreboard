<?php

declare(strict_types=1);

// There is no public signup (docs-initial-build/03-api.md § Auth). Creates a login account
// interactively, with the password entered twice and never echoed.
//
//   php bin/create-user.php --username=ann --display-name="Ann" [--admin]

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

use App\Domain\PasswordPolicy;
use App\Repo\Db;
use App\Repo\UserRepo;

function printUsageAndExit(): never
{
    fwrite(STDERR, "Usage: php bin/create-user.php --username=ann --display-name=\"Ann\" [--admin]\n");
    exit(1);
}

function readHiddenPassword(string $prompt): string
{
    echo $prompt;

    if (stripos(PHP_OS, 'WIN') === 0) {
        // No portable way to suppress echo on Windows without extra
        // extensions; this is a local/dev-machine fallback only.
        $password = fgets(STDIN);
    } else {
        system('stty -echo 2>/dev/null');
        $password = fgets(STDIN);
        system('stty echo 2>/dev/null');
        echo "\n";
    }

    return trim((string) $password);
}

$options = getopt('', ['username:', 'display-name:', 'admin']);

$username = $options['username'] ?? null;
$displayName = $options['display-name'] ?? null;
$isAdmin = array_key_exists('admin', $options);

if (!is_string($username) || trim($username) === '' || !is_string($displayName) || trim($displayName) === '') {
    printUsageAndExit();
}

$username = trim($username);
$displayName = trim($displayName);

$config = require __DIR__ . '/../config/config.php';
$pdo = Db::connect($config);
$users = new UserRepo($pdo);

if ($users->findByUsername($username) !== null) {
    fwrite(STDERR, "A user named '{$username}' already exists.\n");
    exit(1);
}

$password = readHiddenPassword('Password: ');
$confirm = readHiddenPassword('Confirm password: ');

if (!PasswordPolicy::isValid($password)) {
    fwrite(STDERR, PasswordPolicy::describeViolations($password) . "\n");
    exit(1);
}

if ($password !== $confirm) {
    fwrite(STDERR, "Passwords do not match.\n");
    exit(1);
}

$id = $users->create($username, password_hash($password, PASSWORD_DEFAULT), $displayName, $isAdmin);

echo "Created user '{$username}' (id={$id}" . ($isAdmin ? ', admin' : '') . ").\n";
