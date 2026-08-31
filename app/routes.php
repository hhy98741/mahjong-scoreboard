<?php

declare(strict_types=1);

use App\Domain\DomainException;
use App\Http\Middleware\Auth;
use App\Http\Request;
use App\Http\Response;
use App\Repo\Db;
use App\Repo\LoginAttemptRepo;
use App\Repo\PlayerRepo;
use App\Repo\RulesetRepo;
use App\Repo\UserRepo;
use App\Service\AvatarException;
use App\Service\AvatarService;

// Exempt from auth for the whole life of the project — deploy.sh smoke-tests
// this route and rolls back the deploy on a non-200, so it must never require
// a session. See docs/03-api.md § Health.
$router->get('/api/health', function (): void {
    Response::json(['status' => 'ok', 'php' => PHP_VERSION]);
});

// ---------------------------------------------------------------- auth

/** @param array{id:int, username:string, display_name:string, is_admin:bool} $user */
$authUserPayload = static function (array $user): array {
    return [
        'id' => $user['id'],
        'username' => $user['username'],
        'display_name' => $user['display_name'],
        'is_admin' => $user['is_admin'],
    ];
};

$router->post('/api/auth/login', function (Request $request) use ($config, $authUserPayload): void {
    $username = is_string($request->body['username'] ?? null) ? trim($request->body['username']) : '';
    $password = is_string($request->body['password'] ?? null) ? $request->body['password'] : '';

    if ($username === '' || $password === '') {
        Response::error('validation_failed', 'Username and password are required.', 422, [
            'username' => 'Required.',
            'password' => 'Required.',
        ]);
        return;
    }

    $pdo = Db::connect($config);
    $attempts = new LoginAttemptRepo($pdo);
    $attempts->pruneOld();

    if ($attempts->isRateLimited($username)) {
        Response::error('rate_limited', 'Too many failed attempts. Try again in a few minutes.', 429);
        return;
    }

    $users = new UserRepo($pdo);
    $user = $users->findByUsername($username);

    if ($user === null || !password_verify($password, $user['password_hash'])) {
        $attempts->recordFailure($username);
        Response::error('unauthenticated', 'Invalid username or password.', 401);
        return;
    }

    $attempts->clearSuccess($username);
    $users->touchLastLogin($user['id']);
    Auth::login($user['id']);

    Response::json($authUserPayload($user));
});

$router->post('/api/auth/logout', function (Request $request): void {
    Auth::logout();
    Response::noContent();
});

$router->get('/api/auth/me', function (Request $request) use ($config, $authUserPayload): void {
    $user = Auth::currentUser($config);
    if ($user === null) {
        Response::error('unauthenticated', 'Not logged in.', 401);
        return;
    }

    Response::json($authUserPayload($user));
});

// ---------------------------------------------------------------- players

$colorPattern = '/^#[0-9a-fA-F]{6}$/';

/** @param array{id:int, name:string, avatar_path:?string, color:string, is_active:bool} $player */
$playerPayload = static function (array $player): array {
    return [
        'id' => $player['id'],
        'name' => $player['name'],
        'color' => $player['color'],
        'avatar_url' => $player['avatar_path'] !== null ? '/' . $player['avatar_path'] : '/default.svg',
        'is_active' => $player['is_active'],
    ];
};

$router->get('/api/players', function (Request $request) use ($config, $playerPayload): void {
    $includeInactive = ($request->query['include_inactive'] ?? null) === '1';
    $players = (new PlayerRepo(Db::connect($config)))->all($includeInactive);

    Response::json(array_map($playerPayload, $players));
});

$router->post('/api/players', function (Request $request) use ($config, $playerPayload, $colorPattern): void {
    $name = is_string($request->body['name'] ?? null) ? trim($request->body['name']) : '';
    $color = $request->body['color'] ?? null;

    $fields = [];
    if ($name === '') {
        $fields['name'] = 'Required.';
    }
    if ($color !== null && (!is_string($color) || !preg_match($colorPattern, $color))) {
        $fields['color'] = 'Must be a #rrggbb hex value.';
    }
    if ($fields !== []) {
        Response::error('validation_failed', 'Invalid player.', 422, $fields);
        return;
    }

    try {
        $player = (new PlayerRepo(Db::connect($config)))->create($name, is_string($color) ? $color : null);
    } catch (DomainException $e) {
        Response::error('validation_failed', $e->getMessage(), 422, ['name' => $e->getMessage()]);
        return;
    }

    Response::json($playerPayload($player), 201);
});

$router->patch('/api/players/{id}', function (Request $request, string $id) use ($config, $playerPayload, $colorPattern): void {
    $repo = new PlayerRepo(Db::connect($config));
    $player = $repo->find((int) $id);
    if ($player === null) {
        Response::error('not_found', 'Player not found.', 404);
        return;
    }

    $name = null;
    if (array_key_exists('name', $request->body)) {
        $name = is_string($request->body['name']) ? trim($request->body['name']) : '';
        if ($name === '') {
            Response::error('validation_failed', 'Invalid player.', 422, ['name' => 'Required.']);
            return;
        }
    }

    $color = null;
    if (array_key_exists('color', $request->body)) {
        $color = $request->body['color'];
        if (!is_string($color) || !preg_match($colorPattern, $color)) {
            Response::error('validation_failed', 'Invalid player.', 422, ['color' => 'Must be a #rrggbb hex value.']);
            return;
        }
    }

    $isActive = array_key_exists('is_active', $request->body) ? (bool) $request->body['is_active'] : null;

    try {
        $updated = $repo->update((int) $id, $name, $color, $isActive);
    } catch (DomainException $e) {
        Response::error('validation_failed', $e->getMessage(), 422, ['name' => $e->getMessage()]);
        return;
    }

    Response::json($playerPayload($updated));
});

// The one route that accepts multipart/form-data (docs/03-api.md § Auth) -
// Http/Middleware/Auth.php carves out exactly this path for that content
// type; the Origin check still applies unchanged and is the real guard.
$router->post('/api/players/{id}/avatar', function (Request $request, string $id) use ($config, $playerPayload): void {
    $repo = new PlayerRepo(Db::connect($config));
    $player = $repo->find((int) $id);
    if ($player === null) {
        Response::error('not_found', 'Player not found.', 404);
        return;
    }

    $file = $request->files['avatar'] ?? null;
    if (!is_array($file)) {
        Response::error('validation_failed', 'An avatar file is required.', 422, ['avatar' => 'Required.']);
        return;
    }

    try {
        $avatarPath = (new AvatarService((string) $config['avatar_dir']))->replace((int) $id, $file, $player['avatar_path']);
    } catch (AvatarException $e) {
        Response::error('validation_failed', $e->getMessage(), 422, ['avatar' => $e->getMessage()]);
        return;
    }

    $repo->updateAvatarPath((int) $id, $avatarPath);

    Response::json($playerPayload($repo->find((int) $id)));
});

$router->delete('/api/players/{id}/avatar', function (Request $request, string $id) use ($config, $playerPayload): void {
    $repo = new PlayerRepo(Db::connect($config));
    $player = $repo->find((int) $id);
    if ($player === null) {
        Response::error('not_found', 'Player not found.', 404);
        return;
    }

    (new AvatarService((string) $config['avatar_dir']))->delete($player['avatar_path']);
    $repo->updateAvatarPath((int) $id, null);

    Response::json($playerPayload($repo->find((int) $id)));
});

$router->delete('/api/players/{id}', function (Request $request, string $id) use ($config): void {
    $repo = new PlayerRepo(Db::connect($config));
    $player = $repo->find((int) $id);
    if ($player === null) {
        Response::error('not_found', 'Player not found.', 404);
        return;
    }

    if ($repo->isSeatedInInProgressGame((int) $id)) {
        Response::error('conflict', 'This player is seated in the game in progress.', 409);
        return;
    }

    $repo->softDelete((int) $id);
    Response::noContent();
});

// ---------------------------------------------------------------- rulesets

/** @param array{id:int, name:string, table_max_faan:int, payment_rule:string, penalty_default:int, is_default:bool, points:array<string,int>} $ruleset */
$rulesetPayload = static function (array $ruleset): array {
    return [
        'id' => $ruleset['id'],
        'name' => $ruleset['name'],
        'table_max_faan' => $ruleset['table_max_faan'],
        'payment_rule' => $ruleset['payment_rule'],
        'penalty_default' => $ruleset['penalty_default'],
        'is_default' => $ruleset['is_default'],
        // (object) forces a JSON object even though every key is a
        // sequential faan starting at 0 - json_encode would otherwise emit a
        // JSON array, contradicting the {"3": 8, "4": 16} shape in the spec.
        'points' => (object) $ruleset['points'],
    ];
};

$router->get('/api/rulesets', function (Request $request) use ($config, $rulesetPayload): void {
    Response::json(array_map($rulesetPayload, (new RulesetRepo(Db::connect($config)))->all()));
});

$router->get('/api/rulesets/{id}', function (Request $request, string $id) use ($config, $rulesetPayload): void {
    $ruleset = (new RulesetRepo(Db::connect($config)))->find((int) $id);
    if ($ruleset === null) {
        Response::error('not_found', 'Ruleset not found.', 404);
        return;
    }

    Response::json($rulesetPayload($ruleset));
});

$router->post('/api/rulesets', function (Request $request) use ($config, $rulesetPayload): void {
    $repo = new RulesetRepo(Db::connect($config));

    $source = null;
    if (isset($request->query['copy_from'])) {
        $source = $repo->find((int) $request->query['copy_from']);
        if ($source === null) {
            Response::error('validation_failed', 'Ruleset to copy from was not found.', 422, ['copy_from' => 'Not found.']);
            return;
        }
    }

    $name = is_string($request->body['name'] ?? null) ? trim($request->body['name']) : '';
    if ($name === '') {
        Response::error('validation_failed', 'Invalid ruleset.', 422, ['name' => 'Required.']);
        return;
    }

    $tableMaxFaan = array_key_exists('table_max_faan', $request->body)
        ? $request->body['table_max_faan']
        : ($source['table_max_faan'] ?? 13);
    $penaltyDefault = array_key_exists('penalty_default', $request->body)
        ? $request->body['penalty_default']
        : ($source['penalty_default'] ?? 128);
    $points = array_key_exists('points', $request->body) && is_array($request->body['points'])
        ? $request->body['points']
        : ($source['points'] ?? []);

    if (!is_int($tableMaxFaan) || $tableMaxFaan < 0 || $tableMaxFaan > 13) {
        Response::error('validation_failed', 'Invalid ruleset.', 422, ['table_max_faan' => 'Must be between 0 and 13.']);
        return;
    }
    if (!is_int($penaltyDefault) || $penaltyDefault < 0) {
        Response::error('validation_failed', 'Invalid ruleset.', 422, ['penalty_default' => 'Must be 0 or greater.']);
        return;
    }

    try {
        $ruleset = $repo->create($name, $tableMaxFaan, $penaltyDefault, $points);
    } catch (DomainException $e) {
        Response::error('validation_failed', $e->getMessage(), 422);
        return;
    }

    Response::json($rulesetPayload($ruleset), 201);
});

$router->put('/api/rulesets/{id}', function (Request $request, string $id) use ($config, $rulesetPayload): void {
    $repo = new RulesetRepo(Db::connect($config));
    $existing = $repo->find((int) $id);
    if ($existing === null) {
        Response::error('not_found', 'Ruleset not found.', 404);
        return;
    }

    $name = is_string($request->body['name'] ?? null) ? trim($request->body['name']) : '';
    if ($name === '') {
        Response::error('validation_failed', 'Invalid ruleset.', 422, ['name' => 'Required.']);
        return;
    }

    $tableMaxFaan = $request->body['table_max_faan'] ?? null;
    $penaltyDefault = $request->body['penalty_default'] ?? null;
    $points = $request->body['points'] ?? null;

    if (!is_int($tableMaxFaan) || $tableMaxFaan < 0 || $tableMaxFaan > 13) {
        Response::error('validation_failed', 'Invalid ruleset.', 422, ['table_max_faan' => 'Must be between 0 and 13.']);
        return;
    }
    if (!is_int($penaltyDefault) || $penaltyDefault < 0) {
        Response::error('validation_failed', 'Invalid ruleset.', 422, ['penalty_default' => 'Must be 0 or greater.']);
        return;
    }
    if (!is_array($points)) {
        Response::error('validation_failed', 'Invalid ruleset.', 422, ['points' => 'Required.']);
        return;
    }

    try {
        $ruleset = $repo->replace((int) $id, $name, $tableMaxFaan, $penaltyDefault, $points);
    } catch (DomainException $e) {
        Response::error('validation_failed', $e->getMessage(), 422);
        return;
    }

    Response::json($rulesetPayload($ruleset));
});

$router->delete('/api/rulesets/{id}', function (Request $request, string $id) use ($config): void {
    $repo = new RulesetRepo(Db::connect($config));
    $ruleset = $repo->find((int) $id);
    if ($ruleset === null) {
        Response::error('not_found', 'Ruleset not found.', 404);
        return;
    }

    if ($ruleset['is_default']) {
        Response::error('conflict', 'The default ruleset cannot be deleted.', 409);
        return;
    }
    if ($repo->isReferencedByInProgressGame((int) $id)) {
        Response::error('conflict', 'This ruleset is used by the game in progress.', 409);
        return;
    }

    $repo->delete((int) $id);
    Response::noContent();
});
