<?php
/**
 * File: includes/PeerTube_Connection_Operation_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * Durable, bounded journal for prospective PeerTube connections.
 *
 * The journal is deliberately inert: it performs no HTTP, secret, registry,
 * authorization, or retry work. Each mutation is one exact option CAS.
 */
final class PeerTube_Connection_Operation_Store
{
    public const OPTION = 'argentwolf_video_processor_peertube_connection_operations';
    public const VERSION = 1;
    public const MAX_OPERATIONS = 32;

    private const NONAUTOLOAD_VALUES = array('no', 'off', 'auto-off');

    /**
     * Begin one operation with all future local identities reserved before a
     * remote grant can be attempted.
     *
     * @param array<string, mixed> $intent
     * @return array{record:array<string,mixed>|null,result:Atomic_Option_Result}
     */
    public function begin(array $intent, int $actor_id, int $now): array
    {
        if (! self::has_exact_keys($intent, array('backend_id', 'origin', 'label'))) {
            return self::begin_result(null, Atomic_Option_Result::refused());
        }

        try {
            $record = PeerTube_Connection_State_Machine::create(
                array(
                    'operation_id'   => 'connection_' . bin2hex(random_bytes(16)),
                    'backend_id'     => $intent['backend_id'],
                    'origin'         => $intent['origin'],
                    'label'          => $intent['label'],
                    'secret_ref'     => 'managed_' . bin2hex(random_bytes(16)),
                    'provisioning_id'=> 'provision_' . bin2hex(random_bytes(16)),
                ),
                $actor_id,
                $now
            );
        } catch (Throwable) {
            $record = null;
        }

        if (null === $record) {
            return self::begin_result(null, Atomic_Option_Result::refused());
        }

        $store = new Atomic_Option_Store(self::OPTION);
        $snapshot = $store->snapshot();
        $journal = $this->journal_from_snapshot($snapshot);
        if (null === $journal) {
            return self::begin_result($record, self::snapshot_failure($snapshot));
        }

        if (count($journal['operations']) >= self::MAX_OPERATIONS) {
            return self::begin_result($record, Atomic_Option_Result::refused());
        }

        foreach ($journal['operations'] as $stored) {
            if (
                $record['secret_ref'] === $stored['secret_ref']
                || $record['provisioning_id'] === $stored['provisioning_id']
            ) {
                return self::begin_result(
                    $record,
                    Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION)
                );
            }

            if (
                PeerTube_Connection_State_Machine::PHASE_COMPLETE !== $stored['phase']
                && $record['backend_id'] === $stored['backend_id']
            ) {
                return self::begin_result($record, Atomic_Option_Result::refused());
            }
        }

        if (array_key_exists($record['operation_id'], $journal['operations'])) {
            return self::begin_result(
                $record,
                Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION)
            );
        }

        $journal['operations'][$record['operation_id']] = $record;
        if (! self::valid_journal($journal)) {
            return self::begin_result($record, Atomic_Option_Result::refused());
        }

        return self::begin_result(
            $record,
            $store->compare_exchange($snapshot, $journal)
        );
    }

    /**
     * Apply one allowlisted state-machine event at an exact record revision.
     *
     * @param array<string, mixed> $payload
     */
    public function apply_event(
        string $operation_id,
        int $expected_revision,
        string $event,
        array $payload,
        int $now
    ): Atomic_Option_Result {
        if ('' === self::operation_id($operation_id) || $expected_revision < 1) {
            return Atomic_Option_Result::refused();
        }

        $store = new Atomic_Option_Store(self::OPTION);
        $snapshot = $store->snapshot();
        $journal = $this->journal_from_snapshot($snapshot);
        if (null === $journal) {
            return self::snapshot_failure($snapshot);
        }

        $record = $journal['operations'][$operation_id] ?? null;
        if (
            ! is_array($record)
            || $expected_revision !== ($record['record_revision'] ?? null)
        ) {
            return Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
        }

        $next = PeerTube_Connection_State_Machine::apply($record, $event, $payload, $now);
        if (null === $next) {
            return Atomic_Option_Result::refused();
        }

        $journal['operations'][$operation_id] = $next;
        if (! self::valid_journal($journal)) {
            return Atomic_Option_Result::refused();
        }

        return $store->compare_exchange($snapshot, $journal);
    }

    /**
     * Remove only an exact completed record. Unresolved records are never
     * evicted to make room for new setup.
     */
    public function remove_complete(
        string $operation_id,
        int $expected_revision
    ): Atomic_Option_Result {
        if ('' === self::operation_id($operation_id) || $expected_revision < 1) {
            return Atomic_Option_Result::refused();
        }

        $store = new Atomic_Option_Store(self::OPTION);
        $snapshot = $store->snapshot();
        $journal = $this->journal_from_snapshot($snapshot);
        if (null === $journal) {
            return self::snapshot_failure($snapshot);
        }

        $record = $journal['operations'][$operation_id] ?? null;
        if (
            ! is_array($record)
            || $expected_revision !== ($record['record_revision'] ?? null)
        ) {
            return Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
        }

        if (PeerTube_Connection_State_Machine::PHASE_COMPLETE !== ($record['phase'] ?? '')) {
            return Atomic_Option_Result::refused();
        }

        unset($journal['operations'][$operation_id]);
        return $store->compare_exchange($snapshot, $journal);
    }

    /** @return array<string, mixed>|null */
    public function get(string $operation_id): ?array
    {
        if ('' === self::operation_id($operation_id)) {
            return null;
        }

        $snapshot = (new Atomic_Option_Store(self::OPTION))->snapshot();
        $journal = $this->journal_from_snapshot($snapshot);
        if (null === $journal) {
            return null;
        }

        return $journal['operations'][$operation_id] ?? null;
    }

    /** @return array<string, array<string, mixed>>|null */
    public function open_operations(): ?array
    {
        $snapshot = (new Atomic_Option_Store(self::OPTION))->snapshot();
        $journal = $this->journal_from_snapshot($snapshot);
        if (null === $journal) {
            return null;
        }

        return array_filter(
            $journal['operations'],
            static fn (array $record): bool =>
                PeerTube_Connection_State_Machine::PHASE_COMPLETE !== $record['phase']
        );
    }

    /**
     * @return array{version:int,operations:array<string,array<string,mixed>>}|null
     */
    private function journal_from_snapshot(Atomic_Option_Snapshot $snapshot): ?array
    {
        if ($snapshot->is_absent()) {
            return array(
                'version'    => self::VERSION,
                'operations' => array(),
            );
        }

        if (
            ! $snapshot->is_present()
            || ! in_array(
                (string) $snapshot->autoload(),
                self::NONAUTOLOAD_VALUES,
                true
            )
        ) {
            return null;
        }

        $journal = $snapshot->value();
        return self::valid_journal($journal) ? $journal : null;
    }

    private static function snapshot_failure(
        Atomic_Option_Snapshot $snapshot
    ): Atomic_Option_Result {
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_NONE,
                Atomic_Option_Result::PHASE_VALIDATION
            );
        }

        return Atomic_Option_Result::refused();
    }

    /**
     * @param mixed $journal
     */
    private static function valid_journal(mixed $journal): bool
    {
        if (
            ! is_array($journal)
            || ! self::has_exact_keys($journal, array('version', 'operations'))
            || self::VERSION !== $journal['version']
            || ! is_array($journal['operations'])
            || count($journal['operations']) > self::MAX_OPERATIONS
        ) {
            return false;
        }

        $open_backends = array();
        $secret_refs = array();
        $provisioning_ids = array();
        foreach ($journal['operations'] as $operation_id => $record) {
            if (
                ! is_string($operation_id)
                || '' === self::operation_id($operation_id)
                || ! PeerTube_Connection_State_Machine::valid($record)
                || $operation_id !== $record['operation_id']
            ) {
                return false;
            }

            if (
                isset($secret_refs[$record['secret_ref']])
                || isset($provisioning_ids[$record['provisioning_id']])
            ) {
                return false;
            }
            $secret_refs[$record['secret_ref']] = true;
            $provisioning_ids[$record['provisioning_id']] = true;

            if (PeerTube_Connection_State_Machine::PHASE_COMPLETE === $record['phase']) {
                continue;
            }

            if (isset($open_backends[$record['backend_id']])) {
                return false;
            }
            $open_backends[$record['backend_id']] = true;
        }

        return true;
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array{record:array<string,mixed>|null,result:Atomic_Option_Result}
     */
    private static function begin_result(
        ?array $record,
        Atomic_Option_Result $result
    ): array {
        return array(
            'record' => $record,
            'result' => $result,
        );
    }

    /** @param list<string> $expected */
    private static function has_exact_keys(array $value, array $expected): bool
    {
        if (count($value) !== count($expected)) {
            return false;
        }

        foreach ($expected as $key) {
            if (! array_key_exists($key, $value)) {
                return false;
            }
        }

        foreach (array_keys($value) as $key) {
            if (! is_string($key) || ! in_array($key, $expected, true)) {
                return false;
            }
        }

        return true;
    }

    private static function operation_id(mixed $value): string
    {
        return is_string($value)
            && 1 === preg_match('/^connection_[a-f0-9]{32}$/D', $value)
                ? $value
                : '';
    }
}

// EOF: includes/PeerTube_Connection_Operation_Store.php
