<?php
/**
 * File: includes/PeerTube_Http_Client.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use InvalidArgumentException;

/**
 * Origin-bound WordPress HTTP transport for reviewed PeerTube API endpoints.
 *
 * Callers cannot provide a URL. Each public method below constructs one exact
 * reviewed endpoint and validates every dynamic path/header/body value before
 * the WordPress HTTP API is reached.
 */
final class PeerTube_Http_Client
{
    public const MAX_METADATA_RESPONSE_BYTES = 1048576;
    public const MAX_CHANNEL_RESPONSE_BYTES = 2097152;
    public const MAX_UPLOAD_REQUEST_BYTES = 1048576;

    private const DEFAULT_TIMEOUT_SECONDS = 15;
    private const STREAM_UPLOAD_TIMEOUT_SECONDS = 3600;
    private const CONFIG_PATH = '/api/v1/config';
    private const OAUTH_CLIENT_PATH = '/api/v1/oauth-clients/local';
    private const TOKEN_PATH = '/api/v1/users/token';
    private const REVOKE_TOKEN_PATH = '/api/v1/users/revoke-token';
    private const CURRENT_USER_PATH = '/api/v1/users/me';
    private const RESUMABLE_UPLOAD_PATH = '/api/v1/videos/upload-resumable';

    public function __construct(private readonly string $origin)
    {
        if ('' === $origin || $origin !== PeerTube_Origin::sanitize($origin)) {
            throw new InvalidArgumentException('PeerTube HTTP client requires an exact canonical safe origin.');
        }
    }

    public function origin(): string
    {
        return $this->origin;
    }

    /**
     * @return array{
     *   ok:bool,
     *   http_status:int,
     *   headers:array<string,string>,
     *   body:string,
     *   error:array<string,mixed>|null
     * }
     */
    public function get(string $path, int $response_limit = self::MAX_METADATA_RESPONSE_BYTES): array
    {
        if (
            self::CONFIG_PATH !== $path
            || $response_limit < 1
            || $response_limit > self::MAX_METADATA_RESPONSE_BYTES
        ) {
            throw new InvalidArgumentException('PeerTube HTTP path is not the reviewed public configuration endpoint.');
        }

        return $this->request('GET', self::CONFIG_PATH, $response_limit);
    }

    /** @return array<string, mixed> */
    public function get_local_oauth_client(): array
    {
        return $this->request('GET', self::OAUTH_CLIENT_PATH, self::MAX_METADATA_RESPONSE_BYTES, 'sensitive');
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    public function post_password_token(array $fields, string $otp = ''): array
    {
        $expected = array(
            'client_id',
            'client_secret',
            'username',
            'password',
            'response_type',
            'grant_type',
            'scope',
        );

        if ($expected !== array_keys($fields)) {
            throw new InvalidArgumentException('PeerTube password-token form fields are not the reviewed exact set.');
        }

        foreach ($fields as $name => $value) {
            $maximum = in_array($name, array('client_secret', 'password'), true) ? 16384 : 1024;
            if (! self::safe_request_value($value, $maximum, true)) {
                throw new InvalidArgumentException('PeerTube password-token form contains an unsafe value.');
            }
        }

        if (
            'code' !== $fields['response_type']
            || 'password' !== $fields['grant_type']
            || 'upload' !== $fields['scope']
            || ('' !== $otp && 1 !== preg_match('/^[0-9]{6}$/D', $otp))
        ) {
            throw new InvalidArgumentException('PeerTube password-token request is outside the reviewed contract.');
        }

        $headers = array('Content-Type' => 'application/x-www-form-urlencoded');
        if ('' !== $otp) {
            $headers['x-peertube-otp'] = $otp;
        }

        return $this->request(
            'POST',
            self::TOKEN_PATH,
            self::MAX_METADATA_RESPONSE_BYTES,
            'token',
            $headers,
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
        );
    }

    /**
     * @param array<string, string> $fields
     * @return array<string, mixed>
     */
    public function post_refresh_token(array $fields): array
    {
        $expected = array('client_id', 'client_secret', 'grant_type', 'refresh_token');
        if ($expected !== array_keys($fields)) {
            throw new InvalidArgumentException('PeerTube refresh-token form fields are not the reviewed exact set.');
        }

        foreach ($fields as $name => $value) {
            $maximum = in_array($name, array('client_secret', 'refresh_token'), true) ? 16384 : 1024;
            if (! self::safe_request_value($value, $maximum, true)) {
                throw new InvalidArgumentException('PeerTube refresh-token form contains an unsafe value.');
            }
        }
        if ('refresh_token' !== $fields['grant_type']) {
            throw new InvalidArgumentException('PeerTube refresh-token request is outside the reviewed contract.');
        }

        return $this->request(
            'POST',
            self::TOKEN_PATH,
            self::MAX_METADATA_RESPONSE_BYTES,
            'token',
            array('Content-Type' => 'application/x-www-form-urlencoded'),
            http_build_query($fields, '', '&', PHP_QUERY_RFC3986)
        );
    }

    /** @return array<string, mixed> */
    public function post_revoke_token(string $access_token): array
    {
        if (! self::safe_bearer_token($access_token)) {
            throw new InvalidArgumentException('PeerTube access token is unsafe for an Authorization header.');
        }

        return $this->request(
            'POST',
            self::REVOKE_TOKEN_PATH,
            self::MAX_METADATA_RESPONSE_BYTES,
            'bearer',
            array('Authorization' => 'Bearer ' . $access_token),
            ''
        );
    }

    /** @param array<string,mixed> $metadata @return array<string,mixed> */
    public function post_resumable_upload_init(
        string $access_token,
        array $metadata,
        int $total_bytes,
        string $content_type
    ): array {
        if (! self::safe_bearer_token($access_token)
            || array('channelId', 'name', 'privacy', 'filename') !== array_keys($metadata)
            || ! is_int($metadata['channelId']) || $metadata['channelId'] < 1
            || ! self::safe_request_value($metadata['name'] ?? null, 1024, true)
            || 3 !== ($metadata['privacy'] ?? null)
            || ! self::safe_filename($metadata['filename'] ?? null)
            || 'video/mp4' !== $content_type
            || $total_bytes < 1) {
            throw new InvalidArgumentException('PeerTube resumable-upload initialization is outside the reviewed contract.');
        }

        try {
            $body = json_encode($metadata, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException) {
            throw new InvalidArgumentException('PeerTube resumable-upload metadata could not be encoded.');
        }

        return $this->request(
            'POST',
            self::RESUMABLE_UPLOAD_PATH,
            self::MAX_METADATA_RESPONSE_BYTES,
            'bearer',
            array(
                'Authorization'           => 'Bearer ' . $access_token,
                'Content-Type'            => 'application/json',
                'X-Upload-Content-Length' => (string) $total_bytes,
                'X-Upload-Content-Type'   => $content_type,
            ),
            $body,
            array(200, 201)
        );
    }

    /** @return array<string,mixed> */
    public function put_resumable_upload_chunk(
        string $access_token,
        string $session_id,
        int $start,
        int $total_bytes,
        string $content_type,
        string $chunk
    ): array {
        $length = strlen($chunk);
        if (! self::safe_bearer_token($access_token)
            || ! self::safe_upload_session_id($session_id)
            || $start < 0 || $total_bytes < 1 || $start >= $total_bytes
            || $length < 1 || $length > self::MAX_UPLOAD_REQUEST_BYTES
            || $start > PHP_INT_MAX - $length || $start + $length > $total_bytes
            || 'video/mp4' !== $content_type) {
            throw new InvalidArgumentException('PeerTube resumable-upload chunk is outside the reviewed contract.');
        }

        $end = $start + $length - 1;
        $path = self::RESUMABLE_UPLOAD_PATH . '?upload_id=' . rawurlencode($session_id);
        return $this->request(
            'PUT',
            $path,
            self::MAX_METADATA_RESPONSE_BYTES,
            'bearer',
            array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => $content_type,
                'Content-Length'=> (string) $length,
                'Content-Range' => 'bytes ' . $start . '-' . $end . '/' . $total_bytes,
            ),
            $chunk,
            array(200, 308)
        );
    }

    /** @return array<string,mixed> */
    public function put_resumable_upload_slice(
        string $access_token,
        string $session_id,
        int $total_bytes,
        string $content_type,
        PeerTube_Upload_Slice $slice
    ): array {
        $start = $slice->start();
        $length = $slice->bytes();
        if (! self::safe_bearer_token($access_token)
            || ! self::safe_upload_session_id($session_id)
            || $start < 0 || $total_bytes < 1 || $start >= $total_bytes
            || $length < 1
            || $start > PHP_INT_MAX - $length || $start + $length > $total_bytes
            || 'video/mp4' !== $content_type) {
            throw new InvalidArgumentException('PeerTube resumable-upload slice is outside the reviewed contract.');
        }

        $end = $start + $length - 1;
        $path = self::RESUMABLE_UPLOAD_PATH . '?upload_id=' . rawurlencode($session_id);
        return $this->request(
            'PUT',
            $path,
            self::MAX_METADATA_RESPONSE_BYTES,
            'bearer',
            array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => $content_type,
                'Content-Length'=> (string) $length,
                'Content-Range' => 'bytes ' . $start . '-' . $end . '/' . $total_bytes,
                'Expect'        => '',
            ),
            null,
            array(200, 308),
            $slice
        );
    }

    /** @return array<string,mixed> */
    public function put_resumable_upload_probe(
        string $access_token,
        string $session_id,
        int $total_bytes
    ): array {
        if (! self::safe_bearer_token($access_token)
            || ! self::safe_upload_session_id($session_id)
            || $total_bytes < 1) {
            throw new InvalidArgumentException('PeerTube resumable-upload probe is outside the reviewed contract.');
        }

        $path = self::RESUMABLE_UPLOAD_PATH . '?upload_id=' . rawurlencode($session_id);
        return $this->request(
            'PUT',
            $path,
            self::MAX_METADATA_RESPONSE_BYTES,
            'bearer',
            array(
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/octet-stream',
                'Content-Length'=> '0',
                'Content-Range' => 'bytes */' . $total_bytes,
            ),
            '',
            array(200, 308)
        );
    }

    /** @return array<string, mixed> */
    public function get_current_user(string $access_token): array
    {
        if (! self::safe_bearer_token($access_token)) {
            throw new InvalidArgumentException('PeerTube access token is unsafe for an Authorization header.');
        }

        return $this->request(
            'GET',
            self::CURRENT_USER_PATH,
            self::MAX_METADATA_RESPONSE_BYTES,
            'bearer',
            array('Authorization' => 'Bearer ' . $access_token)
        );
    }

    /** @return array<string, mixed> */
    public function get_video_status(string $access_token, string $video_uuid): array
    {
        if (! self::safe_bearer_token($access_token) || ! self::safe_video_uuid($video_uuid)) {
            throw new InvalidArgumentException('PeerTube video-state request is outside the reviewed bound.');
        }

        return $this->request(
            'GET',
            '/api/v1/videos/' . rawurlencode(strtolower($video_uuid)),
            self::MAX_METADATA_RESPONSE_BYTES,
            'bearer',
            array('Authorization' => 'Bearer ' . $access_token)
        );
    }

    /** @return array<string, mixed> */
    public function get_account_channels(string $account_name, int $start, int $count): array
    {
        if (
            ! self::safe_path_segment($account_name)
            || $start < 0
            || $start > 1000000
            || $count < 1
            || $count > 100
        ) {
            throw new InvalidArgumentException('PeerTube account-channel request is outside the reviewed bound.');
        }

        $query = http_build_query(
            array('start' => $start, 'count' => $count, 'sort' => 'id'),
            '',
            '&',
            PHP_QUERY_RFC3986
        );
        $path = '/api/v1/accounts/' . rawurlencode($account_name) . '/video-channels?' . $query;

        // This endpoint is public. Management authority comes from the prior
        // authenticated identity response plus per-channel owner/local checks,
        // not from leaking a bearer token to a public read.
        return $this->request('GET', $path, self::MAX_CHANNEL_RESPONSE_BYTES);
    }

    /**
     * @param array<string, string> $headers
     * @return array{
     *   ok:bool,
     *   http_status:int,
     *   headers:array<string,string>,
     *   body:string,
     *   error:array<string,mixed>|null
     * }
     */
    private function request(
        string $method,
        string $path,
        int $response_limit,
        string $error_context = 'public',
        array $headers = array(),
        ?string $body = null,
        array $accepted_statuses = array(),
        ?PeerTube_Upload_Slice $upload_slice = null
    ): array {
        if ($response_limit < 1 || $response_limit > self::MAX_CHANNEL_RESPONSE_BYTES) {
            throw new InvalidArgumentException('PeerTube HTTP response limit is outside the reviewed bound.');
        }

        if (! in_array($method, array('GET', 'POST', 'PUT'), true) || ! str_starts_with($path, '/api/v1/')) {
            throw new InvalidArgumentException('PeerTube HTTP method/path is outside the reviewed endpoint set.');
        }

        if (! in_array($error_context, array('public', 'sensitive', 'token', 'bearer'), true)) {
            throw new InvalidArgumentException('PeerTube HTTP error context is outside the reviewed endpoint set.');
        }

        foreach ($accepted_statuses as $accepted_status) {
            if (! is_int($accepted_status) || $accepted_status < 200 || $accepted_status > 399) {
                throw new InvalidArgumentException('PeerTube HTTP accepted status is outside the reviewed bound.');
            }
        }

        if (null !== $upload_slice && ('PUT' !== $method || null !== $body)) {
            throw new InvalidArgumentException('PeerTube streamed upload request shape is invalid.');
        }
        if (! function_exists('wp_safe_remote_request')) {
            return self::failure(PeerTube_Api_Error::transport(null));
        }
        if (null !== $upload_slice && (
            ! function_exists('curl_init')
            || ! function_exists('curl_exec')
            || ! function_exists('curl_setopt')
            || ! function_exists('add_action')
            || ! function_exists('remove_action')
            || ! defined('CURLOPT_UPLOAD')
            || ! defined('CURLOPT_CUSTOMREQUEST')
            || ! defined('CURLOPT_READFUNCTION')
            || (! defined('CURLOPT_INFILESIZE_LARGE') && ! defined('CURLOPT_INFILESIZE'))
        )) {
            return self::failure(PeerTube_Api_Error::invalid_response('stream_upload_curl_unavailable'));
        }

        $url = $this->origin . $path;
        $host_filter = null;
        $port_filter = null;
        $curl_filter = null;
        $curl_configured = false;

        try {
            if (PeerTube_Origin::is_development_origin($this->origin)) {
                if (! function_exists('add_filter') || ! function_exists('remove_filter')) {
                    return self::failure(
                        PeerTube_Api_Error::invalid_response('development_origin_filter_unavailable')
                    );
                }

                $host_filter = function (mixed $external, mixed $host, mixed $request_url): bool {
                    unset($host);

                    return true === $external
                        || (is_string($request_url) && $this->targets_origin($request_url));
                };
                add_filter('http_request_host_is_external', $host_filter, 10, 3);
            }

            $configured_port = $this->configured_port();
            if (null !== $configured_port && ! in_array($configured_port, array(80, 443, 8080), true)) {
                if (! function_exists('add_filter') || ! function_exists('remove_filter')) {
                    return self::failure(PeerTube_Api_Error::invalid_response('origin_port_filter_unavailable'));
                }

                $port_filter = function (mixed $ports, mixed $host, mixed $request_url) use ($configured_port): mixed {
                    unset($host);

                    if (! is_array($ports) || ! is_string($request_url) || ! $this->targets_origin($request_url)) {
                        return $ports;
                    }

                    if (! in_array($configured_port, $ports, true)) {
                        $ports[] = $configured_port;
                    }

                    return $ports;
                };
                add_filter('http_allowed_safe_ports', $port_filter, 10, 3);
            }

            if (null !== $upload_slice) {
                $curl_filter = static function (mixed $handle, mixed $parsed_args, mixed $request_url) use (
                    $upload_slice,
                    $url,
                    &$curl_configured
                ): void {
                    if (! is_string($request_url) || ! hash_equals($url, $request_url)
                        || ! is_array($parsed_args) || 'PUT' !== ($parsed_args['method'] ?? null)) {
                        return;
                    }

                    $read = static function (mixed $curl, mixed $stream, int $requested) use ($upload_slice): string {
                        unset($curl, $stream);
                        return $upload_slice->read($requested);
                    };
                    $size_option = defined('CURLOPT_INFILESIZE_LARGE')
                        ? constant('CURLOPT_INFILESIZE_LARGE')
                        : constant('CURLOPT_INFILESIZE');
                    foreach (array(
                        array(constant('CURLOPT_UPLOAD'), true),
                        array(constant('CURLOPT_CUSTOMREQUEST'), 'PUT'),
                        array($size_option, $upload_slice->bytes()),
                        array(constant('CURLOPT_READFUNCTION'), $read),
                    ) as [$option, $value]) {
                        if (true !== curl_setopt($handle, $option, $value)) {
                            throw new \RuntimeException('PeerTube streamed upload cURL setup failed before send.');
                        }
                    }
                    $curl_configured = true;
                };
                add_action('http_api_curl', $curl_filter, 10, 3);
            }

            $request = array(
                    'method'              => $method,
                    'timeout'             => null === $upload_slice
                        ? self::DEFAULT_TIMEOUT_SECONDS
                        : self::STREAM_UPLOAD_TIMEOUT_SECONDS,
                    'blocking'            => true,
                    'redirection'         => 0,
                    'sslverify'           => true,
                    'reject_unsafe_urls'  => true,
                    'decompress'          => false,
                    'limit_response_size' => $response_limit,
                    'headers'             => array_merge(
                        array(
                        'Accept'          => 'application/json, application/problem+json',
                        'Accept-Encoding' => 'identity',
                        'User-Agent'      => self::user_agent(),
                        ),
                        $headers
                    ),
                );

            if (null !== $body) {
                $request['body'] = $body;
            }

            $response = wp_safe_remote_request($url, $request);
            if (null !== $upload_slice && (! $curl_configured || ! $upload_slice->complete())) {
                return self::failure(PeerTube_Api_Error::invalid_response('stream_upload_incomplete'));
            }
        } catch (\Throwable $error) {
            return self::failure(PeerTube_Api_Error::transport($error));
        } finally {
            if (null !== $curl_filter) {
                remove_action('http_api_curl', $curl_filter, 10);
            }

            if (null !== $port_filter) {
                remove_filter('http_allowed_safe_ports', $port_filter, 10);
            }

            if (null !== $host_filter) {
                remove_filter('http_request_host_is_external', $host_filter, 10);
            }
        }

        if (function_exists('is_wp_error') && is_wp_error($response)) {
            return self::failure(PeerTube_Api_Error::transport($response));
        }

        if (
            ! is_array($response)
            || ! function_exists('wp_remote_retrieve_response_code')
            || ! function_exists('wp_remote_retrieve_body')
            || ! function_exists('wp_remote_retrieve_header')
        ) {
            return self::failure(PeerTube_Api_Error::invalid_response('wordpress_http_response_invalid'));
        }

        $http_status = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        if (! is_int($http_status) || $http_status < 100 || $http_status > 599 || ! is_string($body)) {
            return self::failure(PeerTube_Api_Error::invalid_response('wordpress_http_response_invalid'));
        }

        $headers = self::selected_headers($response);
        $content_length = self::nonnegative_integer($headers['content-length'] ?? '');
        $response_was_truncated = $content_length > $response_limit || strlen($body) >= $response_limit;

        $success_status = [] === $accepted_statuses
            ? ($http_status >= 200 && $http_status < 300)
            : in_array($http_status, $accepted_statuses, true);
        if (! $success_status) {
            return self::failure(
                PeerTube_Api_Error::normalize(
                    $http_status,
                    $headers,
                    $response_was_truncated ? '' : $body,
                    $error_context
                ),
                $http_status,
                $headers
            );
        }

        $content_encoding = strtolower(trim($headers['content-encoding'] ?? ''));
        if ('' !== $content_encoding && 'identity' !== $content_encoding) {
            return self::failure(
                PeerTube_Api_Error::invalid_response('response_content_encoding_unsupported', $http_status),
                $http_status,
                $headers
            );
        }

        if ($response_was_truncated) {
            return self::failure(
                PeerTube_Api_Error::invalid_response('response_too_large', $http_status),
                $http_status,
                $headers
            );
        }

        return array(
            'ok'          => true,
            'http_status' => $http_status,
            'headers'     => $headers,
            'body'        => $body,
            'error'       => null,
        );
    }

    private function targets_origin(string $url): bool
    {
        $parts = function_exists('wp_parse_url') ? wp_parse_url($url) : parse_url($url);
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ('' === $scheme || '' === $host) {
            return false;
        }

        $host_output = false !== filter_var(trim($host, '[]'), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . trim($host, '[]') . ']'
            : $host;
        $port = $parts['port'] ?? null;
        $candidate = $scheme . '://' . $host_output . (is_int($port) ? ':' . $port : '');

        return hash_equals($this->origin, $candidate);
    }

    private function configured_port(): ?int
    {
        $parts = function_exists('wp_parse_url') ? wp_parse_url($this->origin) : parse_url($this->origin);
        if (! is_array($parts) || ! isset($parts['port']) || ! is_int($parts['port'])) {
            return null;
        }

        return $parts['port'];
    }

    /** @return array<string, string> */
    private static function selected_headers(array $response): array
    {
        $headers = array();

        foreach (
            array(
                'content-type',
                'content-encoding',
                'content-length',
                'retry-after',
                'x-ratelimit-limit',
                'x-ratelimit-remaining',
                'x-ratelimit-reset',
                'x-peertube-otp',
                'location',
                'range',
            ) as $name
        ) {
            $value = wp_remote_retrieve_header($response, $name);
            if (is_array($value)) {
                $value = 1 === count($value) ? reset($value) : '';
            }

            if (is_string($value) && strlen($value) <= 1024) {
                $headers[$name] = $value;
            } else {
                $headers[$name] = '';
            }
        }

        return $headers;
    }

    private static function nonnegative_integer(string $value): int
    {
        if ('' === $value || ! ctype_digit($value)) {
            return 0;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0)));
        return false === $parsed ? PHP_INT_MAX : (int) $parsed;
    }

    private static function safe_request_value(mixed $value, int $maximum, bool $allow_spaces): bool
    {
        if (! is_string($value) || '' === $value || strlen($value) > $maximum || 1 !== preg_match('//u', $value)) {
            return false;
        }

        if (1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return false;
        }

        return $allow_spaces || 1 !== preg_match('/\s/u', $value);
    }

    private static function safe_bearer_token(string $value): bool
    {
        return self::safe_request_value($value, 16384, false);
    }

    private static function safe_path_segment(string $value): bool
    {
        return strlen($value) <= 50
            && 1 === preg_match('/^[a-z0-9_]+(?:[a-z0-9_.-]+[a-z0-9_]+)?$/D', $value);
    }

    private static function safe_filename(mixed $value): bool
    {
        return is_string($value)
            && '' !== $value
            && strlen($value) <= 255
            && basename($value) === $value
            && ! str_contains($value, '/') && ! str_contains($value, '\\') && 1 !== preg_match('/[\x00-\x1F\x7F]/', $value);
    }

    private static function safe_upload_session_id(string $value): bool
    {
        return 1 === preg_match('/^[A-Za-z0-9._~-]{1,191}$/D', $value);
    }

    private static function safe_video_uuid(string $value): bool
    {
        return 1 === preg_match('/^[a-fA-F0-9]{8}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{4}-[a-fA-F0-9]{12}$/D', $value);
    }

    private static function user_agent(): string
    {
        $version = defined('ARGENT_VIDEO_VERSION') && is_string(ARGENT_VIDEO_VERSION)
            ? ARGENT_VIDEO_VERSION
            : 'development';

        return 'ArgentWolf-Video-Processor/' . $version . '; WordPress';
    }

    /**
     * @param array<string, mixed> $error
     * @param array<string, string> $headers
     * @return array{
     *   ok:false,
     *   http_status:int,
     *   headers:array<string,string>,
     *   body:string,
     *   error:array<string,mixed>
     * }
     */
    private static function failure(array $error, int $http_status = 0, array $headers = array()): array
    {
        return array(
            'ok'          => false,
            'http_status' => $http_status,
            'headers'     => $headers,
            'body'        => '',
            'error'       => $error,
        );
    }
}

// EOF: includes/PeerTube_Http_Client.php
