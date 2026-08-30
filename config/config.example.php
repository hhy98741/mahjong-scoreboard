<?php
// Copy to config/config.php and fill in real values. That file is gitignored
// and never leaves this machine (or the server, where it is created once by
// hand and never synced by deploy.sh). See docs/05-deployment.md.

return [
    'db' => [
        'host' => 'localhost',
        'name' => 'mahjong',
        'user' => 'mahjong',
        'pass' => 'changeme',
    ],
    'avatar_dir'   => __DIR__ . '/../public_html/avatars',
    'session_name' => 'mjsb',
    'debug'        => true,
];
