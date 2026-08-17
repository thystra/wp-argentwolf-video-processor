<?php
/**
 * File: includes/Video_Meta.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Video_Meta
{
    public const ATTACHMENT_ID = '_argent_video_attachment_id';
    public const ORIGIN_POST_ID = '_argent_video_origin_post_id';
    public const ORIGIN_SEQUENCE = '_argent_video_origin_sequence';
    public const INGEST_KIND = '_argent_video_ingest_kind';
    public const MASTER_AUTHORITY = '_argent_video_master_authority';
    public const SOURCE_STATE = '_argent_video_source_state';
    public const DESTINATION = '_argent_video_destination';
    public const PROFILE_SNAPSHOT = '_argent_video_profile_snapshot';
    public const PUBLICATION_POLICY = '_argent_video_publication_policy';
    public const METADATA_ORIGIN = '_argent_video_metadata_origin';
    public const CLEANUP_STATE = '_argent_video_cleanup_state';
    public const LAST_ERROR = '_argent_video_last_error';

    public static function register(): void
    {
        foreach (self::definitions() as $meta_key => $args) {
            register_post_meta(Video_Post_Type::POST_TYPE, $meta_key, $args);
        }
    }

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $base = array(
            'single'        => true,
            'show_in_rest'  => false,
            'auth_callback' => array(self::class, 'authorize'),
        );

        return array(
            self::ATTACHMENT_ID => $base + array(
                'type'              => 'integer',
                'sanitize_callback' => array(self::class, 'sanitize_positive_id'),
            ),
            self::ORIGIN_POST_ID => $base + array(
                'type'              => 'integer',
                'sanitize_callback' => array(self::class, 'sanitize_positive_id'),
            ),
            self::ORIGIN_SEQUENCE => $base + array(
                'type'              => 'integer',
                'sanitize_callback' => array(self::class, 'sanitize_positive_id'),
            ),
            self::INGEST_KIND => $base + array(
                'type'              => 'string',
                'sanitize_callback' => array(self::class, 'sanitize_ingest_kind'),
            ),
            self::MASTER_AUTHORITY => $base + array(
                'type'              => 'string',
                'sanitize_callback' => array(self::class, 'sanitize_master_authority'),
            ),
            self::SOURCE_STATE => $base + array(
                'type'              => 'string',
                'sanitize_callback' => array(self::class, 'sanitize_source_state'),
            ),
            self::DESTINATION => $base + array(
                'type'              => 'array',
                'sanitize_callback' => array(self::class, 'sanitize_destination'),
            ),
            self::PROFILE_SNAPSHOT => $base + array(
                'type'              => 'array',
                'sanitize_callback' => array(self::class, 'sanitize_profile_snapshot'),
            ),
            self::PUBLICATION_POLICY => $base + array(
                'type'              => 'array',
                'sanitize_callback' => array(self::class, 'sanitize_publication_policy'),
            ),
            self::METADATA_ORIGIN => $base + array(
                'type'              => 'array',
                'sanitize_callback' => array(self::class, 'sanitize_metadata_origin'),
            ),
            self::CLEANUP_STATE => $base + array(
                'type'              => 'string',
                'sanitize_callback' => array(self::class, 'sanitize_cleanup_state'),
            ),
            self::LAST_ERROR => $base + array(
                'type'              => 'string',
                'sanitize_callback' => array(self::class, 'sanitize_last_error'),
            ),
        );
    }

    /** @param array<int, string> $caps */
    public static function authorize(
        mixed $allowed,
        string $meta_key,
        int $post_id,
        int $user_id,
        string $cap = '',
        array $caps = array()
    ): bool {
        unset($allowed, $meta_key, $cap, $caps);

        return $post_id > 0
            && $user_id > 0
            && user_can($user_id, 'edit_post', $post_id);
    }

    public static function sanitize_positive_id(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }

        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return 0;
        }

        $max = (string) PHP_INT_MAX;
        if (
            strlen($value) > strlen($max)
            || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)
        ) {
            return 0;
        }

        return (int) $value;
    }

    public static function sanitize_ingest_kind(mixed $value): string
    {
        return self::enum(
            $value,
            array('wordpress_attachment', 'wordpress_staging', 'direct_backend_upload', 'existing_remote', 'unknown'),
            'unknown'
        );
    }

    public static function sanitize_master_authority(mixed $value): string
    {
        return self::enum(
            $value,
            array('external_archive', 'wordpress_source', 'backend_source', 'none', 'unknown'),
            'unknown'
        );
    }

    public static function sanitize_source_state(mixed $value): string
    {
        return self::enum(
            $value,
            array('present', 'uploading', 'verified_remote', 'cleanup_pending', 'removed', 'missing', 'error'),
            'error'
        );
    }

    /** @return array<string, mixed> */
    public static function sanitize_destination(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $backend_id = self::sanitize_backend_id($value['backend_id'] ?? '');
        if ('' === $backend_id) {
            return array();
        }

        $result = array(
            'version'    => max(1, absint($value['version'] ?? 1)),
            'backend_id' => $backend_id,
        );

        if (array_key_exists('channel_id', $value)) {
            $channel_id = self::sanitize_remote_identifier($value['channel_id'], 191);
            if ('' === $channel_id) {
                return array();
            }
            $result['channel_id'] = $channel_id;
        }

        return $result;
    }

    /** @return array<string|int, mixed> */
    public static function sanitize_profile_snapshot(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $sanitized = self::sanitize_structure($value);
        if (! is_array($sanitized)) {
            return array();
        }

        $sanitized['version'] = max(1, absint($value['version'] ?? 1));
        return $sanitized;
    }

    /** @return array<string, mixed> */
    public static function sanitize_publication_policy(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $mode = self::enum(
            $value['mode'] ?? '',
            array('immediate', 'publish_with_post', 'private', 'unlisted', 'manual'),
            'manual'
        );
        $anchor_post_id = self::sanitize_positive_id($value['anchor_post_id'] ?? 0);

        if ('publish_with_post' === $mode && $anchor_post_id < 1) {
            $mode = 'manual';
        }

        $result = array(
            'version' => max(1, absint($value['version'] ?? 1)),
            'mode'    => $mode,
        );

        if ($anchor_post_id > 0) {
            $result['anchor_post_id'] = $anchor_post_id;
        }

        return $result;
    }

    /** @return array<string, mixed> */
    public static function sanitize_metadata_origin(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $fields = $value['fields'] ?? array();
        if (! is_array($fields)) {
            $fields = array();
        }

        $sanitized_fields = array();
        foreach ($fields as $key => $manual) {
            $field = sanitize_key((string) $key);
            $manual_flag = self::sanitize_boolean($manual);
            if ('' !== $field && null !== $manual_flag) {
                $sanitized_fields[$field] = $manual_flag;
            }
        }

        return array(
            'version' => max(1, absint($value['version'] ?? 1)),
            'fields'  => $sanitized_fields,
        );
    }

    public static function sanitize_cleanup_state(mixed $value): string
    {
        return self::enum(
            $value,
            array('none', 'pending', 'eligible', 'running', 'complete', 'blocked', 'failed'),
            'none'
        );
    }

    public static function sanitize_last_error(mixed $value): string
    {
        $message = sanitize_text_field((string) $value);
        return function_exists('mb_substr') ? mb_substr($message, 0, 2000) : substr($message, 0, 2000);
    }

    public static function sanitize_backend_id(mixed $value): string
    {
        return Backend_Identity::sanitize($value);
    }

    public static function sanitize_remote_identifier(mixed $value, int $max_length): string
    {
        if (! is_string($value) || '' === $value || $max_length < 1) {
            return '';
        }

        $sanitized = sanitize_text_field($value);
        if ($sanitized !== $value) {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        return $length <= $max_length ? $value : '';
    }

    private static function sanitize_boolean(mixed $value): ?bool
    {
        if (true === $value || 1 === $value || '1' === $value || 'true' === $value) {
            return true;
        }

        if (false === $value || 0 === $value || '0' === $value || 'false' === $value) {
            return false;
        }

        return null;
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed, string $fallback): string
    {
        $candidate = sanitize_key((string) $value);
        return in_array($candidate, $allowed, true) ? $candidate : $fallback;
    }

    private static function sanitize_structure(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 6) {
            return null;
        }

        if (is_bool($value) || is_int($value) || is_float($value) || null === $value) {
            return $value;
        }

        if (is_string($value)) {
            return sanitize_text_field($value);
        }

        if (! is_array($value)) {
            return null;
        }

        $result = array();
        foreach ($value as $key => $item) {
            if (is_int($key)) {
                $clean_key = $key;
            } else {
                $clean_key = sanitize_key((string) $key);
                if ('' === $clean_key) {
                    continue;
                }
            }

            $clean_item = self::sanitize_structure($item, $depth + 1);
            if (null !== $clean_item || null === $item) {
                $result[$clean_key] = $clean_item;
            }
        }

        return $result;
    }
}

// EOF: includes/Video_Meta.php
