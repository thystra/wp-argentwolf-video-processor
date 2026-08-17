<?php
/**
 * File: includes/Backend_Identity.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Backend_Identity
{
    public const MAX_BACKEND_ID_LENGTH = 64;

    public static function sanitize(mixed $value): string
    {
        if (! is_string($value) || strlen($value) > self::MAX_BACKEND_ID_LENGTH) {
            return '';
        }

        return 1 === preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $value)
            ? $value
            : '';
    }

    public static function sanitize_opaque(mixed $value, int $max_length): string
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
}

// EOF: includes/Backend_Identity.php
