<?php
/**
 * Real-WordPress assertions for the R33 PeerTube detection checkpoint.
 *
 * Executed by WP-CLI with the plugin activated. No HTTP functions are stubbed:
 * PeerTube_Http_Client reaches the isolated fixture through
 * wp_safe_remote_request().
 */

declare(strict_types=1);

use ArgentVideo\PeerTube_Api_Client;
use ArgentVideo\PeerTube_Http_Client;
use ArgentVideo\PeerTube_Origin;

if (! defined('ABSPATH')) {
    fwrite(STDERR, "ABSPATH is unavailable.\n");
    exit(1);
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';

$fail = static function (string $message): never {
    fwrite(STDERR, 'PEERTUBE_HTTP_SMOKE_ASSERTION_FAILED: ' . $message . "\n");
    exit(1);
};

$origin = 'http://peertube.test:9000';

if (! is_plugin_active('argentwolf-video-processor/argentwolf-video-processor.php')) {
    $fail('The plugin is not active.');
}

if (! defined('WP_DEBUG') || true !== WP_DEBUG) {
    $fail('WP_DEBUG is not enabled.');
}

if (! defined('WP_HTTP_BLOCK_EXTERNAL') || true !== WP_HTTP_BLOCK_EXTERNAL) {
    $fail('WP_HTTP_BLOCK_EXTERNAL is not enabled.');
}

if (! defined('WP_ACCESSIBLE_HOSTS') || 'peertube.test' !== WP_ACCESSIBLE_HOSTS) {
    $fail('WP_ACCESSIBLE_HOSTS is not the exact isolated fixture hostname.');
}

if (! defined('ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS')) {
    $fail('The development-origin allowlist is unavailable.');
}

$development_origins = constant('ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS');
if (array($origin) !== $development_origins) {
    $fail('The development-origin allowlist is not the exact fixture origin.');
}

if ($origin !== PeerTube_Origin::sanitize($origin)) {
    $fail('The exact fixture origin was not accepted by the origin policy.');
}

if (! function_exists('wp_safe_remote_request')) {
    $fail('The real WordPress safe HTTP API is unavailable.');
}

if (1048576 !== PeerTube_Http_Client::MAX_METADATA_RESPONSE_BYTES) {
    $fail('The reviewed response-size ceiling changed unexpectedly.');
}

$client = new PeerTube_Api_Client(new PeerTube_Http_Client($origin));
$result = $client->detect_instance();

if (true !== ($result['ok'] ?? null)) {
    $fail('Instance detection failed: ' . wp_json_encode($result));
}

$expected = array(
    'origin'                => $origin,
    'server_version'        => '8.1.8',
    'instance_name'         => 'AWVP isolated PeerTube fixture',
    'transcoding_hls'       => true,
    'transcoding_web_video' => false,
);

if ($expected !== ($result['data'] ?? null)) {
    $fail('The normalized instance result differs from the reviewed field allowlist.');
}

if (array('ok', 'data', 'error') !== array_keys($result)) {
    $fail('The API result exposed an unexpected top-level field.');
}

if (5 !== count($result['data'])) {
    $fail('The normalized instance result exposed an unexpected data field.');
}

$encoded = wp_json_encode($result);
if (! is_string($encoded)) {
    $fail('The normalized result could not be encoded.');
}

if (str_contains($encoded, 'RAW_UNKNOWN_MARKER') || str_contains($encoded, 'fixture-admin@example.invalid')) {
    $fail('An unreviewed raw response field crossed the API boundary.');
}

if (strlen($encoded) > 512) {
    $fail('The normalized result exceeded its focused smoke-test output bound.');
}

echo "PEERTUBE_HTTP_WORDPRESS_ASSERTIONS=PASS\n";

// EOF: tests/fixtures/peertube-http-smoke/assert-detect.php
