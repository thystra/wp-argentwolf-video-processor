<?php
/**
 * File: includes/Atomic_Option_Plan_Result.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Classified result of prospectively preparing an option mutation.
 */
final class Atomic_Option_Plan_Result
{
    public const READY = 'ready';
    public const CONFLICT = 'conflict';
    public const INDETERMINATE = 'indeterminate';
    public const REFUSED = 'refused';

    private function __construct(
        private readonly string $status,
        private readonly ?Atomic_Option_Mutation_Plan $plan = null
    ) {
    }

    public static function ready(Atomic_Option_Mutation_Plan $plan): self
    {
        return new self(self::READY, $plan);
    }

    public static function conflict(): self
    {
        return new self(self::CONFLICT);
    }

    public static function indeterminate(): self
    {
        return new self(self::INDETERMINATE);
    }

    public static function refused(): self
    {
        return new self(self::REFUSED);
    }

    public function status(): string
    {
        return $this->status;
    }

    public function plan(): ?Atomic_Option_Mutation_Plan
    {
        return $this->plan;
    }
}

// EOF: includes/Atomic_Option_Plan_Result.php
