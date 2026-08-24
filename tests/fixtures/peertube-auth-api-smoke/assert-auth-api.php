<?php
/**
 * Real-WordPress assertions for the R34 authenticated PeerTube API primitives.
 *
 * Executed by WP-CLI with the plugin activated. No HTTP functions are stubbed:
 * PeerTube_Http_Client reaches only the isolated fixture through the real
 * WordPress safe HTTP API.
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
    fwrite(STDERR, 'PEERTUBE_AUTH_API_SMOKE_ASSERTION_FAILED: ' . $message . "\n");
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

if (
    1048576 !== PeerTube_Http_Client::MAX_METADATA_RESPONSE_BYTES
    || 2097152 !== PeerTube_Http_Client::MAX_CHANNEL_RESPONSE_BYTES
) {
    $fail('A reviewed response-size ceiling changed unexpectedly.');
}

global $wpdb;
if (! $wpdb instanceof wpdb) {
    $fail('The WordPress database connection is unavailable.');
}

$option_snapshot = static function () use ($wpdb, $fail): array {
    $legacy_prefix = $wpdb->esc_like('argent_video_') . '%';
    $canonical_prefix = $wpdb->esc_like('argentwolf_video_') . '%';
    $query = $wpdb->prepare(
        "SELECT option_name, option_value, autoload
         FROM {$wpdb->options}
         WHERE option_name LIKE %s OR option_name LIKE %s
         ORDER BY option_name ASC",
        $legacy_prefix,
        $canonical_prefix
    );
    $rows = $wpdb->get_results($query, ARRAY_A);
    if (! is_array($rows)) {
        $fail('Could not snapshot AWVP-owned options.');
    }

    return $rows;
};

$tree_snapshot = static function (string $root) use ($fail): array {
    if (! file_exists($root) && ! is_link($root)) {
        return array('exists' => false, 'entries' => array());
    }

    if (! is_dir($root) || is_link($root)) {
        $fail('The managed upload root is not a normal directory.');
    }

    $entries = array();
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $path => $info) {
        $relative = substr((string) $path, strlen($root) + 1);
        if (! is_string($relative) || '' === $relative) {
            $fail('Could not normalize a managed upload path.');
        }

        $entries[$relative] = array(
            'type' => $info->isLink() ? 'link' : ($info->isDir() ? 'directory' : 'file'),
            'size' => $info->isFile() ? $info->getSize() : 0,
        );
    }
    ksort($entries, SORT_STRING);

    return array('exists' => true, 'entries' => $entries);
};

$upload = wp_get_upload_dir();
if (! is_array($upload) || ! empty($upload['error']) || ! is_string($upload['basedir'] ?? null)) {
    $fail('The WordPress upload base is unavailable.');
}
$managed_upload_root = trailingslashit($upload['basedir']) . 'argentwolf-video-processor';

$options_before = $option_snapshot();
$uploads_before = $tree_snapshot($managed_upload_root);

$client = new PeerTube_Api_Client(new PeerTube_Http_Client($origin));
if ($origin !== $client->origin()) {
    $fail('The API client did not retain the exact canonical origin.');
}

$oauth_result = $client->local_oauth_client();
$expected_oauth = array(
    'client_id'     => str_repeat('c', 32),
    'client_secret' => str_repeat('S', 32),
);
if (
    array('ok', 'data', 'error') !== array_keys($oauth_result)
    || true !== ($oauth_result['ok'] ?? null)
    || $expected_oauth !== ($oauth_result['data'] ?? null)
    || null !== ($oauth_result['error'] ?? null)
) {
    $fail('The local OAuth-client response was not normalized to the exact reviewed fields.');
}

$received_at = 2000000000;
$missing_otp = $client->password_token(
    $expected_oauth,
    'awvp-fixture-user',
    'AWVP fixture password only!',
    '',
    $received_at
);
if (
    array('ok', 'data', 'error') !== array_keys($missing_otp)
    || false !== ($missing_otp['ok'] ?? null)
    || null !== ($missing_otp['data'] ?? null)
    || 'otp_required' !== ($missing_otp['error']['status'] ?? null)
    || 401 !== ($missing_otp['error']['http_status'] ?? null)
    || '' !== ($missing_otp['error']['code'] ?? null)
    || '' !== ($missing_otp['error']['detail'] ?? null)
) {
    $fail('The OTP-required header or credentialed-error redaction contract failed.');
}

$token_result = $client->password_token(
    $expected_oauth,
    'awvp-fixture-user',
    'AWVP fixture password only!',
    '654321',
    $received_at
);
$expected_token = array(
    'access_token'       => 'awvp-r34-access-token',
    'refresh_token'      => 'awvp-r34-refresh-token',
    'access_expires_at'  => 2000003600,
    'refresh_expires_at' => 2002419200,
);
if (
    array('ok', 'data', 'error') !== array_keys($token_result)
    || true !== ($token_result['ok'] ?? null)
    || $expected_token !== ($token_result['data'] ?? null)
    || null !== ($token_result['error'] ?? null)
) {
    $fail('The password-plus-OTP token response was not normalized exactly.');
}

$identity_result = $client->current_identity($expected_token['access_token']);
$expected_identity = array(
    'user_id'      => '17',
    'username'     => 'awvp-fixture-user',
    'account_id'   => '42',
    'account_name' => 'awvp-fixture-user',
);
if (
    array('ok', 'data', 'error') !== array_keys($identity_result)
    || true !== ($identity_result['ok'] ?? null)
    || $expected_identity !== ($identity_result['data'] ?? null)
    || null !== ($identity_result['error'] ?? null)
) {
    $fail('The bearer-authenticated current identity was not normalized exactly.');
}

$channels_result = $client->owned_channels($expected_token['access_token']);
if (
    array('ok', 'data', 'error') !== array_keys($channels_result)
    || true !== ($channels_result['ok'] ?? null)
    || null !== ($channels_result['error'] ?? null)
    || array('identity', 'channels') !== array_keys($channels_result['data'] ?? array())
    || $expected_identity !== ($channels_result['data']['identity'] ?? null)
    || ! is_array($channels_result['data']['channels'] ?? null)
    || 101 !== count($channels_result['data']['channels'])
) {
    $fail('The authenticated two-page owned-channel discovery result is invalid.');
}

$channels = $channels_result['data']['channels'];
foreach ($channels as $offset => $channel) {
    $id = $offset + 1;
    $expected_channel = array(
        'id'           => (string) $id,
        'name'         => sprintf('channel-%03d', $id),
        'display_name' => sprintf('Fixture Channel %03d', $id),
        'authority'    => 'owned',
    );
    if ($expected_channel !== $channel) {
        $fail('A normalized channel differs from the owner/local-bound allowlist.');
    }
}

$normalized = wp_json_encode(
    array(
        'oauth_keys'    => array_keys($oauth_result['data']),
        'token_keys'    => array_keys($token_result['data']),
        'identity'      => $identity_result['data'],
        'bound_identity' => $channels_result['data']['identity'],
        'first_channel' => $channels[0],
        'last_channel'  => $channels[100],
    )
);
if (! is_string($normalized)) {
    $fail('The normalized API projections could not be encoded.');
}
foreach (array('RAW_OAUTH_CLIENT_FIELD', 'RAW_TOKEN_FIELD', 'RAW_IDENTITY_FIELD', 'RAW_CHANNEL_FIELD') as $marker) {
    if (str_contains($normalized, $marker)) {
        $fail('An unreviewed raw response field crossed the API boundary.');
    }
}

if ($options_before !== $option_snapshot()) {
    $fail('An authenticated API primitive mutated an AWVP-owned option.');
}

if ($uploads_before !== $tree_snapshot($managed_upload_root)) {
    $fail('An authenticated API primitive mutated managed upload storage.');
}

echo "PEERTUBE_AUTH_API_OAUTH_CLIENT=PASS\n";
echo "PEERTUBE_AUTH_API_OTP_REQUIRED_HEADER=PASS\n";
echo "PEERTUBE_AUTH_API_PASSWORD_OTP_TOKEN=PASS\n";
echo "PEERTUBE_AUTH_API_CURRENT_IDENTITY=PASS\n";
echo "PEERTUBE_AUTH_API_OWNED_CHANNELS=101\n";
echo "PEERTUBE_AUTH_API_OPTION_PERSISTENCE=NONE\n";
echo "PEERTUBE_AUTH_API_MANAGED_UPLOAD_MUTATIONS=NONE\n";
echo "PEERTUBE_AUTH_API_WORDPRESS_ASSERTIONS=PASS\n";

// EOF: tests/fixtures/peertube-auth-api-smoke/assert-auth-api.php
