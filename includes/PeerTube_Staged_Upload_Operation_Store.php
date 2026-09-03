<?php
/**
 * File: includes/PeerTube_Staged_Upload_Operation_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * Exact-CAS journal for R42 staged-upload state-machine records.
 *
 * No media transfer, remote API call, retry loop, task scheduling, or cleanup
 * occurs here. Completed records are retained in this checkpoint so the local
 * intent commitment remains a duplicate-creation fence.
 */
final class PeerTube_Staged_Upload_Operation_Store
{
    public const OPTION = 'argentwolf_video_processor_peertube_upload_operations';
    public const VERSION = 1;
    public const MAX_OPERATIONS = 128;

    public const PROBE_PRESENT = 'present';
    public const PROBE_ABSENT = 'absent';
    public const PROBE_REFUSED = 'refused';
    public const PROBE_INDETERMINATE = 'indeterminate';

    private const NONAUTOLOAD_VALUES = array('no', 'off', 'auto-off');
    private const MAX_JOURNAL_BYTES = 1048576;

    /**
     * @param array<string,mixed> $intent
     * @return array{record:array<string,mixed>|null,result:Atomic_Option_Result}
     */
    public function begin(array $intent, int $actor_id, int $now): array
    {
        if (! self::has_exact_keys(
            $intent,
            array('video_post_id', 'backend_id', 'origin', 'destination_id', 'source')
        )) {
            return self::begin_result(null, Atomic_Option_Result::refused());
        }

        try {
            $record = PeerTube_Staged_Upload_State_Machine::create(
                array(
                    'operation_id'   => 'upload_' . bin2hex(random_bytes(16)),
                    'video_post_id'  => $intent['video_post_id'],
                    'backend_id'     => $intent['backend_id'],
                    'origin'         => $intent['origin'],
                    'destination_id' => $intent['destination_id'],
                    'source'         => $intent['source'],
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

        $store = new Atomic_Option_Store(self::OPTION, self::MAX_JOURNAL_BYTES);
        $snapshot = $store->snapshot();
        $journal = $this->journal_from_snapshot($snapshot);
        if (null === $journal) {
            return self::begin_result($record, self::snapshot_failure($snapshot));
        }

        if (count($journal['operations']) >= self::MAX_OPERATIONS) {
            return self::begin_result($record, Atomic_Option_Result::refused());
        }

        foreach ($journal['operations'] as $stored) {
            if (hash_equals($stored['intent_sha256'], $record['intent_sha256'])) {
                return self::begin_result(
                    $record,
                    Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION)
                );
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

        return self::begin_result($record, $store->compare_exchange($snapshot, $journal));
    }

    /** @param array<string,mixed> $payload */
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

        $store = new Atomic_Option_Store(self::OPTION, self::MAX_JOURNAL_BYTES);
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

        $next = PeerTube_Staged_Upload_State_Machine::apply($record, $event, $payload, $now);
        if (null === $next) {
            return Atomic_Option_Result::refused();
        }

        $journal['operations'][$operation_id] = $next;
        if (! self::valid_journal($journal)) {
            return Atomic_Option_Result::refused();
        }

        return $store->compare_exchange($snapshot, $journal);
    }

    /** @return array<string,mixed>|null */
    public function get(string $operation_id): ?array
    {
        $probe = $this->probe($operation_id);
        return self::PROBE_PRESENT === $probe['status'] ? $probe['record'] : null;
    }

    /** @return array{status:string,record:array<string,mixed>|null} */
    public function probe(string $operation_id): array
    {
        if ('' === self::operation_id($operation_id)) {
            return self::probe_result(self::PROBE_REFUSED);
        }

        $snapshot = (new Atomic_Option_Store(self::OPTION, self::MAX_JOURNAL_BYTES))->snapshot();
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return self::probe_result(self::PROBE_INDETERMINATE);
        }
        if (Atomic_Option_Snapshot::REFUSED === $snapshot->state()) {
            return self::probe_result(self::PROBE_REFUSED);
        }

        $journal = $this->journal_from_snapshot($snapshot);
        if (null === $journal) {
            return self::probe_result(self::PROBE_REFUSED);
        }

        $record = $journal['operations'][$operation_id] ?? null;
        return is_array($record)
            ? self::probe_result(self::PROBE_PRESENT, $record)
            : self::probe_result(self::PROBE_ABSENT);
    }

    /** @return array<string,array<string,mixed>>|null */
    public function open_operations(): ?array
    {
        $snapshot = (new Atomic_Option_Store(self::OPTION, self::MAX_JOURNAL_BYTES))->snapshot();
        $journal = $this->journal_from_snapshot($snapshot);
        if (null === $journal) {
            return null;
        }

        return array_filter(
            $journal['operations'],
            static fn (array $record): bool =>
                PeerTube_Staged_Upload_State_Machine::PHASE_COMPLETE !== $record['phase']
        );
    }

    /** @return array{version:int,operations:array<string,array<string,mixed>>}|null */
    private function journal_from_snapshot(Atomic_Option_Snapshot $snapshot): ?array
    {
        if ($snapshot->is_absent()) {
            return array('version' => self::VERSION, 'operations' => array());
        }

        if (
            ! $snapshot->is_present()
            || ! in_array((string) $snapshot->autoload(), self::NONAUTOLOAD_VALUES, true)
        ) {
            return null;
        }

        $journal = $snapshot->value();
        return self::valid_journal($journal) ? $journal : null;
    }

    private static function snapshot_failure(Atomic_Option_Snapshot $snapshot): Atomic_Option_Result
    {
        return Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()
            ? Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_NONE,
                Atomic_Option_Result::PHASE_VALIDATION
            )
            : Atomic_Option_Result::refused();
    }

    /** @param mixed $journal */
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

        $intent_hashes = array();
        foreach ($journal['operations'] as $operation_id => $record) {
            if (
                ! is_string($operation_id)
                || '' === self::operation_id($operation_id)
                || ! PeerTube_Staged_Upload_State_Machine::valid($record)
                || $operation_id !== $record['operation_id']
                || isset($intent_hashes[$record['intent_sha256']])
            ) {
                return false;
            }
            $intent_hashes[$record['intent_sha256']] = true;
        }

        return strlen(serialize($journal)) <= self::MAX_JOURNAL_BYTES;
    }

    /** @return array{record:array<string,mixed>|null,result:Atomic_Option_Result} */
    private static function begin_result(?array $record, Atomic_Option_Result $result): array
    {
        return array('record' => $record, 'result' => $result);
    }

    /** @return array{status:string,record:array<string,mixed>|null} */
    private static function probe_result(string $status, ?array $record = null): array
    {
        return array('status' => $status, 'record' => $record);
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
            && 1 === preg_match('/^upload_[a-f0-9]{32}$/D', $value)
                ? $value
                : '';
    }
}

// EOF: includes/PeerTube_Staged_Upload_Operation_Store.php
