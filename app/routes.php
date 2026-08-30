<?php

declare(strict_types=1);

use App\Http\Response;

// Exempt from auth for the whole life of the project — deploy.sh smoke-tests
// this route and rolls back the deploy on a non-200, so it must never require
// a session. See docs/03-api.md § Health.
$router->get('/api/health', function (): void {
    Response::json(['status' => 'ok', 'php' => PHP_VERSION]);
});
