<?php
/**
 * File: includes/Video_Embed_Identity.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Canonical, provider-neutral identity for one externally embedded video. */
final class Video_Embed_Identity
{
    public const VERSION = 1;
    public const PEERTUBE = 'peertube';
    public const YOUTUBE = 'youtube';
    public const VIMEO = 'vimeo';

    public const YOUTUBE_ORIGIN = 'https://www.youtube.com';
    public const VIMEO_ORIGIN = 'https://vimeo.com';

    /**
     * Build one normalized identity from an already-recognized provider token.
     *
     * @return array<string,mixed>|null
     */
    public static function create(string $provider, string $origin, string $video_id): ?array
    {
        if (self::PEERTUBE === $provider) {
            $origin = PeerTube_Origin::sanitize($origin);
            $video_id = self::sanitize_peertube_id($video_id);
            if ('' === $origin || '' === $video_id) {
                return null;
            }
            $canonical_url = $origin . '/videos/watch/' . rawurlencode($video_id);
            $embed_url = $origin . '/videos/embed/' . rawurlencode($video_id);
        } elseif (self::YOUTUBE === $provider) {
            if (self::YOUTUBE_ORIGIN !== $origin) {
                return null;
            }
            $video_id = self::sanitize_youtube_id($video_id);
            if ('' === $video_id) {
                return null;
            }
            $canonical_url = self::YOUTUBE_ORIGIN . '/watch?v=' . rawurlencode($video_id);
            $embed_url = 'https://www.youtube-nocookie.com/embed/' . rawurlencode($video_id);
        } elseif (self::VIMEO === $provider) {
            if (self::VIMEO_ORIGIN !== $origin) {
                return null;
            }
            $video_id = self::sanitize_vimeo_id($video_id);
            if ('' === $video_id) {
                return null;
            }
            $canonical_url = self::VIMEO_ORIGIN . '/' . rawurlencode($video_id);
            $embed_url = 'https://player.vimeo.com/video/' . rawurlencode($video_id);
        } else {
            return null;
        }

        return array(
            'version'         => self::VERSION,
            'provider'        => $provider,
            'provider_origin' => $origin,
            'video_id'        => $video_id,
            'canonical_key'   => self::key($provider, $origin, $video_id),
            'canonical_url'   => $canonical_url,
            'embed_url'       => $embed_url,
        );
    }

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value) || self::VERSION !== ($value['version'] ?? null)) {
            return array();
        }

        $provider = is_string($value['provider'] ?? null) ? $value['provider'] : '';
        $origin = is_string($value['provider_origin'] ?? null) ? $value['provider_origin'] : '';
        $video_id = is_string($value['video_id'] ?? null) ? $value['video_id'] : '';
        $canonical = self::create($provider, $origin, $video_id);

        return is_array($canonical) ? $canonical : array();
    }

    public static function key(string $provider, string $origin, string $video_id): string
    {
        return 'v1|' . $provider . '|' . $origin . '|' . $video_id;
    }

    public static function sanitize_peertube_id(mixed $value): string
    {
        if (! is_string($value) || '' === $value || trim($value) !== $value) {
            return '';
        }

        if (1 === preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[1-5][0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}$/D', $value)) {
            return strtolower($value);
        }
        if (1 === preg_match('/^[1-9][0-9]{0,19}$/D', $value)) {
            return $value;
        }

        return 1 === preg_match('/^[1-9A-HJ-NP-Za-km-z]{22}$/D', $value) ? $value : '';
    }

    public static function sanitize_youtube_id(mixed $value): string
    {
        return is_string($value) && 1 === preg_match('/^[A-Za-z0-9_-]{11}$/D', $value)
            ? $value
            : '';
    }

    public static function sanitize_vimeo_id(mixed $value): string
    {
        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]{0,19}$/D', $value)) {
            return '';
        }

        return $value;
    }
}

// EOF: includes/Video_Embed_Identity.php
