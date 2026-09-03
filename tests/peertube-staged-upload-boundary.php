<?php
/**
 * R42 regression boundary: state-machine foundation must not enable upload.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Backend_Capabilities.php';

use ArgentVideo\Backend_Capabilities;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$capabilities = Backend_Capabilities::peertube_activation();
$assert(
    false === ($capabilities[Backend_Capabilities::INGEST_AWVP_STAGING] ?? null),
    'R42 state-machine foundation unexpectedly enabled AWVP-staged ingest.'
);
$assert(
    false === ($capabilities[Backend_Capabilities::INGEST_SERVER_PUSH] ?? null),
    'R42 state-machine foundation unexpectedly enabled PeerTube server push.'
);
$assert(
    false === ($capabilities[Backend_Capabilities::PROCESSING_VIDEO] ?? null),
    'R42 state-machine foundation unexpectedly claimed PeerTube processing authority.'
);

$runtime_files = array(
    'includes/PeerTube_Http_Client.php',
    'includes/PeerTube_Api_Client.php',
    'includes/PeerTube_Staged_Source_Identity.php',
    'includes/PeerTube_Staged_Upload_State_Machine.php',
    'includes/PeerTube_Staged_Upload_Guard.php',
    'includes/PeerTube_Staged_Upload_Operation_Store.php',
    'includes/PeerTube_Connection_Admin.php',
    'includes/Plugin.php',
);

foreach ($runtime_files as $relative) {
    $source = file_get_contents(dirname(__DIR__) . '/' . $relative);
    $assert(is_string($source), 'Could not inspect R42 upload boundary file: ' . $relative);
    $lower = strtolower((string) $source);
    $assert(
        ! str_contains($lower, '/api/v1/videos/upload')
        && ! str_contains($lower, 'videos/upload-resumable')
        && ! str_contains($lower, 'videos/upload'),
        'R42 state-machine foundation introduced a PeerTube media-upload endpoint in ' . $relative
    );
}

$new_runtime = implode(
    "\n",
    array_map(
        static fn (string $relative): string => (string) file_get_contents(dirname(__DIR__) . '/' . $relative),
        array(
            'includes/PeerTube_Staged_Source_Identity.php',
            'includes/PeerTube_Staged_Upload_State_Machine.php',
            'includes/PeerTube_Staged_Upload_Guard.php',
            'includes/PeerTube_Staged_Upload_Operation_Store.php',
        )
    )
);
$assert(! str_contains($new_runtime, 'wp_remote_post'), 'R42 state-machine foundation performs an HTTP POST.');
$assert(! str_contains($new_runtime, 'wp_safe_remote_post'), 'R42 state-machine foundation performs a safe HTTP POST.');
$assert(! str_contains($new_runtime, 'wp_schedule'), 'R42 state-machine foundation schedules background upload work.');

fwrite(STDOUT, "PeerTube staged upload no-mutation boundary tests passed.\n");
