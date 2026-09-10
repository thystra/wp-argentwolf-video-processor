<?php
/** Dependency-free RC10 operator terminology regression. */
declare(strict_types=1);

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$root = dirname(__DIR__);
$connection = (string) file_get_contents($root . '/includes/PeerTube_Connection_Admin.php');
$overview = (string) file_get_contents($root . '/includes/PeerTube_Overview_Admin.php');
$migration = (string) file_get_contents($root . '/includes/PeerTube_Migration_Admin.php');

$assert(
    str_contains($connection, "Connect a PeerTube Server"),
    'Connection page does not use the required PeerTube server heading.'
);
$assert(
    ! str_contains($connection, 'Connect a PeerTube Instance')
        && ! str_contains($connection, 'PeerTube instance you wish to connect'),
    'Operator connection copy still calls the PeerTube server an instance.'
);
$assert(
    str_contains($connection, "Backend ID (internal identifier)"),
    'Connection UI does not label Backend ID as an internal identifier.'
);
$assert(
    str_contains(
        $connection,
        'The Backend ID is used in logs, diagnostics, and error messages to identify the particular PeerTube server involved.'
    ),
    'Connection UI is missing the required Backend ID helper sentence.'
);
$assert(
    str_contains($overview, "Backend ID (internal identifier):"),
    'Overview diagnostics do not use the operator-facing Backend ID label.'
);
$assert(
    ! str_contains($migration, 'PeerTube instance')
        && ! str_contains($migration, 'PeerTube Instance'),
    'Migration UI still exposes PeerTube instance terminology.'
);

fwrite(STDOUT, "RC10 PeerTube operator terminology tests passed.\n");
