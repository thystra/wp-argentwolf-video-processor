<?php
/**
 * File: includes/PeerTube_Token_Lifecycle_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/** Durable, non-secret R41 token-lifecycle journal, one option per backend. */
final class PeerTube_Token_Lifecycle_Store
{
    public const OPTION_PREFIX = 'argentwolf_video_processor_peertube_lifecycle.';
    public const VERSION = 1;

    /** @return array<string,mixed>|null */
    public function read(string $backend_id): ?array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id) {
            return null;
        }
        try {
            $snapshot = $this->store($backend_id)->snapshot();
        } catch (Throwable) {
            return null;
        }
        if (! $snapshot->is_present()) {
            return null;
        }
        return self::sanitize($snapshot->value());
    }

    /**
     * Exact create-or-replace. The caller supplies the record it previously read;
     * null means the option must still be absent.
     *
     * @param array<string,mixed>|null $expected
     * @param array<string,mixed> $replacement
     */
    public function replace(?array $expected, array $replacement): Atomic_Option_Result
    {
        $replacement = self::sanitize($replacement);
        if (null === $replacement) {
            return Atomic_Option_Result::refused();
        }
        $backend_id = $replacement['backend_id'];
        $store = $this->store($backend_id);
        $snapshot = $store->snapshot();
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_NONE,
                Atomic_Option_Result::PHASE_VALIDATION
            );
        }
        if (null === $expected) {
            if (! $snapshot->is_absent()) {
                return Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
            }
        } else {
            $expected = self::sanitize($expected);
            if (null === $expected || ! $snapshot->is_present() || $snapshot->value() !== $expected) {
                return Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
            }
        }
        return $store->compare_exchange($snapshot, $replacement);
    }

    /** @return array<string,mixed>|null */
    public static function sanitize(mixed $record): ?array
    {
        if (! is_array($record)) {
            return null;
        }
        $keys = array(
            'version', 'backend_id', 'action', 'phase', 'expected_generation',
            'retry_after', 'last_error', 'last_mutation', 'revision', 'created_at', 'updated_at',
        );
        if (array_keys($record) !== $keys) {
            return null;
        }
        $backend_id = Backend_Identity::sanitize($record['backend_id'] ?? null);
        $action = $record['action'] ?? null;
        $phase = $record['phase'] ?? null;
        if (
            self::VERSION !== ($record['version'] ?? null)
            || '' === $backend_id
            || Backend_Registry::LOCAL_ID === $backend_id
            || ! in_array($action, array('refresh', 'disconnect'), true)
            || ! is_string($phase)
            || ! self::valid_phase($action, $phase)
            || ! is_int($record['expected_generation'])
            || $record['expected_generation'] < 1
            || ! is_int($record['retry_after'])
            || $record['retry_after'] < 0
            || $record['retry_after'] > 86400
            || ! is_array($record['last_error'])
            || ! self::safe_error($record['last_error'])
            || ! is_array($record['last_mutation'])
            || ! self::safe_mutation($record['last_mutation'])
            || ! is_int($record['revision'])
            || $record['revision'] < 1
            || ! is_int($record['created_at'])
            || ! is_int($record['updated_at'])
            || $record['created_at'] < 1
            || $record['updated_at'] < $record['created_at']
            || strlen(serialize($record)) > 8192
        ) {
            return null;
        }
        return $record;
    }

    private function store(string $backend_id): Atomic_Option_Store
    {
        return new Atomic_Option_Store(self::OPTION_PREFIX . $backend_id, 16384);
    }

    private static function valid_phase(string $action, string $phase): bool
    {
        $refresh = array(
            'refresh_ready', 'refresh_wait', 'refresh_in_flight', 'refresh_complete',
            'refresh_reauthentication_required', 'refresh_indeterminate',
        );
        $disconnect = array(
            'disconnect_ready', 'disconnect_revoke_in_flight', 'disconnect_revoked',
            'disconnect_indeterminate', 'disconnect_retire_planned', 'disconnect_retired',
            'disconnect_complete',
        );
        return in_array($phase, 'refresh' === $action ? $refresh : $disconnect, true);
    }

    /** @param array<string,mixed> $error */
    private static function safe_error(array $error): bool
    {
        if ([] === $error) {
            return true;
        }
        if (array_keys($error) !== array('reason', 'http_status')) {
            return false;
        }
        return is_string($error['reason'])
            && strlen($error['reason']) <= 120
            && 1 === preg_match('/^[a-z0-9_.-]+$/D', $error['reason'])
            && is_int($error['http_status'])
            && $error['http_status'] >= 0
            && $error['http_status'] <= 599;
    }

    /** @param array<string,mixed> $mutation */
    private static function safe_mutation(array $mutation): bool
    {
        if ([] === $mutation) {
            return true;
        }
        $keys = array(
            'kind', 'mutation_id', 'before_exists', 'before_sha256', 'before_bytes',
            'after_exists', 'after_sha256', 'after_bytes',
        );
        if (array_keys($mutation) !== $keys) {
            return false;
        }
        return 'registry_retire' === ($mutation['kind'] ?? null)
            && is_string($mutation['mutation_id'] ?? null)
            && 1 === preg_match('/^mutation_[a-f0-9]{32}$/D', $mutation['mutation_id'])
            && is_bool($mutation['before_exists'] ?? null)
            && is_string($mutation['before_sha256'] ?? null)
            && is_int($mutation['before_bytes'] ?? null)
            && true === ($mutation['after_exists'] ?? null)
            && is_string($mutation['after_sha256'] ?? null)
            && is_int($mutation['after_bytes'] ?? null);
    }
}

// EOF: includes/PeerTube_Token_Lifecycle_Store.php
