<?php
/**
 * File: includes/PeerTube_Origin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class PeerTube_Origin
{
    private const DEV_ORIGINS_CONSTANT = 'ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS';

    public static function sanitize(mixed $value): string
    {
        if (! is_string($value) || '' === $value || trim($value) !== $value) {
            return '';
        }

        $candidate = self::normalize_syntax($value, true);
        if ('' === $candidate) {
            return '';
        }

        if (self::is_development_origin($candidate)) {
            return $candidate;
        }

        $parts = self::parse($candidate);
        if (! is_array($parts) || 'https' !== ($parts['scheme'] ?? '')) {
            return '';
        }

        $host = (string) ($parts['host'] ?? '');
        if (self::is_ip_literal($host) || self::is_local_name($host)) {
            return '';
        }

        return self::is_dns_hostname($host) ? $candidate : '';
    }

    public static function is_development_origin(string $canonical_origin): bool
    {
        if (! defined(self::DEV_ORIGINS_CONSTANT)) {
            return false;
        }

        $configured = constant(self::DEV_ORIGINS_CONSTANT);
        if (! is_array($configured)) {
            return false;
        }

        foreach ($configured as $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized = self::normalize_syntax($value, true);
            if ('' !== $normalized && hash_equals($normalized, $canonical_origin)) {
                return true;
            }
        }

        return false;
    }

    private static function normalize_syntax(string $value, bool $allow_http): string
    {
        $parts = self::parse($value);
        if (! is_array($parts)) {
            return '';
        }

        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if ('https' !== $scheme && (! $allow_http || 'http' !== $scheme)) {
            return '';
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        if ('' === $host || str_contains($host, "\0")) {
            return '';
        }

        $path = (string) ($parts['path'] ?? '');
        if ('' !== $path && '/' !== $path) {
            return '';
        }

        $port = $parts['port'] ?? null;
        if (null !== $port && (! is_int($port) || $port < 1 || $port > 65535)) {
            return '';
        }

        $ip = trim($host, '[]');
        if (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $host_output = '[' . $ip . ']';
        } elseif (false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $host_output = $ip;
        } elseif (self::is_dns_hostname($host) || self::is_local_name($host)) {
            $host_output = $host;
        } else {
            return '';
        }

        $default_port = ('https' === $scheme) ? 443 : 80;
        $port_output = (null !== $port && $port !== $default_port) ? ':' . $port : '';

        return $scheme . '://' . $host_output . $port_output;
    }

    /** @return array<string, mixed>|false */
    private static function parse(string $value): array|false
    {
        if (function_exists('wp_parse_url')) {
            $parsed = wp_parse_url($value);
            return is_array($parsed) ? $parsed : false;
        }

        $parsed = parse_url($value);
        return is_array($parsed) ? $parsed : false;
    }

    private static function is_ip_literal(string $host): bool
    {
        return false !== filter_var(trim($host, '[]'), FILTER_VALIDATE_IP);
    }

    private static function is_local_name(string $host): bool
    {
        $host = strtolower(rtrim($host, '.'));

        return 'localhost' === $host
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.test');
    }

    private static function is_dns_hostname(string $host): bool
    {
        if (strlen($host) > 253 || '' === $host || str_ends_with($host, '.')) {
            return false;
        }

        if (1 !== preg_match('/^[a-z0-9.-]+$/', $host)) {
            return false;
        }

        $labels = explode('.', $host);
        if (count($labels) < 2) {
            return false;
        }

        foreach ($labels as $label) {
            if (
                '' === $label
                || strlen($label) > 63
                || '-' === $label[0]
                || '-' === $label[strlen($label) - 1]
                || 1 !== preg_match('/^[a-z0-9-]+$/', $label)
            ) {
                return false;
            }
        }

        return true;
    }
}

// EOF: includes/PeerTube_Origin.php
