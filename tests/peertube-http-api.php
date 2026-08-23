<?php
/**
 * Focused dependency-free tests for the read-only PeerTube HTTP/API boundary.
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
        'authentication_required' === $invalid_grant['error']['status'],
        'PeerTube invalid_grant was not classified as requiring authentication.'
    );

    $queue($response(401, '{"code":"missing_two_factor"}', array()));
    $otp_required = $api->detect_instance();
    $assert('otp_required' === $otp_required['error']['status'], 'Missing OTP was not classified.');

    $queue($response(400, '{"code":"invalid_two_factor"}', array()));
    $otp_invalid = $api->detect_instance();
    $assert('otp_required' === $otp_invalid['error']['status'], 'Invalid OTP was not classified.');

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

    echo "AWVP PeerTube read-only HTTP/API tests passed.\n";
}
