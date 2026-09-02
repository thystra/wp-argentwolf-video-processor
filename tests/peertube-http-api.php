<?php
/**
 * Focused dependency-free tests for the bounded PeerTube HTTP/API boundary.
 */

declare(strict_types=1);

namespace {
    define('ARGENT_VIDEO_VERSION', 'test');
    define(
        'ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS',
        array('http://127.0.0.1:9000', 'http://peertube.test:9000')
    );

    $GLOBALS['awvp_http_filters'] = array();
    $GLOBALS['awvp_http_requests'] = array();
    $GLOBALS['awvp_http_responses'] = array();
    $GLOBALS['awvp_http_throw'] = false;
    $GLOBALS['awvp_add_filter_throw_hook'] = '';

    final class WP_Error
    {
        public function __construct(
            private readonly string $code,
            private readonly string $message
        ) {
        }

        public function get_error_code(): string
        {
            return $this->code;
        }

        public function get_error_message(): string
        {
            return $this->message;
        }
    }

    function wp_parse_url(string $url): array|false
    {
        $parsed = parse_url($url);
        return is_array($parsed) ? $parsed : false;
    }

    function add_filter(
        string $hook,
        callable $callback,
        int $priority = 10,
        int $accepted_args = 1
    ): bool {
        $GLOBALS['awvp_http_filters'][$hook][$priority][] = array($callback, $accepted_args);
        if ($hook === $GLOBALS['awvp_add_filter_throw_hook']) {
            throw new \RuntimeException('Synthetic filter registration exception.');
        }
        return true;
    }

    function remove_filter(string $hook, callable $callback, int $priority = 10): bool
    {
        $filters = $GLOBALS['awvp_http_filters'][$hook][$priority] ?? array();
        foreach ($filters as $index => $registered) {
            if ($registered[0] === $callback) {
                unset($GLOBALS['awvp_http_filters'][$hook][$priority][$index]);
                if ([] === $GLOBALS['awvp_http_filters'][$hook][$priority]) {
                    unset($GLOBALS['awvp_http_filters'][$hook][$priority]);
                }
                if ([] === ($GLOBALS['awvp_http_filters'][$hook] ?? array())) {
                    unset($GLOBALS['awvp_http_filters'][$hook]);
                }
                return true;
            }
        }

        return false;
    }

    function awvp_apply_filter(string $hook, mixed $value, mixed ...$args): mixed
    {
        $priorities = $GLOBALS['awvp_http_filters'][$hook] ?? array();
        ksort($priorities);

        foreach ($priorities as $filters) {
            foreach ($filters as [$callback, $accepted_args]) {
                $arguments = array_slice(array_merge(array($value), $args), 0, $accepted_args);
                $value = $callback(...$arguments);
            }
        }

        return $value;
    }

    /** @param array<string, mixed> $args */
    function wp_safe_remote_request(string $url, array $args = array()): array|WP_Error
    {
        $parts = wp_parse_url($url);
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        $external = awvp_apply_filter('http_request_host_is_external', false, $host, $url);
        $ports = awvp_apply_filter('http_allowed_safe_ports', array(80, 443, 8080), $host, $url);
        $other_external = awvp_apply_filter(
            'http_request_host_is_external',
            false,
            '169.254.169.254',
            'http://169.254.169.254/api/v1/config'
        );
        $other_ports = awvp_apply_filter(
            'http_allowed_safe_ports',
            array(80, 443, 8080),
            'other.example.org',
            'https://other.example.org:9000/api/v1/config'
        );

        $GLOBALS['awvp_http_requests'][] = array(
            'url'            => $url,
            'args'           => $args,
            'external'       => $external,
            'ports'          => $ports,
            'other_external' => $other_external,
            'other_ports'    => $other_ports,
        );

        if ($GLOBALS['awvp_http_throw']) {
            throw new \RuntimeException('Synthetic transport exception.');
        }

        if ([] === $GLOBALS['awvp_http_responses']) {
            return new WP_Error('http_request_failed', 'No synthetic response was queued.');
        }

        return array_shift($GLOBALS['awvp_http_responses']);
    }

    function is_wp_error(mixed $value): bool
    {
        return $value instanceof WP_Error;
    }

    /** @param array<string, mixed>|WP_Error $response */
    function wp_remote_retrieve_response_code(array|WP_Error $response): int
    {
        return is_array($response) && is_int($response['response']['code'] ?? null)
            ? $response['response']['code']
            : 0;
    }

    /** @param array<string, mixed>|WP_Error $response */
    function wp_remote_retrieve_body(array|WP_Error $response): string
    {
        return is_array($response) && is_string($response['body'] ?? null)
            ? $response['body']
            : '';
    }

    /** @param array<string, mixed>|WP_Error $response */
    function wp_remote_retrieve_header(array|WP_Error $response, string $wanted): array|string
    {
        if (! is_array($response) || ! is_array($response['headers'] ?? null)) {
            return '';
        }

        foreach ($response['headers'] as $name => $value) {
            if (is_string($name) && strtolower($name) === strtolower($wanted)) {
                return is_array($value) || is_string($value) ? $value : '';
            }
        }

        return '';
    }
}

namespace ArgentVideo {
    require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Api_Error.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Http_Client.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Password_Grant_Api.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Identity_Destination_Api.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Token_Lifecycle_Api.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Api_Client.php';

    $assert = static function (bool $condition, string $message): void {
        if (! $condition) {
            throw new \RuntimeException($message);
        }
    };

    /** @param array<string, mixed>|\WP_Error $response */
    $queue = static function (array|\WP_Error $response): void {
        $GLOBALS['awvp_http_responses'][] = $response;
    };

    /** @param array<string, mixed> $headers
     *  @return array<string, mixed>
     */
    $response = static function (int $status, string $body, array $headers = array()): array {
        return array(
            'response' => array('code' => $status),
            'headers'  => $headers,
            'body'     => $body,
        );
    };

    foreach (
        array(
            'HTTPS://video.example.org',
            'https://video.example.org/',
            'https://video.example.org/api/v1',
            'http://video.example.org',
            'https://127.0.0.1:9000',
        ) as $invalid_origin
    ) {
        $rejected = false;
        try {
            new PeerTube_Http_Client($invalid_origin);
        } catch (\InvalidArgumentException) {
            $rejected = true;
        }
        $assert($rejected, 'HTTP client accepted invalid/noncanonical origin: ' . $invalid_origin);
    }

    $http = new PeerTube_Http_Client('https://video.example.org');
    $api = new PeerTube_Api_Client($http);

    foreach (
        array(
            'https://attacker.example/api/v1/config',
            '/api/v1/config?redirect=https://attacker.example',
            '/api/v1/../config',
            '/api/v1/oauth-clients/local',
        ) as $invalid_path
    ) {
        $before = count($GLOBALS['awvp_http_requests']);
        $rejected = false;
        try {
            $http->get($invalid_path);
        } catch (\InvalidArgumentException) {
            $rejected = true;
        }
        $assert($rejected, 'HTTP client accepted unreviewed path: ' . $invalid_path);
        $assert(
            $before === count($GLOBALS['awvp_http_requests']),
            'Rejected HTTP path performed a request: ' . $invalid_path
        );
    }

    $before_unreviewed_endpoint_input = count($GLOBALS['awvp_http_requests']);
    foreach (
        array(
            static fn (): array => $http->get_account_channels('user@remote.example', 0, 100),
            static fn (): array => $http->post_password_token(
                array(
                    'client_id'     => 'client-id',
                    'client_secret' => 'client-secret',
                    'username'      => 'upload-bot@example.org',
                    'password'      => 'fixture password',
                    'response_type' => 'code',
                    'grant_type'    => 'password',
                    'scope'         => 'upload',
                ),
                '12345a'
            ),
        ) as $unreviewed_endpoint_call
    ) {
        $rejected = false;
        try {
            $unreviewed_endpoint_call();
        } catch (\InvalidArgumentException) {
            $rejected = true;
        }
        $assert($rejected, 'HTTP client accepted unreviewed endpoint input.');
    }
    $assert(
        $before_unreviewed_endpoint_input === count($GLOBALS['awvp_http_requests']),
        'Rejected endpoint input performed an HTTP request.'
    );

    $queue(
        $response(
            200,
            json_encode(
                array(
                    'serverVersion' => '8.1.8',
                    'serverCommit'  => 'must-not-be-returned',
                    'instance'      => array(
                        'name'           => 'Example PeerTube',
                        'customizations' => array('javascript' => 'must-not-be-returned'),
                    ),
                    'transcoding' => array(
                        'hls'        => array('enabled' => true),
                        'web_videos' => array('enabled' => false),
                    ),
                    'unknown' => str_repeat('x', 1024),
                ),
                JSON_THROW_ON_ERROR
            ),
            array('Content-Type' => 'application/json; charset=utf-8')
        )
    );
    $detected = $api->detect_instance();
    $assert(true === $detected['ok'], 'Valid PeerTube config detection failed.');
    $assert('8.1.8' === $detected['data']['server_version'], 'Server version was not normalized.');
    $assert('Example PeerTube' === $detected['data']['instance_name'], 'Instance name was not normalized.');
    $assert(true === $detected['data']['transcoding_hls'], 'HLS observation was not normalized.');
    $assert(false === $detected['data']['transcoding_web_video'], 'Web-video observation was not normalized.');
    $assert(
        array(
            'origin',
            'server_version',
            'instance_name',
            'transcoding_hls',
            'transcoding_web_video',
        ) === array_keys($detected['data']),
        'Detection result exposed unreviewed raw config fields.'
    );

    $request = $GLOBALS['awvp_http_requests'][array_key_last($GLOBALS['awvp_http_requests'])];
    $assert(1 === count($GLOBALS['awvp_http_requests']), 'Detection performed an unexpected extra request.');
    $assert('https://video.example.org/api/v1/config' === $request['url'], 'Config request URL mismatch.');
    $assert('GET' === $request['args']['method'], 'Config request must use GET.');
    $assert(true === $request['args']['blocking'], 'Config request must be blocking.');
    $assert(0 === $request['args']['redirection'], 'Config request must disable redirects.');
    $assert(true === $request['args']['sslverify'], 'Config request must verify TLS.');
    $assert(true === $request['args']['reject_unsafe_urls'], 'Config request must retain safe-URL checks.');
    $assert(false === $request['args']['decompress'], 'Config request must not expand compressed remote data.');
    $assert(
        'identity' === $request['args']['headers']['Accept-Encoding'],
        'Config request must explicitly request an identity-encoded bounded body.'
    );
    $assert(
        'ArgentWolf-Video-Processor/test; WordPress' === $request['args']['headers']['User-Agent'],
        'Config request User-Agent mismatch.'
    );
    $assert(
        ! array_key_exists('Authorization', $request['args']['headers'])
        && ! array_key_exists('Cookie', $request['args']['headers'])
        && ! array_key_exists('cookies', $request['args']),
        'Public detection sent authentication or cookie state.'
    );
    $assert(
        PeerTube_Http_Client::MAX_METADATA_RESPONSE_BYTES === $request['args']['limit_response_size'],
        'Config response must carry the reviewed size bound.'
    );
    $assert(! array_key_exists('body', $request['args']), 'Public detection must send no request body.');

    $queue(
        $response(
            429,
            '{"type":"about:blank","code":"rate_limit","detail":"Try later"}',
            array(
                'Content-Type'          => 'application/problem+json',
                'Retry-After'           => '17',
                'X-RateLimit-Limit'     => '15',
                'X-RateLimit-Remaining' => '0',
                'X-RateLimit-Reset'     => '2000000000',
            )
        )
    );
    $limited = $api->detect_instance();
    $assert(false === $limited['ok'], '429 response must fail detection.');
    $assert('rate_limited' === $limited['error']['status'], '429 was not normalized to rate_limited.');
    $assert(17 === $limited['error']['retry_after'], 'Retry-After was not retained.');
    $assert(15 === $limited['error']['rate_limit'], 'Rate limit was not retained.');
    $assert(0 === $limited['error']['rate_remaining'], 'Rate remaining was not retained.');
    $assert(2000000000 === $limited['error']['rate_reset'], 'Rate reset was not retained.');

    $queue(
        $response(
            429,
            '{"code":"invalid_grant","detail":"rate limiter body is not authoritative"}',
            array('Retry-After' => '3')
        )
    );
    $status_authority = $api->detect_instance();
    $assert(
        'rate_limited' === $status_authority['error']['status'],
        '429 status was overridden by an untrusted body code.'
    );

    $queue(
        $response(
            429,
            str_repeat('x', PeerTube_Http_Client::MAX_METADATA_RESPONSE_BYTES),
            array('Retry-After' => '5')
        )
    );
    $oversized_rate_limit = $api->detect_instance();
    $assert(
        'rate_limited' === $oversized_rate_limit['error']['status'],
        'Truncated 429 body overrode the authoritative status.'
    );
    $assert(5 === $oversized_rate_limit['error']['retry_after'], 'Truncated 429 lost retry metadata.');
    $assert('' === $oversized_rate_limit['error']['detail'], 'Truncated error body was retained.');

    $queue($response(400, '{"code":"invalid_grant"}', array()));
    $invalid_grant = $api->detect_instance();
    $assert(
        'invalid_response' === $invalid_grant['error']['status'],
        'Public detection trusted a token-only invalid_grant code.'
    );

    $queue($response(401, '{"code":"missing_two_factor"}', array()));
    $otp_required = $api->detect_instance();
    $assert(
        'authentication_required' === $otp_required['error']['status'],
        'Public detection incorrectly treated a token-only OTP code as authoritative.'
    );

    $queue($response(400, '{"code":"invalid_two_factor"}', array()));
    $otp_invalid = $api->detect_instance();
    $assert(
        'invalid_response' === $otp_invalid['error']['status'],
        'Public detection incorrectly accepted an invalid-OTP body as endpoint authority.'
    );

    foreach (
        array(
            'account_blocked' => 'permission_denied',
            'email_not_verified' => 'permission_denied',
            'account_waiting_for_approval' => 'permission_denied',
            'account_approval_rejected' => 'permission_denied',
            'too_long_password' => 'authentication_required',
        ) as $official_token_code => $expected_token_status
    ) {
        $official_token_error = PeerTube_Api_Error::normalize(
            400,
            array(),
            json_encode(array('code' => $official_token_code), JSON_THROW_ON_ERROR),
            'token'
        );
        $assert(
            $expected_token_status === $official_token_error['status']
            && $official_token_code === $official_token_error['code'],
            'Official PeerTube token error mapping mismatch: ' . $official_token_code
        );
    }

    $queue(
        $response(
            429,
            '{"detail":"raw access_token should-not-escape"}',
            array('Content-Type' => 'application/problem+json')
        )
    );
    $secret_error = $api->detect_instance();
    $assert('' === $secret_error['error']['detail'], 'Secret-like error detail escaped normalization.');
    $assert(
        ! str_contains(serialize($secret_error), 'should-not-escape'),
        'Failed API result retained secret-like response material.'
    );

    $obfuscated_error = PeerTube_Api_Error::normalize(
        400,
        array(),
        "{\"type\":\"client_secret=type-leak\",\"code\":\"access_token=code-leak\","
            . "\"detail\":\"access_\\u0001token=detail-leak\"}"
    );
    $assert('' === $obfuscated_error['type'], 'Secret-like problem type escaped normalization.');
    $assert('' === $obfuscated_error['code'], 'Secret-like problem code escaped normalization.');
    $assert('' === $obfuscated_error['detail'], 'Control-obfuscated secret detail escaped normalization.');
    $assert(
        ! str_contains(serialize($obfuscated_error), '-leak'),
        'Normalized error retained secret-like remote fields.'
    );

    $format_obfuscated_error = PeerTube_Api_Error::normalize(
        400,
        array(),
        "{\"detail\":\"refresh_\\u200btoken=format-leak\"}"
    );
    $assert(
        '' === $format_obfuscated_error['detail'],
        'Unicode-format-obfuscated secret detail escaped normalization.'
    );

    $utf8_error = PeerTube_Api_Error::normalize(
        400,
        array(),
        json_encode(array('detail' => str_repeat('x', 511) . 'étail'), JSON_THROW_ON_ERROR)
    );
    $assert(1 === preg_match('//u', $utf8_error['detail']), 'Truncated detail is not valid UTF-8.');
    $assert(
        false !== json_encode($utf8_error, JSON_UNESCAPED_UNICODE),
        'Normalized UTF-8 detail cannot be encoded safely.'
    );

    $unreviewed_local_code = PeerTube_Api_Error::invalid_response('plausiblelowercasecredential');
    $assert(
        '' === $unreviewed_local_code['code'],
        'An unreviewed caller-provided value crossed the local diagnostic-code boundary.'
    );

    $queue(
        $response(
            302,
            '{"serverVersion":"8.1.8"}',
            array('Content-Type' => 'application/json', 'Location' => 'https://other.example/')
        )
    );
    $redirect = $api->detect_instance();
    $assert(false === $redirect['ok'], 'Redirect response must fail detection.');
    $assert(302 === $redirect['error']['http_status'], 'Redirect status was not retained.');
    $assert('invalid_response' === $redirect['error']['status'], 'Redirect did not fail as invalid_response.');

    foreach (
        array(
            $response(200, '{"serverVersion":"8.1.8"}', array('Content-Type' => 'text/html')),
            $response(200, '{broken', array('Content-Type' => 'application/json')),
            $response(200, '[{"serverVersion":"8.1.8"}]', array('Content-Type' => 'application/json')),
            $response(200, '{"instance":{"name":"Missing version"}}', array('Content-Type' => 'application/json')),
            $response(200, '{"serverVersion":"latest"}', array('Content-Type' => 'application/json')),
        ) as $invalid_response
    ) {
        $queue($invalid_response);
        $invalid = $api->detect_instance();
        $assert(false === $invalid['ok'], 'Malformed/unexpected config response passed detection.');
        $assert('invalid_response' === $invalid['error']['status'], 'Malformed config error state mismatch.');
    }

    $queue(
        $response(
            200,
            '{"serverVersion":"8.1.8"}',
            array('Content-Type' => 'application/json', 'Content-Encoding' => 'gzip')
        )
    );
    $encoded = $api->detect_instance();
    $assert(false === $encoded['ok'], 'Encoded response must fail detection.');
    $assert(
        'response_content_encoding_unsupported' === $encoded['error']['code'],
        'Encoded response failure code mismatch.'
    );

    foreach (
        array(
            str_repeat('é', 120),
            str_repeat('é', 121),
        ) as $index => $instance_name
    ) {
        $queue(
            $response(
                200,
                json_encode(
                    array('serverVersion' => '8.1.8', 'instance' => array('name' => $instance_name)),
                    JSON_THROW_ON_ERROR
                ),
                array('Content-Type' => 'application/json')
            )
        );
        $unicode_name = $api->detect_instance();
        $assert(true === $unicode_name['ok'], 'Unicode instance-name detection failed.');
        $expected_name = 0 === $index ? $instance_name : '';
        $assert(
            $expected_name === $unicode_name['data']['instance_name'],
            'Unicode instance-name character limit was not deterministic.'
        );
    }

    $queue(
        $response(
            200,
            str_repeat('x', PeerTube_Http_Client::MAX_METADATA_RESPONSE_BYTES),
            array('Content-Type' => 'application/json')
        )
    );
    $oversized = $api->detect_instance();
    $assert(false === $oversized['ok'], 'Oversized response must fail detection.');
    $assert('response_too_large' === $oversized['error']['code'], 'Oversized response code mismatch.');

    $queue(new \WP_Error('http_request_failed', 'SSL certificate verification failed.'));
    $tls = $api->detect_instance();
    $assert(false === $tls['ok'], 'Transport error must fail detection.');
    $assert('tls_error' === $tls['error']['status'], 'TLS transport error was not classified.');
    $assert('' === $tls['error']['detail'], 'Transport error must not expose raw diagnostic text.');

    $transport_secret_code = PeerTube_Api_Error::transport(
        new \WP_Error('access_token=transport-code-sentinel', 'Synthetic transport failure.')
    );
    $assert('' === $transport_secret_code['code'], 'Secret-like transport error code escaped normalization.');
    $assert(
        ! str_contains(serialize($transport_secret_code), 'transport-code-sentinel'),
        'Normalized transport error retained a secret-like WP_Error code.'
    );

    // Authenticated PeerTube primitives remain explicit, bounded, and
    // persistence-free. The OAuth client is ephemeral input to password_token.
    $oauth_secret_sentinel = 'x9Q2m7C4v8N6p3R5';
    $queue(
        $response(
            200,
            json_encode(
                array(
                    'client_id' => 'awvp-client-id',
                    'client_secret' => $oauth_secret_sentinel,
                    'ignored' => 'raw',
                ),
                JSON_THROW_ON_ERROR
            ),
            array('Content-Type' => 'application/json')
        )
    );
    $oauth_client = $api->local_oauth_client();
    $assert(true === $oauth_client['ok'], 'Valid local OAuth client response failed.');
    $assert(
        array('client_id', 'client_secret') === array_keys($oauth_client['data']),
        'OAuth client response exposed unreviewed fields.'
    );
    $oauth_request = $GLOBALS['awvp_http_requests'][array_key_last($GLOBALS['awvp_http_requests'])];
    $assert(
        'https://video.example.org/api/v1/oauth-clients/local' === $oauth_request['url'],
        'OAuth-client request URL mismatch.'
    );
    $assert('GET' === $oauth_request['args']['method'], 'OAuth-client request must use GET.');
    $assert(! isset($oauth_request['args']['body']), 'OAuth-client request must be bodyless.');
    $assert(
        ! isset($oauth_request['args']['headers']['Authorization'])
        && ! isset($oauth_request['args']['cookies']),
        'Public OAuth-client request sent authentication/cookies.'
    );

    $password_sentinel = 'q7V9m2K4-random-value';
    $before_password = count($GLOBALS['awvp_http_requests']);
    $queue(
        $response(
            401,
            json_encode(
                array(
                    'code' => 'invalid_grant',
                    'type' => $password_sentinel,
                    'detail' => $password_sentinel . ' ' . $oauth_secret_sentinel,
                ),
                JSON_THROW_ON_ERROR
            ),
            array('X-PeerTube-OTP' => 'required; app', 'Content-Type' => 'application/problem+json')
        )
    );
    $missing_otp = $api->password_token(
        $oauth_client['data'],
        'upload-bot@example.org',
        $password_sentinel,
        '',
        2000000000
    );
    $assert(false === $missing_otp['ok'], 'Missing OTP unexpectedly produced a token.');
    $assert('otp_required' === $missing_otp['error']['status'], 'OTP-required response header lost authority.');
    $assert(
        '' === $missing_otp['error']['type'] && '' === $missing_otp['error']['detail'],
        'Credential-bearing token error retained untrusted textual diagnostics.'
    );
    $assert(
        $before_password + 1 === count($GLOBALS['awvp_http_requests']),
        'Password grant retried or performed an extra request.'
    );
    $password_request = $GLOBALS['awvp_http_requests'][array_key_last($GLOBALS['awvp_http_requests'])];
    parse_str((string) $password_request['args']['body'], $password_form);
    $assert(
        array(
            'client_id'     => 'awvp-client-id',
            'client_secret' => $oauth_secret_sentinel,
            'username'      => 'upload-bot@example.org',
            'password'      => $password_sentinel,
            'response_type' => 'code',
            'grant_type'    => 'password',
            'scope'         => 'upload',
        ) === $password_form,
        'Password grant form did not match the reviewed PeerTube contract.'
    );
    $assert('POST' === $password_request['args']['method'], 'Password grant must use POST.');
    $assert(
        'application/x-www-form-urlencoded' === $password_request['args']['headers']['Content-Type'],
        'Password grant content type mismatch.'
    );
    $assert(
        ! isset($password_request['args']['headers']['Authorization'])
        && ! isset($password_request['args']['headers']['x-peertube-otp']),
        'Initial password grant sent bearer/OTP state.'
    );
    $assert(
        ! str_contains(serialize($missing_otp), $password_sentinel)
        && ! str_contains(serialize($missing_otp), $oauth_secret_sentinel),
        'Failed password result retained caller credentials.'
    );

    $queue($response(400, '{"code":"invalid_grant"}', array()));
    $wrong_password = $api->password_token(
        $oauth_client['data'],
        'upload-bot@example.org',
        'wrong-password',
        '',
        2000000000
    );
    $assert(
        'authentication_required' === $wrong_password['error']['status'],
        'Wrong-password response was incorrectly classified as OTP-required.'
    );

    $access_sentinel = 'a8F3k9L2m7N4q6R1';
    $refresh_sentinel = 'r6P2v8J4n9C3w7T5';
    $queue(
        $response(
            200,
            json_encode(
                array(
                    'token_type'               => 'Bearer',
                    'access_token'             => $access_sentinel,
                    'refresh_token'            => $refresh_sentinel,
                    'expires_in'               => 3600,
                    'refresh_token_expires_in' => 2419200,
                    'password'                 => 'unreviewed-response-field',
                ),
                JSON_THROW_ON_ERROR
            ),
            array('Content-Type' => 'application/json', 'Cache-Control' => 'no-store')
        )
    );
    $token = $api->password_token(
        $oauth_client['data'],
        'upload-bot@example.org',
        $password_sentinel,
        '123456',
        2000000000
    );
    $assert(true === $token['ok'], 'Valid password/OTP token exchange failed.');
    $assert(
        array(
            'access_token'       => $access_sentinel,
            'refresh_token'      => $refresh_sentinel,
            'access_expires_at'  => 2000003600,
            'refresh_expires_at' => 2002419200,
        ) === $token['data'],
        'Token response was not minimally/absolutely normalized.'
    );
    $token_request = $GLOBALS['awvp_http_requests'][array_key_last($GLOBALS['awvp_http_requests'])];
    $assert(
        '123456' === $token_request['args']['headers']['x-peertube-otp'],
        'OTP was not confined to the reviewed request header.'
    );
    $assert(
        ! str_contains(serialize($token), $password_sentinel)
        && ! str_contains(serialize($token), 'unreviewed-response-field'),
        'Successful token result retained password/unreviewed response fields.'
    );

    $before_invalid_otp = count($GLOBALS['awvp_http_requests']);
    $invalid_otp_input = $api->password_token(
        $oauth_client['data'],
        'upload-bot@example.org',
        $password_sentinel,
        "12345\r\nInjected: yes",
        2000000000
    );
    $assert(false === $invalid_otp_input['ok'], 'Unsafe OTP input was accepted.');
    $assert(
        'password_token_input_invalid' === $invalid_otp_input['error']['code'],
        'Reviewed local password-token input code was erased.'
    );
    $assert(
        $before_invalid_otp === count($GLOBALS['awvp_http_requests']),
        'Unsafe OTP input performed an HTTP request.'
    );

    foreach (
        array(
            array(
                'token_type'               => 'bearer',
                'access_token'             => 'lowercase-type-access',
                'refresh_token'            => 'lowercase-type-refresh',
                'expires_in'               => 3600,
                'refresh_token_expires_in' => 7200,
            ),
            array(
                'token_type'               => 'Bearer',
                'access_token'             => 'overflow-access-sentinel',
                'refresh_token'            => 'overflow-refresh-sentinel',
                'expires_in'               => 315576001,
                'refresh_token_expires_in' => 7200,
            ),
            array(
                'token_type'               => 'Bearer',
                'access_token'             => 'skew-boundary-access',
                'refresh_token'            => 'skew-boundary-refresh',
                'expires_in'               => 60,
                'refresh_token_expires_in' => 7200,
            ),
        ) as $invalid_token_body
    ) {
        $queue(
            $response(
                200,
                json_encode($invalid_token_body, JSON_THROW_ON_ERROR),
                array('Content-Type' => 'application/json')
            )
        );
        $invalid_token = $api->password_token(
            $oauth_client['data'],
            'upload-bot@example.org',
            $password_sentinel,
            '',
            2000000000
        );
        $assert(false === $invalid_token['ok'], 'Malformed token response passed normalization.');
        $assert(null === $invalid_token['data'], 'Malformed token response retained secret data.');
        $assert(
            ! str_contains(serialize($invalid_token), (string) $invalid_token_body['access_token'])
            && ! str_contains(serialize($invalid_token), (string) $invalid_token_body['refresh_token']),
            'Malformed token error retained response token material.'
        );
    }

    $queue(
        $response(
            200,
            json_encode(
                array(
                    'token_type'               => 'Bearer',
                    'access_token'             => 'usable-boundary-access',
                    'refresh_token'            => 'usable-boundary-refresh',
                    'expires_in'               => 61,
                    'refresh_token_expires_in' => 61,
                ),
                JSON_THROW_ON_ERROR
            ),
            array('Content-Type' => 'application/json')
        )
    );
    $usable_boundary = $api->password_token(
        $oauth_client['data'],
        'upload-bot@example.org',
        $password_sentinel,
        '',
        2000000000
    );
    $assert(
        true === $usable_boundary['ok']
        && 2000000061 === $usable_boundary['data']['access_expires_at']
        && 2000000061 === $usable_boundary['data']['refresh_expires_at'],
        'The first lifetime beyond the 60-second skew margin was rejected.'
    );

    $queue(
        $response(
            200,
            json_encode(
                array(
                    'token_type'               => 'Bearer',
                    'access_token'             => 'integer-overflow-access',
                    'refresh_token'            => 'integer-overflow-refresh',
                    'expires_in'               => 3600,
                    'refresh_token_expires_in' => 7200,
                ),
                JSON_THROW_ON_ERROR
            ),
            array('Content-Type' => 'application/json')
        )
    );
    $integer_overflow = $api->password_token(
        $oauth_client['data'],
        'upload-bot@example.org',
        $password_sentinel,
        '',
        PHP_INT_MAX - 100
    );
    $assert(false === $integer_overflow['ok'], 'Token expiry addition overflow was accepted.');
    $assert(
        ! str_contains(serialize($integer_overflow), 'integer-overflow-access')
        && ! str_contains(serialize($integer_overflow), 'integer-overflow-refresh'),
        'Overflow failure retained token material.'
    );

    $before_rate_limit = count($GLOBALS['awvp_http_requests']);
    $queue(
        $response(
            429,
            '{"code":"rate_limit"}',
            array('Retry-After' => '9', 'Content-Type' => 'application/problem+json')
        )
    );
    $token_limited = $api->password_token(
        $oauth_client['data'],
        'upload-bot@example.org',
        $password_sentinel,
        '',
        2000000000
    );
    $assert('rate_limited' === $token_limited['error']['status'], 'Token 429 classification failed.');
    $assert(9 === $token_limited['error']['retry_after'], 'Token Retry-After was not retained.');
    $assert(
        $before_rate_limit + 1 === count($GLOBALS['awvp_http_requests']),
        'Rate-limited token request was retried.'
    );

    $identity_body = array(
        'id'       => 7,
        'username' => 'upload-bot',
        'blocked'  => false,
        'email'    => 'must-not-be-returned@example.org',
        'account'  => array(
            'id'          => 11,
            'name'        => 'upload-bot',
            'displayName' => 'Upload Bot',
        ),
        'videoChannels' => array(array('id' => 99, 'name' => 'unreviewed-inline-channel')),
    );
    $queue_identity = static function () use ($queue, $response, $identity_body): void {
        $queue(
            $response(
                200,
                json_encode($identity_body, JSON_THROW_ON_ERROR),
                array('Content-Type' => 'application/json')
            )
        );
    };
    $queue(
        $response(
            200,
            json_encode($identity_body, JSON_THROW_ON_ERROR),
            array('Content-Type' => 'application/json')
        )
    );
    $identity = $api->current_identity($access_sentinel);
    $assert(true === $identity['ok'], 'Valid authenticated identity failed.');
    $assert(
        array(
            'user_id'      => '7',
            'username'     => 'upload-bot',
            'account_id'   => '11',
            'account_name' => 'upload-bot',
        ) === $identity['data'],
        'Authenticated identity was not minimally normalized.'
    );
    $identity_request = $GLOBALS['awvp_http_requests'][array_key_last($GLOBALS['awvp_http_requests'])];
    $assert(
        'https://video.example.org/api/v1/users/me' === $identity_request['url']
        && 'GET' === $identity_request['args']['method'],
        'Current-identity request endpoint/method mismatch.'
    );
    $assert(
        'Bearer ' . $access_sentinel === $identity_request['args']['headers']['Authorization'],
        'Current-identity bearer header mismatch.'
    );
    $assert(! isset($identity_request['args']['body']), 'Current-identity request must be bodyless.');
    $assert(
        ! str_contains(serialize($identity), $access_sentinel)
        && ! str_contains(serialize($identity), 'must-not-be-returned@example.org'),
        'Identity result retained bearer/unreviewed personal fields.'
    );

    $before_bearer_injection = count($GLOBALS['awvp_http_requests']);
    $bearer_injection = $api->current_identity("token\r\nX-Injected: yes");
    $assert(false === $bearer_injection['ok'], 'Unsafe bearer token input was accepted.');
    $assert(
        'access_token_input_invalid' === $bearer_injection['error']['code'],
        'Reviewed local access-token input code was erased.'
    );
    $assert(
        $before_bearer_injection === count($GLOBALS['awvp_http_requests']),
        'Unsafe bearer token input performed an HTTP request.'
    );

    $queue(
        $response(
            401,
            json_encode(
                array(
                    'code' => 'missing_two_factor',
                    'type' => $access_sentinel,
                    'detail' => $access_sentinel,
                ),
                JSON_THROW_ON_ERROR
            ),
            array('X-PeerTube-OTP' => 'required; app', 'Content-Type' => 'application/problem+json')
        )
    );
    $bearer_error = $api->current_identity($access_sentinel);
    $assert(
        'authentication_required' === $bearer_error['error']['status'],
        'Bearer endpoint incorrectly accepted token-only OTP response authority.'
    );
    $assert(
        '' === $bearer_error['error']['code']
        && '' === $bearer_error['error']['type']
        && '' === $bearer_error['error']['detail']
        && ! str_contains(serialize($bearer_error), $access_sentinel),
        'Bearer endpoint error retained an opaque credential echo.'
    );

    $blocked_identity_body = $identity_body;
    $blocked_identity_body['blocked'] = true;
    $queue(
        $response(
            200,
            json_encode($blocked_identity_body, JSON_THROW_ON_ERROR),
            array('Content-Type' => 'application/json')
        )
    );
    $blocked_identity = $api->current_identity($access_sentinel);
    $assert(false === $blocked_identity['ok'], 'Blocked PeerTube identity was accepted.');
    $assert('identity_blocked' === $blocked_identity['error']['code'], 'Blocked identity code mismatch.');

    $queue(
        $response(
            200,
            '[' . json_encode($identity_body, JSON_THROW_ON_ERROR) . ']',
            array('Content-Type' => 'application/json')
        )
    );
    $array_identity = $api->current_identity($access_sentinel);
    $assert(false === $array_identity['ok'], 'OpenAPI-stale array identity response was accepted.');

    $boundary_identity_body = $identity_body;
    $boundary_identity_body['username'] = str_repeat('a', 50);
    $boundary_identity_body['account']['name'] = str_repeat('a', 50);
    $queue(
        $response(
            200,
            json_encode($boundary_identity_body, JSON_THROW_ON_ERROR),
            array('Content-Type' => 'application/json')
        )
    );
    $boundary_identity = $api->current_identity($access_sentinel);
    $assert(true === $boundary_identity['ok'], 'A valid 50-character PeerTube machine name was rejected.');

    foreach (
        array(
            str_repeat('a', 51),
            'upload-bot@remote.example',
            'Upload-Bot',
            "upload\u{202E}bot",
        ) as $hostile_machine_name
    ) {
        $hostile_identity_body = $identity_body;
        $hostile_identity_body['username'] = $hostile_machine_name;
        $hostile_identity_body['account']['name'] = $hostile_machine_name;
        $queue(
            $response(
                200,
                json_encode($hostile_identity_body, JSON_THROW_ON_ERROR),
                array('Content-Type' => 'application/json')
            )
        );
        $hostile_identity = $api->current_identity($access_sentinel);
        $assert(false === $hostile_identity['ok'], 'Accepted a noncanonical PeerTube machine name.');
        $assert(
            'identity_shape_invalid' === $hostile_identity['error']['code'],
            'Noncanonical PeerTube machine name returned the wrong diagnostic.'
        );
    }

    $channel = static function (int $id, int $owner_id = 11, bool $is_local = true): array {
        return array(
            'id'           => $id,
            'name'         => 'channel-' . $id,
            'displayName'  => 'Channel ' . $id,
            'isLocal'      => $is_local,
            'ownerAccount' => array('id' => $owner_id, 'name' => 'upload-bot'),
            'unknown'      => array('access_token' => 'remote-field-must-not-return'),
        );
    };
    $page_one = array();
    for ($channel_id = 1; $channel_id <= 100; $channel_id++) {
        $page_one[] = $channel($channel_id);
    }
    $queue_identity();
    $queue(
        $response(
            200,
            json_encode(array('total' => 101, 'data' => $page_one), JSON_THROW_ON_ERROR),
            array('Content-Type' => 'application/json')
        )
    );
    $queue(
        $response(
            200,
            json_encode(array('total' => 101, 'data' => array($channel(101))), JSON_THROW_ON_ERROR),
            array('Content-Type' => 'application/json')
        )
    );
    $before_channels = count($GLOBALS['awvp_http_requests']);
    $channels = $api->owned_channels($access_sentinel);
    $assert(true === $channels['ok'], 'Valid owned-channel pagination failed.');
    $assert(101 === count($channels['data']['channels']), 'Owned-channel pagination count mismatch.');
    $assert(
        array(
            'id'           => '1',
            'name'         => 'channel-1',
            'display_name' => 'Channel 1',
            'authority'    => 'owned',
        ) === $channels['data']['channels'][0],
        'Owned channel was not minimally normalized.'
    );
    $assert(
        ! str_contains(serialize($channels), 'remote-field-must-not-return'),
        'Owned-channel result retained an unreviewed remote field.'
    );
    $assert(
        $before_channels + 3 === count($GLOBALS['awvp_http_requests']),
        'Owned-channel discovery used an unexpected page/request count.'
    );
    $channel_identity_request = $GLOBALS['awvp_http_requests'][$before_channels];
    $assert(
        'https://video.example.org/api/v1/users/me' === $channel_identity_request['url']
        && 'Bearer ' . $access_sentinel === $channel_identity_request['args']['headers']['Authorization'],
        'Owned-channel discovery did not establish fresh bearer-bound identity authority.'
    );
    $channel_requests = array_slice($GLOBALS['awvp_http_requests'], -2);
    $assert(
        'https://video.example.org/api/v1/accounts/upload-bot/video-channels?start=0&count=100&sort=id'
            === $channel_requests[0]['url']
        && 'https://video.example.org/api/v1/accounts/upload-bot/video-channels?start=100&count=100&sort=id'
            === $channel_requests[1]['url'],
        'Owned-channel request path/query was not deterministic.'
    );
    foreach ($channel_requests as $channel_request) {
        $assert(
            ! isset($channel_request['args']['headers']['Authorization'])
            && ! isset($channel_request['args']['body']),
            'Public account-channel request leaked bearer/body state.'
        );
        $assert(
            PeerTube_Http_Client::MAX_CHANNEL_RESPONSE_BYTES
                === $channel_request['args']['limit_response_size'],
            'Channel page did not use the reviewed 2 MiB response bound.'
        );
    }

    $before_injected_path = count($GLOBALS['awvp_http_requests']);
    $fabricated_identity_rejected = false;
    try {
        $api->owned_channels(
            array(
                'account_id' => '11',
                'account_name' => '../users/me?x=1',
            )
        );
    } catch (\TypeError) {
        $fabricated_identity_rejected = true;
    }
    $assert($fabricated_identity_rejected, 'Fabricated identity input was accepted as channel authority.');
    $assert(
        $before_injected_path === count($GLOBALS['awvp_http_requests']),
        'Fabricated identity input performed an HTTP request.'
    );

    $queue_identity();
    $queue(
        $response(
            200,
            '{"total":0,"data":[]}',
            array('Content-Type' => 'application/json')
        )
    );
    $no_channels = $api->owned_channels($access_sentinel);
    $assert(
        true === $no_channels['ok']
        && array('identity', 'channels') === array_keys($no_channels['data'])
        && array() === $no_channels['data']['channels'],
        'A valid empty owned-channel result was not represented explicitly.'
    );

    $queue_identity();
    $queue(
        $response(
            200,
            json_encode(array('total' => 101, 'data' => array($channel(1))), JSON_THROW_ON_ERROR),
            array('Content-Type' => 'application/json')
        )
    );
    $before_underfilled_page = count($GLOBALS['awvp_http_requests']);
    $underfilled_page = $api->owned_channels($access_sentinel);
    $assert(false === $underfilled_page['ok'], 'An underfilled non-final channel page was accepted.');
    $assert(
        'channel_list_incomplete' === $underfilled_page['error']['code'],
        'Underfilled channel page returned the wrong diagnostic.'
    );
    $assert(
        $before_underfilled_page + 2 === count($GLOBALS['awvp_http_requests']),
        'Underfilled channel page triggered another remotely controlled pagination request.'
    );

    foreach (
        array(
            array($channel(1, 12, true), 'mismatched owner'),
            array($channel(1, 11, false), 'remote channel'),
        ) as [$unauthorized_channel, $description]
    ) {
        $queue_identity();
        $queue(
            $response(
                200,
                json_encode(array('total' => 1, 'data' => array($unauthorized_channel)), JSON_THROW_ON_ERROR),
                array('Content-Type' => 'application/json')
            )
        );
        $unauthorized = $api->owned_channels($access_sentinel);
        $assert(false === $unauthorized['ok'], 'Accepted unauthorized ' . $description . '.');
        $assert(
            'channel_authority_invalid' === $unauthorized['error']['code'],
            'Unauthorized-channel error code mismatch.'
        );
    }

    $queue_identity();
    $queue(
        $response(
            200,
            json_encode(array('total' => 2, 'data' => array($channel(1), $channel(1))), JSON_THROW_ON_ERROR),
            array('Content-Type' => 'application/json')
        )
    );
    $duplicate_channels = $api->owned_channels($access_sentinel);
    $assert(false === $duplicate_channels['ok'], 'Duplicate channel IDs were accepted.');

    $queue_identity();
    $queue(
        $response(
            200,
            json_encode(array('total' => 501, 'data' => array()), JSON_THROW_ON_ERROR),
            array('Content-Type' => 'application/json')
        )
    );
    $too_many_channels = $api->owned_channels($access_sentinel);
    $assert(false === $too_many_channels['ok'], 'Unbounded channel total was accepted.');

    $queue_identity();
    $queue(
        $response(
            200,
            str_repeat('x', PeerTube_Http_Client::MAX_CHANNEL_RESPONSE_BYTES),
            array('Content-Type' => 'application/json')
        )
    );
    $oversized_channels = $api->owned_channels($access_sentinel);
    $assert(false === $oversized_channels['ok'], 'Oversized channel page was accepted.');
    $assert(
        'response_too_large' === $oversized_channels['error']['code'],
        'Oversized channel-page error code mismatch.'
    );

    // R41 exact refresh-token and revoke-token contracts.
    $before_refresh = count($GLOBALS['awvp_http_requests']);
    $queue(
        $response(
            200,
            json_encode(
                array(
                    'token_type' => 'Bearer',
                    'access_token' => 'access-refresh-r41',
                    'refresh_token' => 'refresh-refresh-r41',
                    'expires_in' => 3600,
                    'refresh_token_expires_in' => 1209600,
                    'unreviewed' => 'must-not-escape',
                ),
                JSON_THROW_ON_ERROR
            ),
            array('Content-Type' => 'application/json')
        )
    );
    $refreshed = $api->refresh_token(
        array('client_id' => 'client-r41', 'client_secret' => 'client-secret-r41'),
        'refresh-old-r41',
        1700000000
    );
    $assert(true === $refreshed['ok'], 'Valid R41 refresh-token exchange failed.');
    $assert(
        array('access_token', 'refresh_token', 'access_expires_at', 'refresh_expires_at')
            === array_keys($refreshed['data']),
        'R41 refresh exposed unreviewed token-response fields.'
    );
    $refresh_request = $GLOBALS['awvp_http_requests'][array_key_last($GLOBALS['awvp_http_requests'])];
    $assert($before_refresh + 1 === count($GLOBALS['awvp_http_requests']), 'R41 refresh performed an extra HTTP request.');
    $assert('https://video.example.org/api/v1/users/token' === $refresh_request['url'], 'R41 refresh URL mismatch.');
    $assert('POST' === $refresh_request['args']['method'], 'R41 refresh must use POST.');
    $refresh_form = array();
    parse_str((string) $refresh_request['args']['body'], $refresh_form);
    $assert(
        array('client_id', 'client_secret', 'grant_type', 'refresh_token') === array_keys($refresh_form)
            && 'refresh_token' === $refresh_form['grant_type']
            && 'refresh-old-r41' === $refresh_form['refresh_token'],
        'R41 refresh form fields changed from the reviewed exact set.'
    );
    $assert(
        ! array_key_exists('Authorization', $refresh_request['args']['headers']),
        'R41 refresh unexpectedly sent bearer authority.'
    );

    $before_revoke = count($GLOBALS['awvp_http_requests']);
    $queue($response(200, '', array()));
    $revoked = $api->revoke_token('access-refresh-r41');
    $assert(true === $revoked['ok'] && true === $revoked['data']['revoked'], 'Valid R41 revoke failed.');
    $revoke_request = $GLOBALS['awvp_http_requests'][array_key_last($GLOBALS['awvp_http_requests'])];
    $assert($before_revoke + 1 === count($GLOBALS['awvp_http_requests']), 'R41 revoke performed an extra HTTP request.');
    $assert('https://video.example.org/api/v1/users/revoke-token' === $revoke_request['url'], 'R41 revoke URL mismatch.');
    $assert('POST' === $revoke_request['args']['method'], 'R41 revoke must use POST.');
    $assert('Bearer access-refresh-r41' === ($revoke_request['args']['headers']['Authorization'] ?? ''), 'R41 revoke bearer mismatch.');
    $assert('' === ($revoke_request['args']['body'] ?? null), 'R41 revoke must send an empty body.');

    $dev_http = new PeerTube_Http_Client('http://127.0.0.1:9000');
    $dev_api = new PeerTube_Api_Client($dev_http);
    $queue(
        $response(
            200,
            '{"serverVersion":"8.1.8","instance":{"name":"Development PeerTube"}}',
            array('Content-Type' => 'application/json')
        )
    );
    $dev_result = $dev_api->detect_instance();
    $assert(true === $dev_result['ok'], 'Exact configured development origin failed detection.');
    $dev_request = $GLOBALS['awvp_http_requests'][array_key_last($GLOBALS['awvp_http_requests'])];
    $assert(true === $dev_request['external'], 'Exact development origin host filter did not allow its request.');
    $assert(in_array(9000, $dev_request['ports'], true), 'Exact development port was not temporarily allowed.');
    $assert(false === $dev_request['other_external'], 'Development host filter relaxed another origin.');
    $assert(! in_array(9000, $dev_request['other_ports'], true), 'Development port filter relaxed another origin.');
    $assert([] === $GLOBALS['awvp_http_filters'], 'Development-origin filters remained after success.');

    $before_registration_failure = count($GLOBALS['awvp_http_requests']);
    $GLOBALS['awvp_add_filter_throw_hook'] = 'http_allowed_safe_ports';
    $registration_failure = $dev_api->detect_instance();
    $GLOBALS['awvp_add_filter_throw_hook'] = '';
    $assert(false === $registration_failure['ok'], 'Filter registration failure must fail detection.');
    $assert(
        $before_registration_failure === count($GLOBALS['awvp_http_requests']),
        'Filter registration failure performed an HTTP request.'
    );
    $assert([] === $GLOBALS['awvp_http_filters'], 'Filters remained after partial registration failure.');

    $GLOBALS['awvp_http_throw'] = true;
    $thrown = $dev_api->detect_instance();
    $GLOBALS['awvp_http_throw'] = false;
    $assert(false === $thrown['ok'], 'Thrown transport exception must fail detection.');
    $assert('transport_error' === $thrown['error']['status'], 'Thrown transport exception state mismatch.');
    $assert([] === $GLOBALS['awvp_http_filters'], 'Development-origin filters remained after exception.');

    $port_http = new PeerTube_Http_Client('https://video.example.org:8443');
    $port_api = new PeerTube_Api_Client($port_http);
    $queue(
        $response(
            200,
            '{"serverVersion":"8.1.8"}',
            array('Content-Type' => 'application/json')
        )
    );
    $port_result = $port_api->detect_instance();
    $assert(true === $port_result['ok'], 'Canonical HTTPS origin with explicit port failed detection.');
    $port_request = $GLOBALS['awvp_http_requests'][array_key_last($GLOBALS['awvp_http_requests'])];
    $assert(in_array(8443, $port_request['ports'], true), 'Exact configured HTTPS port was not temporarily allowed.');
    $assert(! in_array(8443, $port_request['other_ports'], true), 'HTTPS port filter relaxed another origin.');
    $assert([] === $GLOBALS['awvp_http_filters'], 'Configured-port filter remained after success.');

    echo "AWVP PeerTube bounded HTTP/API tests passed.\n";
}
