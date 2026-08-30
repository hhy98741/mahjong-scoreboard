<?php

declare(strict_types=1);

namespace App\Repo;

use PDO;

final class Db
{
    private static ?PDO $instance = null;

    /** @param array<string, mixed> $config */
    public static function connect(array $config): PDO
    {
        if (self::$instance !== null) {
            return self::$instance;
        }

        $db = $config['db'];
        $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset=utf8mb4";

        self::$instance = new PDO($dsn, $db['user'], $db['pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);

        return self::$instance;
    }
}
