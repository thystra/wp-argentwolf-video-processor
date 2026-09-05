<?php
/** Focused tests for R45.4b3 upload/process runtime budgets. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/PeerTube_Upload_Runtime_Budget.php';

use ArgentVideo\PeerTube_Upload_Runtime_Budget;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$base = PeerTube_Upload_Runtime_Budget::BASE_BYTES;
$assert(3600 === PeerTube_Upload_Runtime_Budget::process_seconds(1), 'Tiny upload lost the one-hour floor.');
$assert(3600 === PeerTube_Upload_Runtime_Budget::process_seconds($base), '128 MiB upload lost the one-hour floor.');
$assert(3600 === PeerTube_Upload_Runtime_Budget::request_seconds(1024 * 1024 * 1024), '1 GiB segment should remain inside the one-hour floor.');
$assert(4800 === PeerTube_Upload_Runtime_Budget::process_seconds(10 * 1024 * 1024 * 1024), '10 GiB upload did not scale from the 128 MiB/minute guard.');
$assert(21600 === PeerTube_Upload_Runtime_Budget::process_seconds(45 * 1024 * 1024 * 1024), '45 GiB upload did not reach the six-hour ceiling.');
$assert(21600 === PeerTube_Upload_Runtime_Budget::process_seconds(100 * 1024 * 1024 * 1024), 'Large upload exceeded the six-hour ceiling.');
$assert(3600 === PeerTube_Upload_Runtime_Budget::process_seconds(0), 'Invalid/unknown size did not fail to the conservative one-hour floor.');

fwrite(STDOUT, "PeerTube upload runtime budget tests passed.\n");
