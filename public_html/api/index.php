<?php
declare(strict_types=1);

// Derive the app directory from this file's own location rather than hardcoding
// a path: the docroot is ~/sites/<name> and the source is ~/apps/<name>, so the
// name is already known. Keeps every host-specific path out of the repo.
$docroot = dirname(__DIR__);          // ~/sites/<name>
$home    = dirname($docroot, 2);      // ~
$name    = basename($docroot);        // <name>

foreach ([
    "$home/apps/$name/app/bootstrap.php",   // production: ~/apps/<name>/
    __DIR__ . '/../../app/bootstrap.php',   // local: public_html/api/ -> repo root app/
] as $bootstrap) {
    if (is_file($bootstrap)) { require $bootstrap; return; }
}

http_response_code(500);
header('Content-Type: application/json');
echo '{"ok":false,"error":{"code":"server_error","message":"bootstrap not found"}}';
