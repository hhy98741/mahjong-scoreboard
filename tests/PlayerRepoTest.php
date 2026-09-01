<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\DomainException;
use App\Repo\Db;
use App\Repo\PlayerRepo;
use App\Repo\UserRepo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Covers PlayerRepo::linkUser, the D29 player<->login bridge. Requires a
 * reachable local database - skips itself if unreachable, same as
 * GamesIntegrationTest.
 */
final class PlayerRepoTest extends TestCase
{
    private static PDO $pdo;
    private static PlayerRepo $players;
    private static UserRepo $users;

    public static function setUpBeforeClass(): void
    {
        $config = require __DIR__ . '/../config/config.php';

        try {
            self::$pdo = Db::connect($config);
            self::$pdo->query('SELECT 1');
        } catch (\Throwable $e) {
            self::markTestSkipped('No local database reachable: ' . $e->getMessage());
        }

        self::$players = new PlayerRepo(self::$pdo);
        self::$users = new UserRepo(self::$pdo);
    }

    private function uniqueSuffix(): string
    {
        return bin2hex(random_bytes(4));
    }

    private function makePlayer(): array
    {
        return self::$players->create('IT-player-' . $this->uniqueSuffix(), null);
    }

    private function makeUser(): int
    {
        return self::$users->create('IT-user-' . $this->uniqueSuffix(), password_hash('secret', PASSWORD_DEFAULT), 'Link Test', false);
    }

    public function testLinkAndUnlinkUser(): void
    {
        $player = $this->makePlayer();
        $userId = $this->makeUser();

        $linked = self::$players->linkUser($player['id'], $userId);
        self::assertSame($userId, $linked['user_id']);

        $unlinked = self::$players->linkUser($player['id'], null);
        self::assertNull($unlinked['user_id']);
    }

    public function testLinkingAlreadyLinkedUserThrows(): void
    {
        $playerA = $this->makePlayer();
        $playerB = $this->makePlayer();
        $userId = $this->makeUser();

        self::$players->linkUser($playerA['id'], $userId);

        $this->expectException(DomainException::class);
        self::$players->linkUser($playerB['id'], $userId);
    }
}
