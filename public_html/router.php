<?php
// Dev only. Production routing is deploy/remote/.htaccess.
// Return false for real files so the built-in server serves them itself.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/')) {
    require __DIR__ . '/api/index.php';
    return true;
}
return false;
