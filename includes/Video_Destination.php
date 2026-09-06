<?php
/**
 * File: includes/Video_Destination.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Canonical per-video destination binding.
 *
 * R46 deliberately separates a video's selected final destination from the
 * currently serving copy. A PeerTube-bound video may continue to serve the
 * local WordPress asset until remote readiness/publication cutover succeeds.
 *
 * Legacy safety rule: absence of destination metadata resolves to the built-in
 * local backend. A malformed *present* value does not silently fall back to
 * another destination.
 */
final class Video_Destination
{
    public const VERSION = 1;

    /** @return array{version:int,backend_id:string} */
    public static function local(): array
    {
        return array(
            'version'    => self::VERSION,
            'backend_id' => Backend_Registry::LOCAL_ID,
        );
    }

    /**
     * @return array{version:int,backend_id:string,channel_id?:string}|array{}
     */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $version = self::positive_int($value['version'] ?? null);
        $backend_id = Backend_Identity::sanitize($value['backend_id'] ?? null);
        if (self::VERSION !== $version || '' === $backend_id) {
            return array();
        }

        $result = array(
            'version'    => self::VERSION,
            'backend_id' => $backend_id,
        );

        if (Backend_Registry::LOCAL_ID === $backend_id) {
            // Local is a complete destination; a remote channel on the local
            // backend is malformed rather than something we silently discard.
            return array_key_exists('channel_id', $value) ? array() : $result;
        }

        if (array_key_exists('channel_id', $value)) {
            $channel_id = self::opaque_identifier($value['channel_id'], 191);
            if ('' === $channel_id) {
                return array();
            }
            $result['channel_id'] = $channel_id;
        }

        return $result;
    }

    /**
     * Resolve stored metadata without allowing a later site-default change to
     * reroute legacy videos.
     *
     * @return array{version:int,backend_id:string,channel_id?:string}|array{}
     */
    public static function resolve(mixed $stored_value, bool $metadata_exists): array
    {
        if (! $metadata_exists) {
            return self::local();
        }

        return self::sanitize($stored_value);
    }

    /** @param array<string,mixed> $destination */
    public static function is_local(array $destination): bool
    {
        return self::local() === self::sanitize($destination);
    }

    private static function positive_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }

        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return 0;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        return false !== $parsed && (string) $parsed === $value ? (int) $parsed : 0;
    }

    private static function opaque_identifier(mixed $value, int $max_length): string
    {
        if (
            ! is_string($value)
            || '' === $value
            || trim($value) !== $value
            || $max_length < 1
            || str_contains($value, '<')
            || str_contains($value, '>')
        ) {
            return '';
        }

        if (1 !== preg_match('//u', $value) || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        return $length <= $max_length ? $value : '';
    }
}

// EOF: includes/Video_Destination.php
