<?php
/**
 * File: includes/PeerTube_Api_Error.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class PeerTube_Api_Error
{
    private const MAX_DETAIL_BYTES = 512;
    private const MAX_RETRY_AFTER = 86400;
    private const MAX_RATE_VALUE = 4294967295;

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public static function normalize(int $http_status, array $headers, string $body): array
    {
        $decoded = json_decode($body, true);
        $code = is_array($decoded)
            ? self::safe_token($decoded['code'] ?? '', 191)
            : '';

        $result = array(
            'status'         => self::machine_status($http_status, $code),
            'http_status'    => $http_status,
            'type'           => '',
            'code'           => $code,
            'detail'         => '',
            'retry_after'    => min(self::integer_header($headers, 'retry-after'), self::MAX_RETRY_AFTER),
            'rate_limit'     => min(self::integer_header($headers, 'x-ratelimit-limit'), self::MAX_RATE_VALUE),
            'rate_remaining' => min(self::integer_header($headers, 'x-ratelimit-remaining'), self::MAX_RATE_VALUE),
            'rate_reset'     => min(self::integer_header($headers, 'x-ratelimit-reset'), self::MAX_RATE_VALUE),
        );

        if (! is_array($decoded)) {
            return $result;
        }

        $result['type'] = self::safe_token($decoded['type'] ?? '', 191);
        $result['detail'] = self::safe_detail($decoded['detail'] ?? ($decoded['error'] ?? ''));

        return $result;
    }

    /** @return array<string, mixed> */
    public static function invalid_response(string $code, int $http_status = 0): array
    {
        return self::local('invalid_response', $code, $http_status);
    }

    /** @return array<string, mixed> */
    public static function transport(mixed $error): array
    {
        $code = '';
        $message = '';

        if (is_object($error) && method_exists($error, 'get_error_code')) {
            $candidate = $error->get_error_code();
            $code = self::safe_token(is_string($candidate) ? $candidate : '', 191);
        } elseif ($error instanceof \Throwable) {
            $code = 'transport_exception';
        }

        if (is_object($error) && method_exists($error, 'get_error_message')) {
            $candidate = $error->get_error_message();
            $message = is_string($candidate) ? $candidate : '';
        } elseif ($error instanceof \Throwable) {
            $message = $error->getMessage();
        }

        $status = 1 === preg_match('/(?:certificate|ssl|tls|peer verification)/i', $message)
            ? 'tls_error'
            : 'transport_error';

        return self::local($status, $code, 0);
    }

    /** @return array<string, mixed> */
    private static function local(string $status, string $code, int $http_status): array
    {
        return array(
            'status'         => $status,
            'http_status'    => $http_status,
            'type'           => '',
            'code'           => self::safe_token($code, 191),
            'detail'         => '',
            'retry_after'    => 0,
            'rate_limit'     => 0,
            'rate_remaining' => 0,
            'rate_reset'     => 0,
        );
    }

    private static function machine_status(int $http_status, string $code): string
    {
        if (429 === $http_status) {
            return 'rate_limited';
        }

        if (
            (401 === $http_status && 'missing_two_factor' === $code)
            || (400 === $http_status && 'invalid_two_factor' === $code)
        ) {
            return 'otp_required';
        }

        if (
            in_array($http_status, array(400, 401), true)
            && in_array($code, array('invalid_client', 'invalid_grant', 'invalid_token'), true)
        ) {
            return 'authentication_required';
        }

        return match ($http_status) {
            401 => 'authentication_required',
            403 => 'permission_denied',
            404 => 'not_found',
            default => $http_status >= 500 ? 'remote_error' : 'invalid_response',
        };
    }

    /** @param array<string, mixed> $headers */
    private static function integer_header(array $headers, string $wanted): int
    {
        foreach ($headers as $name => $value) {
            if (! is_string($name) || strtolower($name) !== $wanted) {
                continue;
            }

            if (is_array($value)) {
                $value = reset($value);
            }

            if (is_int($value) && $value >= 0) {
                return $value;
            }

            if (is_string($value) && ctype_digit($value)) {
                $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0)));
                return false === $parsed ? 0 : $parsed;
            }
        }

        return 0;
    }

    private static function safe_token(mixed $value, int $max_length): string
    {
        if (! is_string($value) || '' === $value || strlen($value) > $max_length) {
            return '';
        }

        if (
            1 !== preg_match('//u', $value)
            || 1 !== preg_match('/^[\x21-\x7E]+$/D', $value)
            || self::contains_secret_marker($value)
        ) {
            return '';
        }

        return $value;
    }

    private static function safe_detail(mixed $value): string
    {
        if (! is_string($value) || '' === $value || 1 !== preg_match('//u', $value)) {
            return '';
        }

        $value = preg_replace('/(?:[\x00-\x1F\x7F]|\p{Cf})/u', '', $value);
        if (! is_string($value) || '' === $value || self::contains_secret_marker($value)) {
            return '';
        }

        if (strlen($value) > self::MAX_DETAIL_BYTES) {
            $value = substr($value, 0, self::MAX_DETAIL_BYTES);
            while ('' !== $value && 1 !== preg_match('//u', $value)) {
                $value = substr($value, 0, -1);
            }
        }

        return $value;
    }

    private static function contains_secret_marker(string $value): bool
    {
        $folded = preg_replace('/[^a-z0-9]+/i', '', strtolower($value));
        if (! is_string($folded)) {
            return true;
        }

        foreach (
            array(
                'accesstoken',
                'refreshtoken',
                'clientsecret',
                'authorization',
                'password',
                'passwd',
                'bearer',
                'otp',
            ) as $marker
        ) {
            if (str_contains($folded, $marker)) {
                return true;
            }
        }

        return false;
    }
}

// EOF: includes/PeerTube_Api_Error.php
