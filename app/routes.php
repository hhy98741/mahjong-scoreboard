<?php

declare(strict_types=1);

use App\Domain\DomainException;
use App\Domain\PasswordPolicy;
use App\Http\Middleware\Auth;
use App\Http\Request;
use App\Http\Response;
use App\Repo\Db;
use App\Repo\LoginAttemptRepo;
use App\Repo\PlayerRepo;
use App\Repo\RulesetRepo;
use App\Repo\StatsRepo;
use App\Repo\UserRepo;
use App\Service\AvatarException;
use App\Service\AvatarService;
use App\Service\ConflictException;
use App\Service\GameService;

// Exempt from auth for the whole life of the project — deploy.sh smoke-tests
// this route and rolls back the deploy on a non-200, so it must never require
// a session. See docs-initial-build/03-api.md § Health.
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

// Self-service password change - the one place a current-password check is
// required, since every admin-only route below is already gated on is_admin.
$router->patch('/api/auth/password', function (Request $request) use ($config): void {
    $currentUser = Auth::currentUser($config);
    if ($currentUser === null) {
        Response::error('unauthenticated', 'Login required.', 401);
        return;
    }

    $currentPassword = is_string($request->body['current_password'] ?? null) ? $request->body['current_password'] : '';
    $newPassword = is_string($request->body['new_password'] ?? null) ? $request->body['new_password'] : '';

    if (!password_verify($currentPassword, $currentUser['password_hash'])) {
        Response::error('validation_failed', 'Current password is incorrect.', 422, ['current_password' => 'Incorrect.']);
        return;
    }
    if (!PasswordPolicy::isValid($newPassword)) {
        $message = PasswordPolicy::describeViolations($newPassword);
        Response::error('validation_failed', $message, 422, ['new_password' => $message]);
        return;
    }

    (new UserRepo(Db::connect($config)))->updatePassword($currentUser['id'], password_hash($newPassword, PASSWORD_DEFAULT));
    Response::noContent();
});

// ---------------------------------------------------------------- players

$colorPattern = '/^#[0-9a-fA-F]{6}$/';

/** @param array{id:int, name:string, avatar_path:?string, color:string, is_active:bool, user_id:?int} $player */
$playerPayload = static function (array $player): array {
    return [
        'id' => $player['id'],
        'name' => $player['name'],
        'color' => $player['color'],
        'avatar_url' => $player['avatar_path'] !== null ? '/' . $player['avatar_path'] : '/default.svg',
        'is_active' => $player['is_active'],
        'user_id' => $player['user_id'],
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

    // D29: linking a player to a login is admin-only, and null is a meaningful
    // target value ("unlink") rather than "leave unchanged" - keep it a
    // separate check/call from the fields above.
    if (array_key_exists('user_id', $request->body)) {
        $currentUser = Auth::currentUser($config);
        if ($currentUser === null || !$currentUser['is_admin']) {
            Response::error('forbidden', 'Admin privileges required.', 403);
            return;
        }

        $rawUserId = $request->body['user_id'];
        if ($rawUserId !== null && !is_int($rawUserId)) {
            Response::error('validation_failed', 'Invalid player.', 422, ['user_id' => 'Must be a user id or null.']);
            return;
        }
        if ($rawUserId !== null && (new UserRepo(Db::connect($config)))->find($rawUserId) === null) {
            Response::error('validation_failed', 'Invalid player.', 422, ['user_id' => 'User not found.']);
            return;
        }

        try {
            $updated = $repo->linkUser((int) $id, $rawUserId);
        } catch (DomainException $e) {
            Response::error('validation_failed', $e->getMessage(), 422, ['user_id' => $e->getMessage()]);
            return;
        }
    }

    Response::json($playerPayload($updated));
});

// The one route that accepts multipart/form-data (docs-initial-build/03-api.md § Auth) -
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

// ---------------------------------------------------------------- users (admin)

$router->get('/api/users', function (Request $request) use ($config, $authUserPayload): void {
    $currentUser = Auth::currentUser($config);
    if ($currentUser === null || !$currentUser['is_admin']) {
        Response::error('forbidden', 'Admin privileges required.', 403);
        return;
    }

    $users = (new UserRepo(Db::connect($config)))->all();
    Response::json(array_map($authUserPayload, $users));
});

$router->post('/api/users', function (Request $request) use ($config, $authUserPayload): void {
    $currentUser = Auth::currentUser($config);
    if ($currentUser === null || !$currentUser['is_admin']) {
        Response::error('forbidden', 'Admin privileges required.', 403);
        return;
    }

    $username = is_string($request->body['username'] ?? null) ? trim($request->body['username']) : '';
    $displayName = is_string($request->body['display_name'] ?? null) ? trim($request->body['display_name']) : '';
    $password = is_string($request->body['password'] ?? null) ? $request->body['password'] : '';
    $isAdmin = (bool) ($request->body['is_admin'] ?? false);

    $fields = [];
    if ($username === '') {
        $fields['username'] = 'Required.';
    }
    if ($displayName === '') {
        $fields['display_name'] = 'Required.';
    }
    if (!PasswordPolicy::isValid($password)) {
        $fields['password'] = PasswordPolicy::describeViolations($password);
    }
    if ($fields !== []) {
        Response::error('validation_failed', 'Invalid user.', 422, $fields);
        return;
    }

    $users = new UserRepo(Db::connect($config));
    try {
        $id = $users->create($username, password_hash($password, PASSWORD_DEFAULT), $displayName, $isAdmin);
    } catch (DomainException $e) {
        Response::error('validation_failed', $e->getMessage(), 422, ['username' => $e->getMessage()]);
        return;
    }

    Response::json($authUserPayload($users->find($id)), 201);
});

$router->patch('/api/users/{id}', function (Request $request, string $id) use ($config, $authUserPayload): void {
    $currentUser = Auth::currentUser($config);
    if ($currentUser === null || !$currentUser['is_admin']) {
        Response::error('forbidden', 'Admin privileges required.', 403);
        return;
    }

    $users = new UserRepo(Db::connect($config));
    $target = $users->find((int) $id);
    if ($target === null) {
        Response::error('not_found', 'User not found.', 404);
        return;
    }

    $username = null;
    if (array_key_exists('username', $request->body)) {
        $username = is_string($request->body['username']) ? trim($request->body['username']) : '';
        if ($username === '') {
            Response::error('validation_failed', 'Invalid user.', 422, ['username' => 'Required.']);
            return;
        }
    }

    $displayName = null;
    if (array_key_exists('display_name', $request->body)) {
        $displayName = is_string($request->body['display_name']) ? trim($request->body['display_name']) : '';
        if ($displayName === '') {
            Response::error('validation_failed', 'Invalid user.', 422, ['display_name' => 'Required.']);
            return;
        }
    }

    $isAdmin = null;
    if (array_key_exists('is_admin', $request->body)) {
        $isAdmin = (bool) $request->body['is_admin'];
        if (!$isAdmin && (int) $id === $currentUser['id']) {
            Response::error('validation_failed', 'You cannot remove your own admin rights.', 422, ['is_admin' => 'Cannot remove your own admin rights.']);
            return;
        }
    }

    try {
        $updated = $users->update((int) $id, $username, $displayName, $isAdmin);
    } catch (DomainException $e) {
        Response::error('validation_failed', $e->getMessage(), 422, ['username' => $e->getMessage()]);
        return;
    }

    Response::json($authUserPayload($updated));
});

// No current-password check - the caller is already authenticated as admin.
$router->post('/api/users/{id}/password', function (Request $request, string $id) use ($config): void {
    $currentUser = Auth::currentUser($config);
    if ($currentUser === null || !$currentUser['is_admin']) {
        Response::error('forbidden', 'Admin privileges required.', 403);
        return;
    }

    $users = new UserRepo(Db::connect($config));
    $target = $users->find((int) $id);
    if ($target === null) {
        Response::error('not_found', 'User not found.', 404);
        return;
    }

    $password = is_string($request->body['password'] ?? null) ? $request->body['password'] : '';
    if (!PasswordPolicy::isValid($password)) {
        $message = PasswordPolicy::describeViolations($password);
        Response::error('validation_failed', $message, 422, ['password' => $message]);
        return;
    }

    $users->updatePassword((int) $id, password_hash($password, PASSWORD_DEFAULT));
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

$router->patch('/api/rulesets/{id}/default', function (Request $request, string $id) use ($config, $rulesetPayload): void {
    $repo = new RulesetRepo(Db::connect($config));
    $ruleset = $repo->find((int) $id);
    if ($ruleset === null) {
        Response::error('not_found', 'Ruleset not found.', 404);
        return;
    }

    Response::json($rulesetPayload($repo->setDefault((int) $id)));
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

// ---------------------------------------------------------------- games

// GameService owns validation, the eight-step hand transaction, and payload
// assembly (docs-initial-build/03-api.md § Games; docs-initial-build/02-scoring-engine.md). Routes below
// only parse the request and translate exceptions to HTTP status codes:
// DomainException -> 422, ConflictException -> 409, a null return -> 404.

$router->post('/api/games', function (Request $request) use ($config): void {
    $service = new GameService(Db::connect($config));
    $currentUser = Auth::currentUser($config);

    try {
        $payload = $service->createGame($request->body, $currentUser['id'] ?? null);
    } catch (ConflictException $e) {
        Response::error('conflict', $e->getMessage(), 409);
        return;
    } catch (DomainException $e) {
        Response::error('validation_failed', $e->getMessage(), 422);
        return;
    }

    Response::json($payload, 201);
});

// Registered before /api/games/{id} - the router matches routes in
// registration order and {id} would otherwise swallow "current" as an id.
$router->get('/api/games/current', function (Request $request) use ($config): void {
    $service = new GameService(Db::connect($config));
    $gameId = $service->findCurrentId();
    if ($gameId === null) {
        Response::error('not_found', 'No game in progress.', 404);
        return;
    }

    Response::json($service->assemblePayload($gameId));
});

$router->get('/api/games', function (Request $request) use ($config): void {
    $service = new GameService(Db::connect($config));

    $filters = [];
    foreach (['status', 'from', 'to'] as $key) {
        if (isset($request->query[$key])) {
            $filters[$key] = (string) $request->query[$key];
        }
    }
    foreach (['player_id', 'player_count', 'limit', 'offset'] as $key) {
        if (isset($request->query[$key])) {
            $filters[$key] = (int) $request->query[$key];
        }
    }

    Response::json($service->listSummaries($filters));
});

$router->get('/api/games/{id}', function (Request $request, string $id) use ($config): void {
    $payload = (new GameService(Db::connect($config)))->assemblePayload((int) $id);
    if ($payload === null) {
        Response::error('not_found', 'Game not found.', 404);
        return;
    }

    Response::json($payload);
});

$router->post('/api/games/{id}/hands', function (Request $request, string $id) use ($config): void {
    $service = new GameService(Db::connect($config));
    $currentUser = Auth::currentUser($config);

    try {
        $payload = $service->recordHand((int) $id, $request->body, $currentUser['id'] ?? null);
    } catch (ConflictException $e) {
        Response::error('conflict', $e->getMessage(), 409);
        return;
    } catch (DomainException $e) {
        Response::error('validation_failed', $e->getMessage(), 422);
        return;
    }

    if ($payload === null) {
        Response::error('not_found', 'Game not found.', 404);
        return;
    }

    Response::json($payload, 201);
});

$router->delete('/api/games/{id}/hands/last', function (Request $request, string $id) use ($config): void {
    $service = new GameService(Db::connect($config));

    try {
        $payload = $service->undoLastHand((int) $id);
    } catch (ConflictException $e) {
        Response::error('conflict', $e->getMessage(), 409);
        return;
    }

    if ($payload === null) {
        Response::error('not_found', 'Game not found.', 404);
        return;
    }

    Response::json($payload);
});

$router->post('/api/games/{id}/end', function (Request $request, string $id) use ($config): void {
    $service = new GameService(Db::connect($config));
    $status = is_string($request->body['status'] ?? null) ? $request->body['status'] : '';

    try {
        $payload = $service->endGame((int) $id, $status);
    } catch (ConflictException $e) {
        Response::error('conflict', $e->getMessage(), 409);
        return;
    } catch (DomainException $e) {
        Response::error('validation_failed', $e->getMessage(), 422, ['status' => $e->getMessage()]);
        return;
    }

    if ($payload === null) {
        Response::error('not_found', 'Game not found.', 404);
        return;
    }

    Response::json($payload);
});

$router->patch('/api/games/{id}', function (Request $request, string $id) use ($config): void {
    $service = new GameService(Db::connect($config));
    $name = is_string($request->body['name'] ?? null) ? $request->body['name'] : '';

    $payload = $service->renameGame((int) $id, $name);
    if ($payload === null) {
        Response::error('not_found', 'Game not found.', 404);
        return;
    }

    Response::json($payload);
});

// Admin only (docs-initial-build/03-api.md § Games), hard delete, requires ?confirm=1 - the
// one destructive route in the API.
$router->delete('/api/games/{id}', function (Request $request, string $id) use ($config): void {
    $currentUser = Auth::currentUser($config);
    if ($currentUser === null || !$currentUser['is_admin']) {
        Response::error('forbidden', 'Admin privileges required.', 403);
        return;
    }
    if (($request->query['confirm'] ?? null) !== '1') {
        Response::error('validation_failed', 'Pass ?confirm=1 to permanently delete this game.', 422, ['confirm' => 'Required.']);
        return;
    }

    $deleted = (new GameService(Db::connect($config)))->deleteGame((int) $id);
    if (!$deleted) {
        Response::error('not_found', 'Game not found.', 404);
        return;
    }

    Response::noContent();
});

// ---------------------------------------------------------------- stats

// Read-only, backing docs-initial-build/06-history-reports.md via Repo\StatsRepo.
// player_count defaults to 4 on EVERY stats endpoint (D25) — rate
// statistics are not comparable across player counts. ?player_count=all
// blends deliberately.
$parseStatsFilters = static function (Request $request): array {
    $filters = ['player_count' => 4];

    if (isset($request->query['from'])) {
        $filters['from'] = (string) $request->query['from'];
    }
    if (isset($request->query['to'])) {
        $filters['to'] = (string) $request->query['to'];
    }
    if (isset($request->query['player_ids']) && $request->query['player_ids'] !== '') {
        $filters['player_ids'] = array_values(array_filter(
            array_map(static fn (string $v): int => (int) trim($v), explode(',', (string) $request->query['player_ids'])),
            static fn (int $id): bool => $id > 0
        ));
    }
    if (isset($request->query['player_count'])) {
        $raw = (string) $request->query['player_count'];
        $filters['player_count'] = $raw === 'all' ? 'all' : (int) $raw;
    }
    $filters['include_abandoned'] = ($request->query['include_abandoned'] ?? null) === '1';

    return $filters;
};

$router->get('/api/stats/leaderboard', function (Request $request) use ($config, $parseStatsFilters): void {
    $filters = $parseStatsFilters($request);
    Response::json((new StatsRepo(Db::connect($config)))->leaderboard($filters));
});

$router->get('/api/stats/players/{id}', function (Request $request, string $id) use ($config, $parseStatsFilters): void {
    $filters = $parseStatsFilters($request);
    $payload = (new StatsRepo(Db::connect($config)))->playerDetail((int) $id, $filters);
    if ($payload === null) {
        Response::error('not_found', 'Player not found.', 404);
        return;
    }

    Response::json($payload);
});

$router->get('/api/stats/flow', function (Request $request) use ($config, $parseStatsFilters): void {
    Response::json((new StatsRepo(Db::connect($config)))->flow($parseStatsFilters($request)));
});

$router->get('/api/stats/seats', function (Request $request) use ($config, $parseStatsFilters): void {
    Response::json((new StatsRepo(Db::connect($config)))->seats($parseStatsFilters($request)));
});

$router->get('/api/stats/records', function (Request $request) use ($config, $parseStatsFilters): void {
    Response::json((new StatsRepo(Db::connect($config)))->records($parseStatsFilters($request)));
});

$router->get('/api/stats/feeders', function (Request $request) use ($config, $parseStatsFilters): void {
    Response::json((new StatsRepo(Db::connect($config)))->feeders($parseStatsFilters($request)));
});

$router->get('/api/stats/win-types', function (Request $request) use ($config, $parseStatsFilters): void {
    Response::json((new StatsRepo(Db::connect($config)))->winTypes($parseStatsFilters($request)));
});

$router->get('/api/stats/games/{id}/curve', function (Request $request, string $id) use ($config): void {
    $payload = (new StatsRepo(Db::connect($config)))->gameCurve((int) $id);
    if ($payload === null) {
        Response::error('not_found', 'Game not found.', 404);
        return;
    }

    Response::json($payload);
});
