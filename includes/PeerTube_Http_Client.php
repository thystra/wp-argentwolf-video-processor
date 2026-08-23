<?php
/**
 * File: includes/PeerTube_Http_Client.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use InvalidArgumentException;

/**
 * Origin-bound WordPress HTTP transport for reviewed PeerTube API reads.
 *
 * This first transport checkpoint deliberately exposes only the public
 * instance-configuration read. Authentication and state-changing endpoints
 * require separately reviewed methods and are not reachable through this
 * class yet.
 */
final class PeerTube_Http_Client
{
    public const MAX_METADATA_RESPONSE_BYTES = 1048576;

    private const DEFAULT_TIMEOUT_SECONDS = 15;
    private const CONFIG_PATH = '/api/v1/config';

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
        if (self::CONFIG_PATH !== $path) {
            throw new InvalidArgumentException('PeerTube HTTP path is not part of the reviewed read-only endpoint set.');
        }

        if ($response_limit < 1 || $response_limit > self::MAX_METADATA_RESPONSE_BYTES) {
            throw new InvalidArgumentException('PeerTube HTTP response limit is outside the reviewed bound.');
        }

        if (! function_exists('wp_safe_remote_request')) {
            return self::failure(PeerTube_Api_Error::transport(null));
        }

        $url = $this->origin . $path;
        $host_filter = null;
        $port_filter = null;

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

            $response = wp_safe_remote_request(
                $url,
                array(
                    'method'              => 'GET',
                    'timeout'             => self::DEFAULT_TIMEOUT_SECONDS,
                    'blocking'            => true,
                    'redirection'         => 0,
                    'sslverify'           => true,
                    'reject_unsafe_urls'  => true,
                    'decompress'          => false,
                    'limit_response_size' => $response_limit,
                    'headers'             => array(
                        'Accept'          => 'application/json, application/problem+json',
                        'Accept-Encoding' => 'identity',
                        'User-Agent'      => self::user_agent(),
                    ),
                )
            );
        } catch (\Throwable $error) {
            return self::failure(PeerTube_Api_Error::transport($error));
        } finally {
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

        if ($http_status < 200 || $http_status >= 300) {
            return self::failure(
                PeerTube_Api_Error::normalize(
                    $http_status,
                    $headers,
                    $response_was_truncated ? '' : $body
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
