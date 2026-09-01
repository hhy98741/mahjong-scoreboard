<?php

declare(strict_types=1);

namespace Tests;

use App\Domain\DomainException;
use App\Repo\Db;
use App\Repo\UserRepo;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Covers the admin user-management surface added for D29 (create/update/
 * updatePassword/all, duplicate-username handling). Requires a reachable
 * local database - skips itself if unreachable, same as GamesIntegrationTest.
 */
final class UserRepoTest extends TestCase
{
    private static PDO $pdo;
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

        self::$users = new UserRepo(self::$pdo);
    }

    private function uniqueUsername(): string
    {
        return 'IT-user-' . bin2hex(random_bytes(4));
    }

    public function testCreateFindAndFindByUsername(): void
    {
        $username = $this->uniqueUsername();
        $id = self::$users->create($username, password_hash('secret', PASSWORD_DEFAULT), 'Integration Test', false);

        $byId = self::$users->find($id);
        $byUsername = self::$users->findByUsername($username);

        self::assertNotNull($byId);
        self::assertNotNull($byUsername);
        self::assertSame($username, $byId['username']);
        self::assertSame('Integration Test', $byId['display_name']);
        self::assertFalse($byId['is_admin']);
        self::assertSame($byId, $byUsername);
    }

    public function testCreateDuplicateUsernameThrows(): void
    {
        $username = $this->uniqueUsername();
        self::$users->create($username, password_hash('secret', PASSWORD_DEFAULT), 'First', false);

        $this->expectException(DomainException::class);
        self::$users->create($username, password_hash('secret', PASSWORD_DEFAULT), 'Second', false);
    }

    public function testUpdatePartialFields(): void
    {
        $id = self::$users->create($this->uniqueUsername(), password_hash('secret', PASSWORD_DEFAULT), 'Before', false);

        $renamed = $this->uniqueUsername();
        $updated = self::$users->update($id, $renamed, null, true);

        self::assertSame($renamed, $updated['username']);
        self::assertSame('Before', $updated['display_name']); // untouched
        self::assertTrue($updated['is_admin']);
    }

    public function testUpdatePassword(): void
    {
        $id = self::$users->create($this->uniqueUsername(), password_hash('old-secret', PASSWORD_DEFAULT), 'Pw Test', false);

        self::$users->updatePassword($id, password_hash('new-secret', PASSWORD_DEFAULT));

        $row = self::$users->find($id);
        self::assertTrue(password_verify('new-secret', $row['password_hash']));
        self::assertFalse(password_verify('old-secret', $row['password_hash']));
    }

    public function testAllReturnsCreatedUser(): void
    {
        $username = $this->uniqueUsername();
        self::$users->create($username, password_hash('secret', PASSWORD_DEFAULT), 'List Test', false);

        $usernames = array_map(static fn (array $u) => $u['username'], self::$users->all());

        self::assertContains($username, $usernames);
    }
}
