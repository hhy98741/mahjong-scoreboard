<?php

declare(strict_types=1);

namespace App\Repo;

use App\Domain\DomainException;
use PDO;

final class UserRepo
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** @return list<array{id:int, username:string, password_hash:string, display_name:string, is_admin:bool}> */
    public function all(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, username, password_hash, display_name, is_admin FROM users ORDER BY username'
        );

        return array_map($this->hydrate(...), $stmt->fetchAll());
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
        try {
            $stmt->execute([$username, $passwordHash, $displayName, $isAdmin ? 1 : 0]);
        } catch (\PDOException $e) {
            throw $this->translateUniqueViolation($e, $username);
        }

        return (int) $this->pdo->lastInsertId();
    }

    /** @return array{id:int, username:string, password_hash:string, display_name:string, is_admin:bool} */
    public function update(int $id, ?string $username, ?string $displayName, ?bool $isAdmin): array
    {
        $fields = [];
        $params = [];

        if ($username !== null) {
            $fields[] = 'username = ?';
            $params[] = $username;
        }
        if ($displayName !== null) {
            $fields[] = 'display_name = ?';
            $params[] = $displayName;
        }
        if ($isAdmin !== null) {
            $fields[] = 'is_admin = ?';
            $params[] = $isAdmin ? 1 : 0;
        }

        if ($fields !== []) {
            $params[] = $id;
            $stmt = $this->pdo->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?');
            try {
                $stmt->execute($params);
            } catch (\PDOException $e) {
                throw $this->translateUniqueViolation($e, $username ?? '');
            }
        }

        return $this->find($id);
    }

    public function updatePassword(int $id, string $passwordHash): void
    {
        $this->pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([$passwordHash, $id]);
    }

    public function touchLastLogin(int $id): void
    {
        $this->pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = ?')->execute([$id]);
    }

    private function translateUniqueViolation(\PDOException $e, string $username): \PDOException|DomainException
    {
        if ($e->getCode() === '23000') {
            return new DomainException("A user named '{$username}' already exists.");
        }

        return $e;
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
