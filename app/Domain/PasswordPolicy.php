<?php

declare(strict_types=1);

namespace App\Domain;

// The one password-strength rule, shared by every place a password is set:
// self-service change (PATCH /api/auth/password), admin create-user (POST
// /api/users) and admin reset (POST /api/users/{id}/password), plus
// bin/create-user.php. Mirrored in frontend/src/passwordPolicy.ts for the
// live checklist under each "new password" field - keep both in sync if
// this ever changes.
final class PasswordPolicy
{
    public const MIN_LENGTH = 12;

    /** @return list<string> unmet requirements, in a fixed order; empty means the password is valid */
    public static function violations(string $password): array
    {
        $violations = [];
        if (strlen($password) < self::MIN_LENGTH) {
            $violations[] = 'at least ' . self::MIN_LENGTH . ' characters';
        }
        if (!preg_match('/[A-Za-z]/', $password)) {
            $violations[] = 'a letter';
        }
        if (!preg_match('/[0-9]/', $password)) {
            $violations[] = 'a number';
        }
        if (!preg_match('/[^A-Za-z0-9]/', $password)) {
            $violations[] = 'a symbol';
        }

        return $violations;
    }

    public static function isValid(string $password): bool
    {
        return self::violations($password) === [];
    }

    public static function describeViolations(string $password): string
    {
        return 'Password must have ' . implode(', ', self::violations($password)) . '.';
    }
}
