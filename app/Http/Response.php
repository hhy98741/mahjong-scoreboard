<?php

declare(strict_types=1);

namespace App\Http;

final class Response
{
    public static function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => true, 'data' => $data]);
    }

    public static function noContent(): void
    {
        self::json(null, 200);
    }

    /** @param array<string, string>|null $fields */
    public static function error(string $code, string $message, int $status, ?array $fields = null): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        $error = ['code' => $code, 'message' => $message];
        if ($fields !== null) {
            $error['fields'] = $fields;
        }
        echo json_encode(['ok' => false, 'error' => $error]);
    }
}
