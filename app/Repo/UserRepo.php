<?php

declare(strict_types=1);

namespace App\Repo;

use PDO;

final class UserRepo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return array{id:int, username:string, password_hash:string, display_name:string, is_admin:bool}|null */
    public function findByUsername(string $username): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, display_name, is_admin FROM users WHERE username = ?'
        );
        $stmt->execute([$username]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    /** @return array{id:int, username:string, password_hash:string, display_name:string, is_admin:bool}|null */
    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, username, password_hash, display_name, is_admin FROM users WHERE id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $this->hydrate($row);
    }

    public function create(string $username, string $passwordHash, string $displayName, bool $isAdmin): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (username, password_hash, display_name, is_admin) VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$username, $passwordHash, $displayName, $isAdmin ? 1 : 0]);

        return (int) $this->pdo->lastInsertId();
    }

    public function touchLastLogin(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$id]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id:int, username:string, password_hash:string, display_name:string, is_admin:bool}
     */
    private function hydrate(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'username' => (string) $row['username'],
            'password_hash' => (string) $row['password_hash'],
            'display_name' => (string) $row['display_name'],
            'is_admin' => (bool) $row['is_admin'],
        ];
    }
}
