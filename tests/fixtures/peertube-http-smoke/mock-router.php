<?php
/**
 * Isolated PeerTube-shaped endpoint for the R33 WordPress HTTP smoke test.
 *
 * This fixture is repository-only test material. It deliberately implements
 * only a readiness route and the public instance-configuration route used by
 * the current read-only API-client checkpoint.
 */

declare(strict_types=1);

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
$target = (string) ($_SERVER['REQUEST_URI'] ?? '');
$request_log = '/awvp-state/requests.log';

if (false === file_put_contents($request_log, $method . ' ' . $target . "\n", FILE_APPEND | LOCK_EX)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "request log unavailable\n";
    return;
}

if ('GET' === $method && '/health' === $target) {
    header('Content-Type: text/plain; charset=utf-8');
    echo "ready\n";
    return;
}

if ('GET' !== $method) {
    http_response_code(405);
    header('Allow: GET');
    header('Content-Type: application/problem+json; charset=utf-8');
    echo json_encode(
        array(
            'type'   => 'about:blank',
            'title'  => 'Method not allowed',
            'status' => 405,
        ),
        JSON_THROW_ON_ERROR
    );
    return;
}

if ('/api/v1/config' !== $target) {
    http_response_code(404);
    header('Content-Type: application/problem+json; charset=utf-8');
    echo json_encode(
        array(
            'type'   => 'about:blank',
            'title'  => 'Not found',
            'status' => 404,
        ),
        JSON_THROW_ON_ERROR
    );
    return;
}

$accept_encoding = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')));
$user_agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$content_length = (string) ($_SERVER['CONTENT_LENGTH'] ?? '');
if (
    'identity' !== $accept_encoding
    || 1 !== preg_match('/^ArgentWolf-Video-Processor\/[0-9A-Za-z.-]+; WordPress$/D', $user_agent)
    || '' !== (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '')
    || '' !== (string) ($_SERVER['HTTP_COOKIE'] ?? '')
    || ('' !== $content_length && '0' !== $content_length)
) {
    http_response_code(400);
    header('Content-Type: application/problem+json; charset=utf-8');
    echo json_encode(
        array(
            'type'   => 'about:blank',
            'title'  => 'Unexpected request metadata',
            'status' => 400,
        ),
        JSON_THROW_ON_ERROR
    );
    return;
}

$payload = array(
    'serverVersion' => '8.1.8',
    'instance'      => array(
        'name'             => 'AWVP isolated PeerTube fixture',
        'shortDescription' => 'RAW_UNKNOWN_MARKER must not leave the API boundary.',
        'administrator'    => 'fixture-admin@example.invalid',
    ),
    'transcoding'   => array(
        'hls'        => array(
            'enabled' => true,
            'profile' => 'RAW_UNKNOWN_MARKER',
        ),
        'web_videos' => array(
            'enabled' => false,
        ),
    ),
    'unknown'       => array(
        'secret' => 'RAW_UNKNOWN_MARKER',
        'blob'   => str_repeat('fixture-data-', 512),
    ),
);

header('Content-Type: application/json; charset=utf-8');
header('Content-Encoding: identity');
header('Cache-Control: no-store');
echo json_encode($payload, JSON_THROW_ON_ERROR);

// EOF: tests/fixtures/peertube-http-smoke/mock-router.php
