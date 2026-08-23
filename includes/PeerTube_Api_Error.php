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

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public static function normalize(int $http_status, array $headers, string $body): array
    {
        $result = array(
            'status'      => self::machine_status($http_status),
            'http_status' => $http_status,
            'type'        => '',
            'code'        => '',
            'detail'      => '',
            'retry_after' => min(self::integer_header($headers, 'retry-after'), self::MAX_RETRY_AFTER),
            'rate_reset'  => self::integer_header($headers, 'x-ratelimit-reset'),
        );

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            return $result;
        }

        $result['type'] = self::safe_token($decoded['type'] ?? '', 191);
        $result['code'] = self::safe_token($decoded['code'] ?? '', 191);
        $result['detail'] = self::safe_detail($decoded['detail'] ?? '');

        return $result;
    }

    private static function machine_status(int $http_status): string
    {
        return match ($http_status) {
            401 => 'authentication_required',
            403 => 'permission_denied',
            404 => 'not_found',
            429 => 'rate_limited',
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

        if (preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return '';
        }

        return $value;
    }

    private static function safe_detail(mixed $value): string
    {
        if (! is_string($value) || '' === $value) {
            return '';
        }

        if (strlen($value) > self::MAX_DETAIL_BYTES) {
            $value = substr($value, 0, self::MAX_DETAIL_BYTES);
        }

        if (preg_match('/(?:access[_ -]?token|refresh[_ -]?token|client[_ -]?secret|authorization|password|passwd|otp)/i', $value)) {
            return '';
        }

        $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $value);
        return is_string($value) ? $value : '';
    }
}

// EOF: includes/PeerTube_Api_Error.php
