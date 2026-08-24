<?php
/**
 * Isolated PeerTube-shaped endpoint for the R34 authenticated API smoke.
 *
 * The fixture validates request authority before returning bounded responses.
 * Its request log records only reviewed pass markers and never records the
 * password, OTP, OAuth client secret, or bearer/refresh tokens.
 */

declare(strict_types=1);

$method = (string) ($_SERVER['REQUEST_METHOD'] ?? '');
$target = (string) ($_SERVER['REQUEST_URI'] ?? '');
$request_log = '/awvp-state/requests.log';

$append_log = static function (string $line) use ($request_log): bool {
    return false !== file_put_contents($request_log, $line . "\n", FILE_APPEND | LOCK_EX);
};

$problem = static function (int $status, string $title, string $code = '', string $detail = ''): void {
    http_response_code($status);
    header('Content-Type: application/problem+json; charset=utf-8');
    header('Content-Encoding: identity');

    $payload = array(
        'type'   => '' === $code
            ? 'about:blank'
            : 'https://docs.joinpeertube.org/api-rest-reference.html#section/Errors/' . $code,
        'title'  => $title,
        'status' => $status,
    );
    if ('' !== $code) {
        $payload['code'] = $code;
    }
    if ('' !== $detail) {
        $payload['detail'] = $detail;
    }

    echo json_encode($payload, JSON_THROW_ON_ERROR);
};

$json = static function (array $payload, int $status = 200): void {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Encoding: identity');
    header('Cache-Control: no-store');
    echo json_encode($payload, JSON_THROW_ON_ERROR);
};

if ('GET' === $method && '/health' === $target) {
    if (! $append_log('GET /health')) {
        http_response_code(500);
        echo "request log unavailable\n";
        return;
    }

    header('Content-Type: text/plain; charset=utf-8');
    echo "ready\n";
    return;
}

$reject = static function (string $reason) use ($append_log, $problem): void {
    $append_log('REJECT ' . $reason);
    $problem(400, 'Unexpected request metadata');
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
        $reject('oauth-client-authority');
        return;
    }

    if (! $append_log('GET /api/v1/oauth-clients/local auth=none body=none')) {
        $problem(500, 'Request log unavailable');
        return;
    }

    $json(
        array(
            'client_id'     => str_repeat('c', 32),
            'client_secret' => str_repeat('S', 32),
            'unknown'       => 'RAW_OAUTH_CLIENT_FIELD',
        )
    );
    return;
}

if ('POST' === $method && '/api/v1/users/token' === $target) {
    $expected_form = array(
        'client_id'     => str_repeat('c', 32),
        'client_secret' => str_repeat('S', 32),
        'username'      => 'awvp-fixture-user',
        'password'      => 'AWVP fixture password only!',
        'response_type' => 'code',
        'grant_type'    => 'password',
        'scope'         => 'upload',
    );
    $parsed_form = array();
    parse_str($body, $parsed_form);
    $expected_body = http_build_query($expected_form, '', '&', PHP_QUERY_RFC3986);

    if (
        '' !== $authorization
        || 'application/x-www-form-urlencoded' !== $content_type
        || $expected_body !== $body
        || $expected_form !== $parsed_form
    ) {
        $reject('token-form-or-authority');
        return;
    }

    if ('' === $otp) {
        if (! $append_log('POST /api/v1/users/token auth=none otp=none form=password response-otp=required-app')) {
            $problem(500, 'Request log unavailable');
            return;
        }

        header('x-peertube-otp: required; app');
        // Deliberately omit the problem code so the client must classify the
        // response from PeerTube's exact OTP-required response header.
        $problem(401, 'Unauthorized', '', 'Fixture credential failure detail must not escape.');
        return;
    }

    if ('654321' !== $otp) {
        $reject('token-otp');
        return;
    }

    if (! $append_log('POST /api/v1/users/token auth=none otp=valid form=password')) {
        $problem(500, 'Request log unavailable');
        return;
    }

    header('Pragma: no-cache');
    $json(
        array(
            'token_type'              => 'Bearer',
            'access_token'            => 'awvp-r34-access-token',
            'refresh_token'           => 'awvp-r34-refresh-token',
            'expires_in'              => 3600,
            'refresh_token_expires_in' => 2419200,
            'unknown'                 => 'RAW_TOKEN_FIELD',
        )
    );
    return;
}

if ('GET' === $method && '/api/v1/users/me' === $target) {
    if ('Bearer awvp-r34-access-token' !== $authorization || '' !== $otp || '' !== $body) {
        $reject('identity-authority');
        return;
    }

    if (! $append_log('GET /api/v1/users/me auth=bearer body=none')) {
        $problem(500, 'Request log unavailable');
        return;
    }

    $json(
        array(
            'id'       => 17,
            'username' => 'awvp-fixture-user',
            'blocked'  => false,
            'email'    => 'RAW_IDENTITY_FIELD@example.invalid',
            'account'  => array(
                'id'          => 42,
                'name'        => 'awvp-fixture-user',
                'displayName' => 'AWVP Fixture User',
                'host'        => 'peertube.test:9000',
            ),
        )
    );
    return;
}

$first_channel_target = '/api/v1/accounts/awvp-fixture-user/video-channels?start=0&count=100&sort=id';
$second_channel_target = '/api/v1/accounts/awvp-fixture-user/video-channels?start=100&count=100&sort=id';
if ('GET' === $method && in_array($target, array($first_channel_target, $second_channel_target), true)) {
    if ('' !== $authorization || '' !== $otp || '' !== $body) {
        $reject('channel-authority');
        return;
    }

    $start = $first_channel_target === $target ? 1 : 101;
    $end = 1 === $start ? 100 : 101;
    $channels = array();
    for ($id = $start; $id <= $end; ++$id) {
        $channels[] = array(
            'id'           => $id,
            'name'         => sprintf('channel-%03d', $id),
            'displayName'  => sprintf('Fixture Channel %03d', $id),
            'isLocal'      => true,
            'ownerAccount' => array(
                'id'   => 42,
                'name' => 'awvp-fixture-user',
                'host' => 'peertube.test:9000',
            ),
            'unknown' => 'RAW_CHANNEL_FIELD',
        );
    }

    if (! $append_log('GET ' . $target . ' auth=none body=none')) {
        $problem(500, 'Request log unavailable');
        return;
    }

    $json(array('total' => 101, 'data' => $channels));
    return;
}

$append_log('REJECT route');
$problem('GET' === $method ? 404 : 405, 'Route not found');

// EOF: tests/fixtures/peertube-auth-api-smoke/mock-router.php
