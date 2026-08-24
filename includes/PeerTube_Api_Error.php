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
    public static function normalize(
        int $http_status,
        array $headers,
        string $body,
        string $context = 'public'
    ): array
    {
        $decoded = json_decode($body, true);
        $sensitive = in_array($context, array('sensitive', 'token', 'bearer'), true);
        $candidate_code = is_array($decoded)
            ? self::ascii_token($decoded['code'] ?? '', 191)
            : '';
        $code = $sensitive
            ? self::reviewed_error_code($candidate_code, $context)
            : self::safe_token($candidate_code, 191);

        $result = array(
            'status'         => self::machine_status($http_status, $code, $headers, $context),
            'http_status'    => $http_status,
            'type'           => '',
            'code'           => $code,
            'detail'         => '',
            'retry_after'    => min(self::integer_header($headers, 'retry-after'), self::MAX_RETRY_AFTER),
            'rate_limit'     => min(self::integer_header($headers, 'x-ratelimit-limit'), self::MAX_RATE_VALUE),
            'rate_remaining' => min(self::integer_header($headers, 'x-ratelimit-remaining'), self::MAX_RATE_VALUE),
            'rate_reset'     => min(self::integer_header($headers, 'x-ratelimit-reset'), self::MAX_RATE_VALUE),
        );

        if (! is_array($decoded) || $sensitive) {
            return $result;
        }

        $result['type'] = self::safe_token($decoded['type'] ?? '', 191);
        $result['detail'] = self::safe_detail($decoded['detail'] ?? ($decoded['error'] ?? ''));

        return $result;
    }

    /** @return array<string, mixed> */
    public static function invalid_response(string $code, int $http_status = 0): array
    {
        return self::local('invalid_response', self::reviewed_local_code($code), $http_status);
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

        return self::local($status, self::safe_token($code, 191), 0);
    }

    /** @return array<string, mixed> */
    private static function local(string $status, string $code, int $http_status): array
    {
        return array(
            'status'         => $status,
            'http_status'    => $http_status,
            'type'           => '',
            'code'           => self::ascii_token($code, 191),
            'detail'         => '',
            'retry_after'    => 0,
            'rate_limit'     => 0,
            'rate_remaining' => 0,
            'rate_reset'     => 0,
        );
    }

    /** @param array<string, mixed> $headers */
    private static function machine_status(
        int $http_status,
        string $code,
        array $headers,
        string $context
    ): string
    {
        if (429 === $http_status) {
            return 'rate_limited';
        }

        if ('token' === $context && (
            (401 === $http_status && self::otp_required_header($headers))
            ||
            (401 === $http_status && 'missing_two_factor' === $code)
            || (400 === $http_status && 'invalid_two_factor' === $code)
        )) {
            return 'otp_required';
        }

        if (
            'token' === $context
            && 400 === $http_status
            && in_array(
                $code,
                array(
                    'account_approval_rejected',
                    'account_blocked',
                    'account_waiting_for_approval',
                    'email_not_verified',
                ),
                true
            )
        ) {
            return 'permission_denied';
        }

        if ('token' === $context && 400 === $http_status && 'too_long_password' === $code) {
            return 'authentication_required';
        }

        if (
            in_array($http_status, array(400, 401), true)
            && (
                ('token' === $context && in_array($code, array('invalid_client', 'invalid_grant', 'invalid_token'), true))
                || ('bearer' === $context && 'invalid_token' === $code)
            )
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

    private static function reviewed_error_code(string $code, string $context): string
    {
        $allowed = match ($context) {
            'token' => array(
                'account_blocked',
                'account_approval_rejected',
                'account_waiting_for_approval',
                'email_not_verified',
                'invalid_client',
                'invalid_grant',
                'invalid_token',
                'invalid_two_factor',
                'missing_two_factor',
                'rate_limit',
                'too_long_password',
            ),
            'bearer' => array('invalid_token', 'rate_limit'),
            'sensitive' => array('rate_limit'),
            default => array(),
        };

        return in_array($code, $allowed, true) ? $code : '';
    }

    /** @param array<string, mixed> $headers */
    private static function otp_required_header(array $headers): bool
    {
        $value = strtolower(self::string_header($headers, 'x-peertube-otp'));
        if ('' === $value) {
            return false;
        }

        $parts = explode(';', $value, 2);
        return 'required' === trim($parts[0]);
    }

    /** @param array<string, mixed> $headers */
    private static function string_header(array $headers, string $wanted): string
    {
        foreach ($headers as $name => $value) {
            if (! is_string($name) || strtolower($name) !== $wanted) {
                continue;
            }

            if (is_array($value)) {
                $value = 1 === count($value) ? reset($value) : '';
            }

            return is_string($value) && strlen($value) <= 1024 ? trim($value) : '';
        }

        return '';
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
        $value = self::ascii_token($value, $max_length);
        if ('' === $value || self::contains_secret_marker($value)) {
            return '';
        }

        return $value;
    }

    private static function reviewed_local_code(string $value): string
    {
        $allowed = array(
            'access_token_input_invalid',
            'api_error_missing',
            'channel_authority_invalid',
            'channel_identity_input_invalid',
            'channel_list_incomplete',
            'channel_list_shape_invalid',
            'channel_shape_invalid',
            'channels_content_type_invalid',
            'channels_json_invalid',
            'channels_shape_invalid',
            'channels_status_invalid',
            'development_origin_filter_unavailable',
            'identity_blocked',
            'identity_content_type_invalid',
            'identity_json_invalid',
            'identity_shape_invalid',
            'identity_status_invalid',
            'instance_content_type_invalid',
            'instance_json_invalid',
            'instance_shape_invalid',
            'instance_status_invalid',
            'instance_version_invalid',
            'oauth_client_content_type_invalid',
            'oauth_client_input_invalid',
            'oauth_client_json_invalid',
            'oauth_client_shape_invalid',
            'oauth_client_status_invalid',
            'origin_port_filter_unavailable',
            'password_token_input_invalid',
            'response_content_encoding_unsupported',
            'response_too_large',
            'token_content_type_invalid',
            'token_json_invalid',
            'token_shape_invalid',
            'token_status_invalid',
            'wordpress_http_response_invalid',
        );

        return in_array($value, $allowed, true) ? $value : '';
    }

    private static function ascii_token(mixed $value, int $max_length): string
    {
        if (
            ! is_string($value)
            || '' === $value
            || strlen($value) > $max_length
            || 1 !== preg_match('/^[\x21-\x7E]+$/D', $value)
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
