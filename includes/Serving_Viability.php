<?php
/**
 * File: includes/Serving_Viability.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Provider-independent result of probing whether a published serving URL is usable. */
final class Serving_Viability
{
    public const HEALTHY = 'healthy';
    public const PROCESSING = 'processing';
    public const MISSING = 'missing';
    public const PRIVATE_OR_RESTRICTED = 'private_or_restricted';
    public const EMBED_DISALLOWED = 'embed_disallowed';
    public const TEMPORARILY_UNAVAILABLE = 'temporarily_unavailable';
    public const PROBE_INDETERMINATE = 'probe_indeterminate';

    private const STATUSES = array(
        self::HEALTHY,
        self::PROCESSING,
        self::MISSING,
        self::PRIVATE_OR_RESTRICTED,
        self::EMBED_DISALLOWED,
        self::TEMPORARILY_UNAVAILABLE,
        self::PROBE_INDETERMINATE,
    );

    private function __construct(
        private readonly string $status,
        private readonly string $reason_code,
        private readonly string $message,
        private readonly int $http_status
    ) {
    }

    public static function create(
        string $status,
        string $reason_code = '',
        string $message = '',
        int $http_status = 0
    ): ?self {
        if (! in_array($status, self::STATUSES, true)) {
            return null;
        }
        $reason_code = self::reason($reason_code);
        $message = self::clean_message($message);
        if ($http_status < 0 || $http_status > 599) {
            return null;
        }
        if (self::HEALTHY === $status && ('' !== $reason_code || '' !== $message)) {
            return null;
        }
        if (self::HEALTHY !== $status && '' === $reason_code) {
            return null;
        }
        return new self($status, $reason_code, $message, $http_status);
    }

    public static function healthy(): self
    {
        return new self(self::HEALTHY, '', '', 200);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function reason_code(): string
    {
        return $this->reason_code;
    }

    public function message(): string
    {
        return $this->message;
    }

    public function http_status(): int
    {
        return $this->http_status;
    }

    public function viable(): bool
    {
        return self::HEALTHY === $this->status;
    }

    /** @return array{status:string,reason_code:string,message:string,http_status:int} */
    public function to_array(): array
    {
        return array(
            'status'      => $this->status,
            'reason_code' => $this->reason_code,
            'message'     => $this->message,
            'http_status' => $this->http_status,
        );
    }

    private static function reason(string $value): string
    {
        $value = strtolower(trim($value));
        return strlen($value) <= 64 && ('' === $value || 1 === preg_match('/^[a-z0-9._-]+$/D', $value))
            ? $value
            : '';
    }

    private static function clean_message(string $value): string
    {
        $value = trim($value);
        if ('' === $value || strlen($value) > 1000 || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) {
            return '';
        }
        return $value;
    }
}

// EOF: includes/Serving_Viability.php
