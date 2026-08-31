<?php

declare(strict_types=1);

namespace App\Repo;

use PDO;

// Login throttling against docs-initial-build/03-api.md § Auth: 5 failures per username per
// 15 minutes. State lives here, not in the session - the attacker controls
// their own session. Keyed on the username as typed, so a bad username
// throttles the same way a bad password does and the endpoint cannot be used
// to enumerate accounts.
final class LoginAttemptRepo
{
    private const WINDOW_MINUTES = 15;
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly PDO $pdo)
    {
    }

    public function isRateLimited(string $username): bool
    {
        $this->pdo->prepare(
            'DELETE FROM login_attempts WHERE username = ? AND attempted_at < (NOW() - INTERVAL ' . self::WINDOW_MINUTES . ' MINUTE)'
        )->execute([$username]);

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE username = ?');
        $stmt->execute([$username]);

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public function recordFailure(string $username): void
    {
        $this->pdo->prepare('INSERT INTO login_attempts (username) VALUES (?)')->execute([$username]);
    }

    public function clearSuccess(string $username): void
    {
        $this->pdo->prepare('DELETE FROM login_attempts WHERE username = ?')->execute([$username]);
    }

    public function pruneOld(): void
    {
        $this->pdo->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    }
}
