<?php
/**
 * File: includes/YouTube_Embed_Provider.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Recognizes common public YouTube URL forms without contacting YouTube. */
final class YouTube_Embed_Provider implements Video_Embed_Provider
{
    /** @var list<string> */
    private const YOUTUBE_HOSTS = array(
        'youtube.com',
        'www.youtube.com',
        'm.youtube.com',
        'music.youtube.com',
        'youtube-nocookie.com',
        'www.youtube-nocookie.com',
    );

    /** @var list<string> */
    private const SHORT_HOSTS = array('youtu.be', 'www.youtu.be');

    public function provider_id(): string
    {
        return Video_Embed_Identity::YOUTUBE;
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
        if (! in_array($scheme, array('http', 'https'), true) || ! self::standard_port($parts, $scheme)) {
            return null;
        }

        $video_id = '';
        $path = (string) ($parts['path'] ?? '');
        if (in_array($host, self::SHORT_HOSTS, true)) {
            if (1 === preg_match('#^/([^/]+)/?$#D', $path, $matches)) {
                $video_id = rawurldecode((string) $matches[1]);
            }
        } elseif (in_array($host, self::YOUTUBE_HOSTS, true)) {
            if ('/watch' === rtrim($path, '/')) {
                $query = array();
                parse_str((string) ($parts['query'] ?? ''), $query);
                $video_id = is_string($query['v'] ?? null) ? $query['v'] : '';
            } elseif (1 === preg_match('#^/(?:embed|shorts|live)/([^/]+)/?$#D', $path, $matches)) {
                $video_id = rawurldecode((string) $matches[1]);
            }
        }

        $video_id = Video_Embed_Identity::sanitize_youtube_id($video_id);
        return '' === $video_id
            ? null
            : Video_Embed_Identity::create($this->provider_id(), Video_Embed_Identity::YOUTUBE_ORIGIN, $video_id);
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

// EOF: includes/YouTube_Embed_Provider.php
