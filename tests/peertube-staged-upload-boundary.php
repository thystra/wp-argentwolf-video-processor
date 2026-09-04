<?php
/**
 * R43/R45 regression boundary: resumable-upload primitives are executable only
 * through the explicit one-shot PeerTube WP-CLI task worker. Browser/admin,
 * cron/REST/AJAX entry points and capability advertisement stay off.
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
$cli = (string) file_get_contents($root . '/includes/CLI_Command.php');
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

// R45.3b adds one explicit CLI-only construction path. The service remains
// unreachable from browser/admin/cron/REST/AJAX entry points.
$assert(
    str_contains($loader, "PeerTube_Staged_Upload_Service.php"),
    'R43 staged-upload executor is not loadable.'
);
$wp_cli_guard = strpos($plugin, "if (defined('WP_CLI') && WP_CLI)");
$upload_build = strpos($plugin, '$peertube_upload = new PeerTube_Staged_Upload_Service(');
$assert(
    false !== $wp_cli_guard && false !== $upload_build && $upload_build > $wp_cli_guard,
    'R45.3b staged-upload service is not composed strictly behind the WP_CLI guard.'
);
$assert(
    ! str_contains($admin, 'PeerTube_Staged_Upload_Service'),
    'Staged-upload executor leaked into PeerTube admin actions.'
);
$assert(
    str_contains($cli, 'public function peertube_task_worker(')
        && ! str_contains($cli, 'PeerTube_Staged_Upload_Service'),
    'PeerTube CLI boundary bypasses the task worker and directly owns R43 upload execution.'
);

$entrypoint_surface = strtolower($admin);
foreach (array('wp_ajax', 'register_rest_route', 'wp_schedule', 'wp cron', 'staged_upload', 'upload_resumable') as $needle) {
    $assert(
        ! str_contains($entrypoint_surface, strtolower($needle)),
        'R43 exposed a browser/admin upload entry point: ' . $needle
    );
}
foreach (array('register_rest_route', 'wp_ajax', 'admin_post_argentwolf_video_processor_peertube_upload') as $needle) {
    $assert(
        ! str_contains(strtolower($plugin), strtolower($needle)),
        'R45.3b Plugin exposed an unreviewed staged-upload entry point: ' . $needle
    );
}

$assert(
    ! str_contains(strtolower($service), 'wp_schedule')
    && ! str_contains(strtolower($service), 'wp_cron'),
    'R43 staged-upload executor schedules automatic/background replay.'
);

fwrite(STDOUT, "PeerTube staged upload executable-boundary tests passed.\n");
