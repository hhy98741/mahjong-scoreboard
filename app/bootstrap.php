<?php

declare(strict_types=1);

// Hand-rolled PSR-4-ish autoloader: App\Foo\Bar -> app/Foo/Bar.php.
spl_autoload_register(function (string $class): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $relative = substr($class, strlen('App\\'));
    $path = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$config = require __DIR__ . '/../config/config.php';

// Never leak internals to the client: log the real error, return a generic
// server_error envelope. Applies to both uncaught exceptions and PHP errors
// promoted to exceptions.
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

set_exception_handler(function (Throwable $e) use ($config): void {
    error_log($e->__toString());

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }

    $error = [
        'code' => 'server_error',
        'message' => 'Something went wrong.',
    ];

    if (!empty($config['debug'])) {
        $error['debug'] = $e->getMessage();
    }

    echo json_encode(['ok' => false, 'error' => $error]);
});

$router = new App\Http\Router();

require __DIR__ . '/routes.php';

$request = App\Http\Request::fromGlobals();

// /api/health must never touch a session (or the database) - deploy.sh
// smoke-tests it and rolls back on anything but a bare 200. Starting a
// session unconditionally would attach a Set-Cookie to every response,
// health included, so it is skipped entirely for that one route.
if ($request->method !== 'GET' || $request->path !== '/api/health') {
    App\Http\Middleware\Auth::start($config);
    App\Http\Middleware\Auth::guard($request, $config);
}

$router->dispatch($request, $config);
