<?php
/**
 * File: includes/Atomic_Option_Mutation_Plan.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Request-local authority for one exact prospective option mutation.
 *
 * The plan is intentionally non-serializable and single-use. Its public
 * evidence contains only hashes, byte counts, and bounded machine identifiers.
 * Exact raw values remain encapsulated in immutable snapshots and are never
 * copied into that evidence.
 */
final class Atomic_Option_Mutation_Plan
{
    /** @var \WeakMap<self,array{before:Atomic_Option_Snapshot,written:Atomic_Option_Snapshot}>|null */
    private static ?\WeakMap $snapshot_authority = null;

    private bool $consumed = false;

    /**
     * Factory for the reviewed atomic option planner.
     *
     * @param array<string, mixed> $evidence
     * @internal
     */
    public static function create(
        string $option,
        string $kind,
        string $mutation_id,
        Atomic_Option_Snapshot $before,
        Atomic_Option_Snapshot $written,
        array $evidence
    ): self {
        return new self(
            $option,
            $kind,
            $mutation_id,
            $before,
            $written,
            $evidence
        );
    }

    /** @param array<string, mixed> $evidence */
    private function __construct(
        private readonly string $option,
        private readonly string $kind,
        private readonly string $mutation_id,
        Atomic_Option_Snapshot $before,
        Atomic_Option_Snapshot $written,
        private readonly array $evidence
    ) {
        self::$snapshot_authority ??= new \WeakMap();
        self::$snapshot_authority[$this] = array(
            'before'  => $before,
            'written' => $written,
        );
    }

    public function option(): string
    {
        return $this->option;
    }

    public function kind(): string
    {
        return $this->kind;
    }

    public function mutation_id(): string
    {
        return $this->mutation_id;
    }

    public function before(): Atomic_Option_Snapshot
    {
        return $this->snapshots()['before'];
    }

    public function written(): Atomic_Option_Snapshot
    {
        return $this->snapshots()['written'];
    }

    /** @return array<string, mixed> */
    public function evidence(): array
    {
        return $this->evidence;
    }

    /**
     * Consume this request-local authority exactly once.
     *
     * @internal Only Atomic_Option_Store::apply_plan() should call this method.
     */
    public function consume(): bool
    {
        if ($this->consumed) {
            return false;
        }

        $this->consumed = true;
        return true;
    }

    /**
     * Keep accidental debug output bounded to the journal-safe evidence.
     *
     * The request-local snapshots can contain encrypted secret records in
     * later phases, so PHP's default recursive object dump is not suitable.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return array(
            'option'   => $this->option,
            'consumed' => $this->consumed,
            'evidence' => $this->evidence,
        );
    }

    /** @return array<never, never> */
    public function __serialize(): array
    {
        throw new \LogicException(
            'Atomic option mutation plans are request-local and cannot be serialized.'
        );
    }

    /** @param array<mixed> $data */
    public function __unserialize(array $data): void
    {
        unset($data);
        throw new \LogicException(
            'Atomic option mutation plans are request-local and cannot be unserialized.'
        );
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): self
    {
        unset($properties);
        throw new \LogicException(
            'Atomic option mutation plans cannot be reconstructed from exported state.'
        );
    }

    /**
     * @return array{before:Atomic_Option_Snapshot,written:Atomic_Option_Snapshot}
     */
    private function snapshots(): array
    {
        $snapshots = self::$snapshot_authority[$this] ?? null;
        if (
            ! is_array($snapshots)
            || ! ($snapshots['before'] ?? null) instanceof Atomic_Option_Snapshot
            || ! ($snapshots['written'] ?? null) instanceof Atomic_Option_Snapshot
        ) {
            throw new \LogicException('Atomic option mutation plan authority is unavailable.');
        }

        return $snapshots;
    }

    private function __clone()
    {
    }
}

// EOF: includes/Atomic_Option_Mutation_Plan.php
