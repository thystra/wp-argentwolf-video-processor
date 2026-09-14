<?php
/**
 * File: includes/PeerTube_Embed_Provider.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Recognizes public PeerTube-shaped watch/embed URLs on arbitrary safe origins.
 *
 * This stage is deliberately syntax-only. A later editor application boundary
 * may query the public PeerTube API to prove the provider and converge aliases
 * such as UUID and short-UUID forms before creating durable AWVP Video state.
 */
final class PeerTube_Embed_Provider implements Video_Embed_Provider
{
    public function provider_id(): string
    {
        return Video_Embed_Identity::PEERTUBE;
    }

    /** @return array<string,mixed>|null */
    public function recognize(string $url): ?array
    {
        $parts = self::parse(trim($url));
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');
        if ('' === $scheme || '' === $host) {
            return null;
        }

        $origin = $scheme . '://' . self::host_output($host);
        $port = $parts['port'] ?? null;
        if (is_int($port)) {
            $default_port = 'https' === $scheme ? 443 : ('http' === $scheme ? 80 : 0);
            if ($port !== $default_port) {
                $origin .= ':' . $port;
            }
        }
        $origin = PeerTube_Origin::sanitize($origin);
        if ('' === $origin) {
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        if (1 !== preg_match('#^/(?:videos/(?:watch|embed)|w)/([^/]+)/?$#D', $path, $matches)) {
            return null;
        }
        $video_id = Video_Embed_Identity::sanitize_peertube_id(rawurldecode((string) $matches[1]));

        return '' === $video_id
            ? null
            : Video_Embed_Identity::create($this->provider_id(), $origin, $video_id);
    }

    private static function host_output(string $host): string
    {
        $host = strtolower($host);
        $ip = trim($host, '[]');
        return false !== filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)
            ? '[' . $ip . ']'
            : $host;
    }

    /** @return array<string,mixed>|false */
    private static function parse(string $url): array|false
    {
        if ('' === $url) {
            return false;
        }
        if (function_exists('wp_parse_url')) {
            $parsed = wp_parse_url($url);
            return is_array($parsed) ? $parsed : false;
        }
        // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Dependency-free test fallback only.
        $parsed = parse_url($url);
        return is_array($parsed) ? $parsed : false;
    }
}

// EOF: includes/PeerTube_Embed_Provider.php
