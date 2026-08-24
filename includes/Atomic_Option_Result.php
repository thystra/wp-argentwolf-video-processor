<?php
/**
 * File: includes/Atomic_Option_Result.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Classified outcome of one consequential option mutation.
 */
final class Atomic_Option_Result
{
    public const APPLIED = 'applied';
    public const CONFLICT = 'conflict';
    public const INDETERMINATE = 'indeterminate';
    public const REFUSED = 'refused';

    public const MUTATION_NONE = 'none';
    public const MUTATION_APPLIED = 'applied';
    public const MUTATION_UNKNOWN = 'unknown';

    public const PHASE_VALIDATION = 'validation';
    public const PHASE_PRE_ACTION = 'pre_action';
    public const PHASE_SQL = 'sql';
    public const PHASE_POSTCONDITION = 'postcondition';
    public const PHASE_CACHE = 'cache';
    public const PHASE_POST_ACTION = 'post_action';
    public const PHASE_COMPLETE = 'complete';

    private function __construct(
        private string $status,
        private string $mutation,
        private string $phase,
        private ?Atomic_Option_Snapshot $before = null,
        private ?Atomic_Option_Snapshot $written = null
    ) {
    }

    public static function applied(
        ?Atomic_Option_Snapshot $before = null,
        ?Atomic_Option_Snapshot $written = null
    ): self {
        return new self(
            self::APPLIED,
            self::MUTATION_APPLIED,
            self::PHASE_COMPLETE,
            $before,
            $written
        );
    }

    /**
     * Report that the required postcondition already held without mutation.
     */
    public static function satisfied(): self
    {
        return new self(
            self::APPLIED,
            self::MUTATION_NONE,
            self::PHASE_COMPLETE
        );
    }

    public static function conflict(string $phase = self::PHASE_SQL): self
    {
        return new self(self::CONFLICT, self::MUTATION_NONE, $phase);
    }

    public static function indeterminate(string $mutation, string $phase): self
    {
        return new self(self::INDETERMINATE, $mutation, $phase);
    }

    public static function refused(string $phase = self::PHASE_VALIDATION): self
    {
        return new self(self::REFUSED, self::MUTATION_NONE, $phase);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function mutation(): string
    {
        return $this->mutation;
    }

    public function phase(): string
    {
        return $this->phase;
    }

    public function before(): ?Atomic_Option_Snapshot
    {
        return $this->before;
    }

    public function written(): ?Atomic_Option_Snapshot
    {
        return $this->written;
    }
}

// EOF: includes/Atomic_Option_Result.php
