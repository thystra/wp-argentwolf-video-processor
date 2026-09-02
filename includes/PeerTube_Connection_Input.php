<?php
/**
 * File: includes/PeerTube_Connection_Input.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Shared exact validators for the PeerTube connection request boundary.
 *
 * These methods reject invalid values; they never normalize a credential or
 * silently rewrite an operation selector supplied by an administrator.
 */
final class PeerTube_Connection_Input
{
    public const MAX_GRANT_ATTEMPTS = 8;
    public const MAX_USERNAME_BYTES = 1024;
    public const MAX_PASSWORD_BYTES = 16384;
    public const MAX_LABEL_BYTES = 480;
    public const MAX_LABEL_CHARACTERS = 120;

    public static function operation_id(mixed $value): string
    {
        return is_string($value)
            && 1 === preg_match('/^connection_[a-f0-9]{32}$/D', $value)
                ? $value
                : '';
    }

    /**
     * PeerTube destination identifiers are retained as exact opaque decimal
     * strings. Rejecting non-canonical input prevents a browser or service
     * boundary from silently rewriting the selected authority.
     */
    public static function destination_id(mixed $value): string
    {
        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return '';
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        return false !== $parsed && (string) $parsed === $value ? $value : '';
    }

    public static function label(mixed $value): string
    {
        if (
            ! is_string($value)
            || '' === $value
            || trim($value) !== $value
            || strlen($value) > self::MAX_LABEL_BYTES
            || 1 !== preg_match('//u', $value)
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)
            || str_contains($value, '<')
            || str_contains($value, '>')
        ) {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        return $length <= self::MAX_LABEL_CHARACTERS ? $value : '';
    }

    public static function valid_credentials(
        mixed $username,
        mixed $password,
        mixed $otp
    ): bool {
        return self::valid_username($username)
            && self::valid_password($password)
            && self::valid_otp($otp);
    }

    public static function valid_username(mixed $value): bool
    {
        return is_string($value)
            && self::bounded_request_text($value, self::MAX_USERNAME_BYTES, false);
    }

    public static function valid_password(mixed $value): bool
    {
        return is_string($value)
            && self::bounded_request_text($value, self::MAX_PASSWORD_BYTES, true);
    }

    public static function valid_otp(mixed $value): bool
    {
        return is_string($value)
            && ('' === $value || 1 === preg_match('/^[0-9]{6}$/D', $value));
    }

    public static function phase(mixed $value): string
    {
        return is_string($value) && in_array($value, self::phases(), true)
            ? $value
            : '';
    }

    /** @return list<string> */
    private static function phases(): array
    {
        return array(
            PeerTube_Connection_State_Machine::PHASE_PREPARED,
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED,
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVED,
            PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED,
            PeerTube_Connection_State_Machine::PHASE_DISABLED,
            PeerTube_Connection_State_Machine::PHASE_GRANT_IN_FLIGHT,
            PeerTube_Connection_State_Machine::PHASE_OTP_RESULT_PENDING,
            PeerTube_Connection_State_Machine::PHASE_CREDENTIAL_RESULT_PENDING,
            PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP,
            PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS,
            PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE,
            PeerTube_Connection_State_Machine::PHASE_SECRET_WRITE_PLANNED,
            PeerTube_Connection_State_Machine::PHASE_SECRET_STORED,
            PeerTube_Connection_State_Machine::PHASE_VERIFICATION_IN_FLIGHT,
            PeerTube_Connection_State_Machine::PHASE_VERIFICATION_FAILED,
            PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION,
            PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY,
            PeerTube_Connection_State_Machine::PHASE_ACTIVATION_PLANNED,
            PeerTube_Connection_State_Machine::PHASE_ACTIVE_PENDING_CLOSE,
            PeerTube_Connection_State_Machine::PHASE_COMPLETE,
        );
    }

    private static function bounded_request_text(
        string $value,
        int $maximum_bytes,
        bool $allow_whitespace
    ): bool {
        if (
            '' === $value
            || strlen($value) > $maximum_bytes
            || 1 !== preg_match('//u', $value)
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            return false;
        }

        return $allow_whitespace || 1 !== preg_match('/\s/u', $value);
    }
}

// EOF: includes/PeerTube_Connection_Input.php
