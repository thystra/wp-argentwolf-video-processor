<?php
/**
 * File: includes/Atomic_Option_Snapshot.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * One raw database observation of a bounded array-valued WordPress option.
 *
 * The raw serialized bytes and autoload value form the compare-and-swap token.
 * Callers must not reconstruct either value from get_option().
 */
final class Atomic_Option_Snapshot
{
    public const PRESENT = 'present';
    public const ABSENT = 'absent';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';

    /** @param array<string|int, mixed>|null $value */
    private function __construct(
        private string $option,
        private string $state,
        private ?string $raw,
        private ?string $autoload,
        private ?array $value
    ) {
    }

    public static function absent(string $option): self
    {
        return new self($option, self::ABSENT, null, null, null);
    }

    /** @param array<string|int, mixed> $value */
    public static function present(
        string $option,
        string $raw,
        string $autoload,
        array $value
    ): self {
        return new self($option, self::PRESENT, $raw, $autoload, $value);
    }

    public static function refused(string $option): self
    {
        return new self($option, self::REFUSED, null, null, null);
    }

    public static function indeterminate(string $option): self
    {
        return new self($option, self::INDETERMINATE, null, null, null);
    }

    public function option(): string
    {
        return $this->option;
    }

    public function state(): string
    {
        return $this->state;
    }

    public function is_present(): bool
    {
        return self::PRESENT === $this->state;
    }

    public function is_absent(): bool
    {
        return self::ABSENT === $this->state;
    }

    public function raw(): ?string
    {
        return $this->raw;
    }

    public function autoload(): ?string
    {
        return $this->autoload;
    }

    /** @return array<string|int, mixed>|null */
    public function value(): ?array
    {
        return $this->value;
    }
}

// EOF: includes/Atomic_Option_Snapshot.php
