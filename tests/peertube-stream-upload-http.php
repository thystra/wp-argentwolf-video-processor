<?php
/** Focused WordPress HTTP/cURL hook test for streamed PeerTube upload slices. */
declare(strict_types=1);

$GLOBALS['awvp_stream_basedir'] = sys_get_temp_dir() . '/awvp-stream-http-' . getmypid();
$GLOBALS['awvp_stream_actions'] = array();
$GLOBALS['awvp_stream_curl'] = array();
$GLOBALS['awvp_stream_request'] = null;

foreach (
    array(
        'CURLOPT_UPLOAD' => 46,
        'CURLOPT_CUSTOMREQUEST' => 10036,
        'CURLOPT_READFUNCTION' => 20012,
        'CURLOPT_INFILESIZE_LARGE' => 30115,
    ) as $constant => $value
) {
    if (! defined($constant)) {
        define($constant, $value);
    }
}

function wp_upload_dir(): array
{
    return array(
        'basedir' => $GLOBALS['awvp_stream_basedir'],
        'baseurl' => 'https://example.test/wp-content/uploads',
        'error' => false,
    );
}

function wp_normalize_path(string $path): string
{
    return str_replace('\\', '/', $path);
}

/** @return array<string,mixed>|int|string|false|null */
function wp_parse_url(string $url, int $component = -1): array|int|string|false|null
{
    return -1 === $component ? parse_url($url) : parse_url($url, $component);
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): bool
{
    $GLOBALS['awvp_stream_actions'][$hook][$priority][] = array($callback, $accepted_args);
    return true;
}

function remove_action(string $hook, callable $callback, int $priority = 10): bool
{
    if (! isset($GLOBALS['awvp_stream_actions'][$hook][$priority])) {
        return false;
    }
    foreach ($GLOBALS['awvp_stream_actions'][$hook][$priority] as $index => $entry) {
        if ($entry[0] === $callback) {
            unset($GLOBALS['awvp_stream_actions'][$hook][$priority][$index]);
            return true;
        }
    }
    return false;
}

function awvp_stream_capture_curl_option(mixed $handle, int $option, mixed $value): bool
{
    if (! is_object($handle)) {
        return false;
    }
    $key = spl_object_id($handle);
    $GLOBALS['awvp_stream_curl'][$key][$option] = $value;
    return true;
}

// Some dependency-free CI environments do not load ext-curl. Supply only the
// global symbols that are actually absent; never redeclare native cURL
// functions when the extension is loaded.
if (! function_exists('curl_init')) {
    function curl_init(): object
    {
        return (object) array('id' => bin2hex(random_bytes(4)));
    }
}

if (! function_exists('curl_exec')) {
    function curl_exec(mixed $handle): string|bool
    {
        unset($handle);
        return true;
    }
}

if (! function_exists('curl_setopt')) {
    function curl_setopt(mixed $handle, int $option, mixed $value): bool
    {
        return awvp_stream_capture_curl_option($handle, $option, $value);
    }
}

// PeerTube_Http_Client is namespaced. Override its unqualified curl_setopt()
// call so this focused test can capture CURLOPT_* configuration even when the
// native ext-curl implementation exists.
if (! function_exists('ArgentVideo\\curl_setopt')) {
    eval(<<<'PHP'
namespace ArgentVideo;

function curl_setopt(mixed $handle, int $option, mixed $value): bool
{
    return \awvp_stream_capture_curl_option($handle, $option, $value);
}
PHP);
}

function wp_safe_remote_request(string $url, array $args): array
{
    $handle = curl_init();
    foreach ($GLOBALS['awvp_stream_actions']['http_api_curl'][10] ?? array() as [$callback, $accepted_args]) {
        unset($accepted_args);
        $callback($handle, $args, $url);
    }

    $options = $GLOBALS['awvp_stream_curl'][spl_object_id($handle)] ?? array();
    $read = $options[CURLOPT_READFUNCTION] ?? null;
    $length = (int) ($options[CURLOPT_INFILESIZE_LARGE] ?? 0);
    $body = '';
    if (is_callable($read)) {
        while (strlen($body) < $length) {
            $piece = $read($handle, null, min(16384, $length - strlen($body)));
            if (! is_string($piece) || '' === $piece) {
                break;
            }
            $body .= $piece;
        }
    }

    $GLOBALS['awvp_stream_request'] = array(
        'url' => $url,
        'args' => $args,
        'curl' => $options,
        'body' => $body,
    );

    return array(
        'response' => array('code' => 308),
        'headers' => array('range' => 'bytes=0-' . max(0, $length - 1)),
        'body' => '',
    );
}

function is_wp_error(mixed $value): bool
{
    unset($value);
    return false;
}

function wp_remote_retrieve_response_code(array $response): int
{
    return (int) ($response['response']['code'] ?? 0);
}

function wp_remote_retrieve_body(array $response): string
{
    return (string) ($response['body'] ?? '');
}

function wp_remote_retrieve_header(array $response, string $name): string|array
{
    foreach (($response['headers'] ?? array()) as $key => $value) {
        if (strtolower((string) $key) === strtolower($name)) {
            return $value;
        }
    }
    return '';
}

require_once dirname(__DIR__) . '/includes/Storage.php';
require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Api_Error.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Source_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Upload_Slice.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Upload_Runtime_Budget.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Http_Client.php';

use ArgentVideo\PeerTube_Http_Client;
use ArgentVideo\PeerTube_Staged_Source_Identity;
use ArgentVideo\PeerTube_Upload_Slice;
use ArgentVideo\Storage;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$directory = Storage::root() . '/77/staging';
if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
    throw new RuntimeException('Could not create stream HTTP fixture directory.');
}
$path = $directory . '/stream.mp4';
$contents = str_repeat('0123456789abcdef', 8192);
$assert(strlen($contents) === file_put_contents($path, $contents), 'Could not write stream HTTP fixture.');
$identity = PeerTube_Staged_Source_Identity::capture($path);
$assert(is_array($identity), 'Could not capture stream HTTP fixture identity.');
$slice = PeerTube_Upload_Slice::open($identity, 1024, 65536);
$assert($slice instanceof PeerTube_Upload_Slice, 'Could not open stream HTTP slice.');

$client = new PeerTube_Http_Client('https://video.example.org');
$result = $client->put_resumable_upload_slice(
    'stream-token-canary',
    'stream-session-0001',
    strlen($contents),
    'video/mp4',
    $slice
);
$assert(true === ($result['ok'] ?? false) && 308 === ($result['http_status'] ?? 0), 'Streamed PUT did not return the accepted 308 transport result.');
$request = $GLOBALS['awvp_stream_request'];
$assert(is_array($request), 'Streamed PUT did not reach the WordPress HTTP boundary.');
$assert('PUT' === ($request['args']['method'] ?? null), 'Streamed request did not retain PUT method.');
$assert(3600 === ($request['args']['timeout'] ?? null), 'Streamed request did not receive the bounded long-transfer timeout.');
$assert(! array_key_exists('body', $request['args']), 'Streamed request materialized the source as a WordPress HTTP body string.');
$assert('65536' === ($request['args']['headers']['Content-Length'] ?? null), 'Streamed Content-Length drifted.');
$assert(
    'bytes 1024-66559/' . strlen($contents) === ($request['args']['headers']['Content-Range'] ?? null),
    'Streamed Content-Range drifted.'
);
$assert(substr($contents, 1024, 65536) === ($request['body'] ?? null), 'cURL read callback streamed the wrong file slice.');
$assert(true === ($request['curl'][CURLOPT_UPLOAD] ?? null), 'cURL upload mode was not enabled.');
$assert('PUT' === ($request['curl'][CURLOPT_CUSTOMREQUEST] ?? null), 'cURL custom request drifted from PUT.');
$assert(65536 === ($request['curl'][CURLOPT_INFILESIZE_LARGE] ?? null), 'cURL upload length drifted.');
$assert($slice->complete() && $slice->verify_unchanged(), 'Streamed HTTP path did not leave a verifiably complete slice.');
$assert(empty(array_filter($GLOBALS['awvp_stream_actions']['http_api_curl'][10] ?? array())), 'Temporary cURL streaming hook was not removed after the request.');
$slice->close();

@unlink($path);
@rmdir($directory);
@rmdir(dirname($directory));
@rmdir(Storage::root());
@rmdir($GLOBALS['awvp_stream_basedir']);

fwrite(STDOUT, "PeerTube streamed HTTP upload tests passed.\n");
