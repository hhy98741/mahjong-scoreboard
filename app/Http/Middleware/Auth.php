<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Request;
use App\Http\Response;
use App\Repo\Db;
use App\Repo\UserRepo;

// Session guard, plus the CSRF checks docs-initial-build/03-api.md § Auth bundles with it:
// a Content-Type restriction and the self-referential Origin/Host comparison
// (D17, D17b - no configured site origin, ever). Both run on every
// state-changing request, exempt routes included, because login itself is a
// state-changing request that needs the same protection.
final class Auth
{
    private const STATE_CHANGING = ['POST', 'PUT', 'PATCH', 'DELETE'];

    // The only routes that do not require a session. Keep in sync with
    // docs-initial-build/03-api.md § Auth and § Health.
    private const EXEMPT = [
        'GET /api/health',
        'POST /api/auth/login',
        'GET /api/auth/me',
    ];

    /** @param array<string, mixed> $config */
    public static function start(array $config): void
    {
        session_name((string) $config['session_name']);
        session_set_cookie_params([
            'lifetime' => 60 * 60 * 24 * 30, // 30 days - a living-room app, nobody re-logs in at the table.
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isHttps(),
        ]);
        session_start();
    }

    /**
     * Runs the CSRF checks unconditionally, then enforces a session for every
     * route except EXEMPT. Terminates the request itself on failure.
     *
     * @param array<string, mixed> $config
     */
    public static function guard(Request $request, array $config): void
    {
        self::checkOrigin($request);
        self::checkContentType($request);

        $routeKey = $request->method . ' ' . $request->path;
        if (in_array($routeKey, self::EXEMPT, true)) {
            return;
        }

        if (self::currentUser($config) === null) {
            Response::error('unauthenticated', 'Login required.', 401);
            exit;
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array{id:int, username:string, password_hash:string, display_name:string, is_admin:bool}|null
     */
    public static function currentUser(array $config): ?array
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!is_int($userId)) {
            return null;
        }

        return (new UserRepo(Db::connect($config)))->find($userId);
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
    }

    public static function logout(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params['path'],
                'domain' => $params['domain'],
                'secure' => $params['secure'],
                'httponly' => $params['httponly'],
                'samesite' => $params['samesite'] !== '' ? $params['samesite'] : 'Lax',
            ]);
        }

        session_destroy();
    }

    private static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['SERVER_PORT'] ?? null) === '443';
    }

    // docs-initial-build/03-api.md § Auth: reject a state-changing request whose Origin is
    // present and whose host does not match the request's own Host. Absent
    // Origin is a pass - non-browser clients send none, and SameSite=Lax
    // covers browsers that omit it. Host is compared host-only, case
    // insensitively.
    private static function checkOrigin(Request $request): void
    {
        if (!in_array($request->method, self::STATE_CHANGING, true)) {
            return;
        }

        $origin = $request->header('Origin');
        if ($origin === null || $origin === '') {
            return;
        }

        $originHost = strtolower((string) (parse_url($origin, PHP_URL_HOST) ?? ''));
        $requestHost = strtolower(explode(':', $request->header('Host') ?? '', 2)[0]);

        if ($originHost === '' || $originHost !== $requestHost) {
            Response::error('forbidden', 'Cross-origin request rejected.', 403);
            exit;
        }
    }

    // The only exception is multipart avatar upload (docs-initial-build/03-api.md § Auth) -
    // that route allows multipart/form-data too, and relies on the Origin
    // check above rather than this one. Do not widen this to any other route.
    private static function checkContentType(Request $request): void
    {
        if (!in_array($request->method, self::STATE_CHANGING, true)) {
            return;
        }

        $contentType = $request->header('Content-Type') ?? '';

        if (self::isAvatarUploadRoute($request) && str_starts_with($contentType, 'multipart/form-data')) {
            return;
        }

        if (!str_starts_with($contentType, 'application/json')) {
            Response::error('validation_failed', 'Content-Type must be application/json.', 422);
            exit;
        }
    }

    private static function isAvatarUploadRoute(Request $request): bool
    {
        return $request->method === 'POST' && (bool) preg_match('#^/api/players/\d+/avatar$#', $request->path);
    }
}
