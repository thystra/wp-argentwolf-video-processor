<?php
/**
 * Isolated PeerTube-shaped endpoint for the R44 remote-asset reconciliation smoke.
 *
 * The request log contains only reviewed scenario markers. It never records
 * form values, OAuth-client material, passwords, OTPs, or returned tokens.
 */

declare(strict_types=1);

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
$target = (string) ($_SERVER['REQUEST_URI'] ?? '');
$request_log = '/awvp-state/requests.log';

$append_log = static function (string $line) use ($request_log): bool {
    return false !== file_put_contents($request_log, $line . "\n", FILE_APPEND | LOCK_EX);
};

$json = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Encoding: identity');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
};

$problem = static function (int $status, string $code = ''): void {
    http_response_code($status);
    header('Content-Type: application/problem+json; charset=utf-8');
    header('Content-Encoding: identity');
    header('Cache-Control: no-store');

    $payload = array(
        'type'   => 'about:blank',
        'title'  => 'Fixture request failed',
        'status' => $status,
    );
    if ('' !== $code) {
        $payload['code'] = $code;
    }
    echo json_encode($payload, JSON_THROW_ON_ERROR);
};

if ('GET' === $method && '/health' === $target) {
    $pid = getmypid();
    if (! function_exists('posix_kill') || ! is_int($pid) || $pid < 2) {
        http_response_code(500);
        echo "transport termination unavailable\n";
        return;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo "ready\n";
    return;
}

$reject = static function (string $reason) use ($append_log, $problem): void {
    $append_log('REJECT ' . $reason);
    $problem(400);
};

$accept = trim((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
$accept_encoding = strtolower(trim((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? '')));
$user_agent = (string) ($_SERVER['HTTP_USER_AGENT'] ?? '');
$cookie = (string) ($_SERVER['HTTP_COOKIE'] ?? '');
$host = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? '');
$otp = (string) ($_SERVER['HTTP_X_PEERTUBE_OTP'] ?? '');
$content_type = strtolower(trim((string) ($_SERVER['CONTENT_TYPE'] ?? '')));
$body = file_get_contents('php://input');

if (
    'peertube.test:9000' !== $host
    || 'application/json, application/problem+json' !== $accept
    || 'identity' !== $accept_encoding
    || 1 !== preg_match('/^ArgentWolf-Video-Processor\/[0-9A-Za-z.-]+; WordPress$/D', $user_agent)
    || '' !== $cookie
    || ! is_string($body)
) {
    $reject('common-metadata');
    return;
}

if ('GET' === $method && '/api/v1/oauth-clients/local' === $target) {
    if ('' !== $authorization || '' !== $otp || '' !== $body) {
        $reject('oauth-authority');
        return;
    }
    if (! $append_log('GET /api/v1/oauth-clients/local auth=none body=none')) {
        $problem(500);
        return;
    }

    $json(
        array(
            'client_id'     => 'r37-oauth-client-id',
            'client_secret' => 'r37-oauth-client-secret-canary',
        )
    );
    return;
}

if ('POST' === $method && '/api/v1/users/token' === $target) {
    $parsed = array();
    parse_str($body, $parsed);
    $username = is_string($parsed['username'] ?? null) ? $parsed['username'] : '';
    $passwords = array(
        'r37-success-user-canary'   => 'r37-success-password-canary',
        'r37-otp-user-canary'       => 'r37-otp-password-canary',
        'r37-transport-user-canary' => 'r37-transport-password-canary',
    );
    $expected = array(
        'client_id'     => 'r37-oauth-client-id',
        'client_secret' => 'r37-oauth-client-secret-canary',
        'username'      => $username,
        'password'      => $passwords[$username] ?? '',
        'response_type' => 'code',
        'grant_type'    => 'password',
        'scope'         => 'upload',
    );
    $expected_body = http_build_query($expected, '', '&', PHP_QUERY_RFC3986);

    if (
        '' !== $authorization
        || 'application/x-www-form-urlencoded' !== $content_type
        || ! isset($passwords[$username])
        || $expected !== $parsed
        || $expected_body !== $body
    ) {
        $reject('token-form-or-authority');
        return;
    }

    if ('r37-success-user-canary' === $username) {
        if ('' !== $otp) {
            $reject('success-otp');
            return;
        }
        if (! $append_log('POST /api/v1/users/token scenario=success otp=none form=password')) {
            $problem(500);
            return;
        }
        $json(
            array(
                'token_type'               => 'Bearer',
                'access_token'             => 'r37-success-access-token-canary',
                'refresh_token'            => 'r37-success-refresh-token-canary',
                'expires_in'               => 3600,
                'refresh_token_expires_in' => 7200,
            )
        );
        return;
    }

    if ('r37-otp-user-canary' === $username) {
        if ('' === $otp) {
            if (! $append_log('POST /api/v1/users/token scenario=otp-required otp=none form=password')) {
                $problem(500);
                return;
            }
            header('X-PeerTube-OTP: required');
            $problem(401, 'invalid_grant');
            return;
        }
        if ('731946' !== $otp) {
            $reject('otp-value');
            return;
        }
        if (! $append_log('POST /api/v1/users/token scenario=otp-success otp=valid form=password')) {
            $problem(500);
            return;
        }
        $json(
            array(
                'token_type'               => 'Bearer',
                'access_token'             => 'r37-otp-access-token-canary',
                'refresh_token'            => 'r37-otp-refresh-token-canary',
                'expires_in'               => 3600,
                'refresh_token_expires_in' => 7200,
            )
        );
        return;
    }

    if ('' !== $otp) {
        $reject('transport-otp');
        return;
    }
    if (! $append_log('POST /api/v1/users/token scenario=transport-drop otp=none form=password')) {
        $problem(500);
        return;
    }

    // Terminate the isolated mock only after the reviewed request marker is
    // durable. WordPress must classify the resulting dropped connection as an
    // uncertain transport outcome; all later fixture steps are local-only.
    $pid = getmypid();
    if (! is_int($pid) || $pid < 2 || ! posix_kill($pid, 9)) {
        $problem(500);
    }
    return;
}

if ('POST' === $method && '/api/v1/videos/upload-resumable' === $target) {
    $upload_length = (string) ($_SERVER['HTTP_X_UPLOAD_CONTENT_LENGTH'] ?? '');
    $upload_type = strtolower(trim((string) ($_SERVER['HTTP_X_UPLOAD_CONTENT_TYPE'] ?? '')));
    $decoded = json_decode($body, true);
    $expected = array(
        'channelId' => 101,
        'name'      => 'R43 staged source',
        'privacy'   => 3,
        'filename'  => 'r43-source.mp4',
    );
    if (
        'Bearer r37-success-access-token-canary' !== $authorization
        || '' !== $otp
        || 'application/json' !== $content_type
        || '16' !== $upload_length
        || 'video/mp4' !== $upload_type
        || ! is_array($decoded)
        || $expected !== $decoded
    ) {
        $reject('upload-init-contract');
        return;
    }
    if (! $append_log('POST /api/v1/videos/upload-resumable auth=bearer privacy=private bytes=16 body=metadata')) {
        $problem(500);
        return;
    }
    http_response_code(201);
    header('Content-Encoding: identity');
    header('Cache-Control: no-store');
    header('Location: /api/v1/videos/upload-resumable?upload_id=r43fixture0001');
    return;
}

if ('PUT' === $method && 1 === preg_match('#^/api/v1/videos/upload-resumable\?upload_id=([^&]+)$#D', $target, $upload_matches)) {
    $content_range = trim((string) ($_SERVER['HTTP_CONTENT_RANGE'] ?? ''));
    $content_length = trim((string) ($_SERVER['CONTENT_LENGTH'] ?? ''));
    if (
        'r43fixture0001' !== rawurldecode($upload_matches[1])
        || 'Bearer r37-success-access-token-canary' !== $authorization
        || '' !== $otp
        || 'video/mp4' !== $content_type
        || 'bytes 0-15/16' !== $content_range
        || '16' !== $content_length
        || 'R43-STAGED-BYTES' !== $body
    ) {
        $reject('upload-chunk-contract');
        return;
    }
    if (! $append_log(
        'PUT /api/v1/videos/upload-resumable upload_id=r43fixture0001 auth=bearer range=bytes=0-15/16 bytes=16 body_sha256=' . hash('sha256', $body)
    )) {
        $problem(500);
        return;
    }
    $json(
        array(
            'video' => array(
                'id' => 901,
                'uuid' => '12345678-1234-4abc-9def-1234567890ab',
                'name' => 'must-not-be-persisted',
            ),
        )
    );
    return;
}

if ('GET' === $method && '/api/v1/videos/12345678-1234-4abc-9def-1234567890ab' === $target) {
    if ('Bearer r37-success-access-token-canary' !== $authorization || '' !== $otp || '' !== $body) {
        $reject('remote-video-authority');
        return;
    }
    $prior = is_file($request_log) ? (string) file_get_contents($request_log) : '';
    $already = substr_count($prior, 'GET /api/v1/videos/12345678-1234-4abc-9def-1234567890ab ');
    $state_id = 0 === $already ? 2 : 1;
    $state_name = 2 === $state_id ? 'processing' : 'ready';
    if (! $append_log('GET /api/v1/videos/12345678-1234-4abc-9def-1234567890ab auth=bearer state=' . $state_name . ' body=none')) {
        $problem(500);
        return;
    }
    $json(array(
        'id' => 901,
        'uuid' => '12345678-1234-4abc-9def-1234567890ab',
        'state' => array('id' => $state_id, 'label' => 2 === $state_id ? 'To transcode' : 'Published'),
        'privacy' => array('id' => 3, 'label' => 'Private'),
        'channel' => array('id' => 101, 'name' => 'channel_101'),
        'embedPath' => '/videos/embed/12345678-1234-4abc-9def-1234567890ab',
        'isLive' => false,
        'isLocal' => true,
        'description' => 'must-not-be-persisted',
    ));
    return;
}

if ('GET' === $method && '/api/v1/users/me' === $target) {
    if (
        'Bearer r37-success-access-token-canary' !== $authorization
        || '' !== $otp
        || '' !== $body
    ) {
        $reject('identity-authority');
        return;
    }
    if (! $append_log('GET /api/v1/users/me auth=bearer body=none')) {
        $problem(500);
        return;
    }
    $json(
        array(
            'id'       => 17,
            'username' => 'awvp_service',
            'blocked'  => false,
            'account'  => array('id' => 23, 'name' => 'awvp_service'),
        )
    );
    return;
}

if (
    'GET' === $method
    && 1 === preg_match(
        '#^/api/v1/accounts/awvp_service/video-channels\?start=(0|100)&count=100&sort=id$#D',
        $target,
        $matches
    )
) {
    if ('' !== $authorization || '' !== $otp || '' !== $body) {
        $reject('channels-authority');
        return;
    }

    $start = (int) $matches[1];
    if (! $append_log(
        'GET /api/v1/accounts/awvp_service/video-channels start=' . $start
            . ' count=100 sort=id auth=none body=none'
    )) {
        $problem(500);
        return;
    }

    $channels = array();
    $end = 0 === $start ? 100 : 101;
    for ($id = $start + 1; $id <= $end; $id++) {
        $channels[] = array(
            'id'           => $id,
            'name'         => 'channel_' . str_pad((string) $id, 3, '0', STR_PAD_LEFT),
            'displayName'  => 'Owned Channel ' . $id,
            'ownerAccount' => array('id' => 23),
            'isLocal'      => true,
        );
    }

    $json(array('total' => 101, 'data' => $channels));
    return;
}

$append_log('REJECT route');
$problem('GET' === $method ? 404 : 405);

// EOF: tests/fixtures/peertube-password-grant-smoke/mock-router.php
