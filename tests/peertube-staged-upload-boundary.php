<?php
/**
 * R43 regression boundary: executable resumable-upload primitives exist, but
 * capability advertisement and WordPress-triggerable upload entry points stay off.
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
    'R43 executable checkpoint prematurely advertised AWVP-staged ingest.'
);
$assert(
    false === ($capabilities[Backend_Capabilities::INGEST_SERVER_PUSH] ?? null),
    'R43 executable checkpoint prematurely advertised PeerTube server push.'
);
$assert(
    false === ($capabilities[Backend_Capabilities::PROCESSING_VIDEO] ?? null),
    'R43 executable checkpoint prematurely claimed PeerTube processing authority.'
);

$root = dirname(__DIR__);
$http = (string) file_get_contents($root . '/includes/PeerTube_Http_Client.php');
$api = (string) file_get_contents($root . '/includes/PeerTube_Api_Client.php');
$service = (string) file_get_contents($root . '/includes/PeerTube_Staged_Upload_Service.php');
$plugin = (string) file_get_contents($root . '/includes/Plugin.php');
$admin = (string) file_get_contents($root . '/includes/PeerTube_Connection_Admin.php');
$loader = (string) file_get_contents($root . '/argentwolf-video-processor.php');

$assert(
    1 === substr_count($http, "'/api/v1/videos/upload-resumable'"),
    'R43 reviewed resumable-upload path must be declared exactly once in the bounded HTTP client.'
);
foreach (array($http, $api, $service, $plugin, $admin, $loader) as $runtime) {
    $assert(
        ! str_contains($runtime, "'/api/v1/videos/upload'")
        && ! str_contains($runtime, '"/api/v1/videos/upload"'),
        'R43 introduced the unreviewed legacy multipart upload endpoint.'
    );
}

$assert(
    str_contains($http, 'post_resumable_upload_init')
    && str_contains($http, 'put_resumable_upload_chunk')
    && str_contains($http, 'put_resumable_upload_probe'),
    'R43 bounded HTTP client is missing one reviewed resumable primitive.'
);
$assert(
    str_contains($api, 'begin_resumable_upload')
    && str_contains($api, 'upload_resumable_chunk')
    && str_contains($api, 'probe_resumable_upload'),
    'R43 API projection is missing one reviewed resumable primitive.'
);
$assert(
    str_contains($service, 'EVENT_CLAIM_UPLOAD')
    && str_contains($service, 'PHASE_UPLOAD_INDETERMINATE')
    && str_contains($service, 'probe_resumable_upload'),
    'R43 executor lost its durable claim/uncertainty/reconciliation boundary.'
);

// The service is class-loaded for testability but is deliberately not wired to
// a user-, cron-, REST-, AJAX-, WP-CLI-, or capability-triggered execution path.
$assert(
    str_contains($loader, "PeerTube_Staged_Upload_Service.php"),
    'R43 staged-upload executor is not loadable.'
);
$assert(
    ! str_contains($plugin, 'PeerTube_Staged_Upload_Service')
    && ! str_contains($admin, 'PeerTube_Staged_Upload_Service'),
    'R43 staged-upload executor was prematurely wired into WordPress runtime actions.'
);

$entrypoint_surface = strtolower($plugin . "\n" . $admin);
foreach (array('wp_ajax', 'register_rest_route', 'wp_schedule', 'wp cron', 'staged_upload', 'upload_resumable') as $needle) {
    $assert(
        ! str_contains($entrypoint_surface, strtolower($needle)),
        'R43 exposed a premature WordPress upload entry point: ' . $needle
    );
}

$assert(
    ! str_contains(strtolower($service), 'wp_schedule')
    && ! str_contains(strtolower($service), 'wp_cron'),
    'R43 staged-upload executor schedules automatic/background replay.'
);

fwrite(STDOUT, "PeerTube staged upload executable-boundary tests passed.\n");
