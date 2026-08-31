<?php

declare(strict_types=1);

// Reads credentials from config/config.php and execs mysqldump, streaming SQL
// to stdout. The password is handed to mysqldump via the MYSQL_PWD
// environment variable of a proc_open'd child (never a -p CLI argument, which
// would be visible to anyone on the box running `ps`) and proc_open is given
// an argv array rather than a shell string, so it never touches a shell or
// shell history either.

$config = require __DIR__ . '/../config/config.php';
$db = $config['db'];

$command = [
    'mysqldump',
    '--single-transaction',
    '--host=' . $db['host'],
    '--user=' . $db['user'],
    $db['name'],
];

// $_SERVER carries non-string entries (e.g. 'argv'); proc_open's env array
// requires string values throughout.
$env = array_merge(array_filter($_SERVER, 'is_string'), ['MYSQL_PWD' => $db['pass']]);

$descriptors = [
    0 => ['pipe', 'r'],
    1 => STDOUT,
    2 => STDERR,
];

$process = proc_open($command, $descriptors, $pipes, null, $env);

if (!is_resource($process)) {
    fwrite(STDERR, "Failed to start mysqldump.\n");
    exit(1);
}

fclose($pipes[0]);
exit(proc_close($process));
