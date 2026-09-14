<?php
/**
 * File: includes/Vimeo_Embed_Provider.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Recognizes supported public Vimeo and Vimeo player URL forms. */
final class Vimeo_Embed_Provider implements Video_Embed_Provider
{
    /** @var list<string> */
    private const HOSTS = array('vimeo.com', 'www.vimeo.com', 'player.vimeo.com');

    public function provider_id(): string
    {
        return Video_Embed_Identity::VIMEO;
    }

    /** @return array<string,mixed>|null */
    public function recognize(string $url): ?array
    {
        $parts = self::parse(trim($url));
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass'])) {
            return null;
        }

        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = strtolower(rtrim((string) ($parts['host'] ?? ''), '.'));
        if (! in_array($scheme, array('http', 'https'), true) || ! in_array($host, self::HOSTS, true) || ! self::standard_port($parts, $scheme)) {
            return null;
        }
        $query = array();
        parse_str((string) ($parts['query'] ?? ''), $query);
        if (array_key_exists('h', $query)) {
            // Vimeo's h parameter is an unlisted/private-link token. Dropping it
            // would create a false public identity, while retaining it would make
            // the canonical identity depend on access material. Fail closed.
            return null;
        }

        $path = (string) ($parts['path'] ?? '');
        $video_id = '';
        foreach (array(
            '#^/([1-9][0-9]*)/?$#D',
            '#^/video/([1-9][0-9]*)/?$#D',
            '#^/channels/[^/]+/([1-9][0-9]*)/?$#D',
            '#^/groups/[^/]+/videos/([1-9][0-9]*)/?$#D',
        ) as $pattern) {
            if (1 === preg_match($pattern, $path, $matches)) {
                $video_id = (string) $matches[1];
                break;
            }
        }

        $video_id = Video_Embed_Identity::sanitize_vimeo_id($video_id);
        return '' === $video_id
            ? null
            : Video_Embed_Identity::create($this->provider_id(), Video_Embed_Identity::VIMEO_ORIGIN, $video_id);
    }

    /** @param array<string,mixed> $parts */
    private static function standard_port(array $parts, string $scheme): bool
    {
        if (! isset($parts['port'])) {
            return true;
        }
        if (! is_int($parts['port'])) {
            return false;
        }

        return ('https' === $scheme && 443 === $parts['port'])
            || ('http' === $scheme && 80 === $parts['port']);
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

// EOF: includes/Vimeo_Embed_Provider.php
