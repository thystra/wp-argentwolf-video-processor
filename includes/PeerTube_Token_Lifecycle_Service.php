<?php
/**
 * File: includes/PeerTube_Token_Lifecycle_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use Throwable;

/**
 * Explicit, restart-safe R41 PeerTube refresh/revoke/disconnect lifecycle.
 *
 * The lifecycle journal never stores token material. A remote mutating request
 * is invoked only after a definite durable in-flight claim; an observed
 * in-flight claim is never replayed after a restart or ambiguous outcome.
 */
final class PeerTube_Token_Lifecycle_Service
{
    public const STATUS_ADVANCED = 'advanced';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_WAIT = 'wait';
    public const STATUS_REAUTHENTICATION_REQUIRED = 'reauthentication_required';
    public const STATUS_INDETERMINATE = 'indeterminate';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_REFUSED = 'refused';

    /** @var Closure(string):PeerTube_Token_Lifecycle_Api */
    private Closure $api_factory;

    public function __construct(
        private readonly PeerTube_Token_Lifecycle_Store $lifecycle,
        private readonly Managed_Backend_Secret_Store $secrets,
        private readonly Backend_Registry $registry,
        ?callable $api_factory = null
    ) {
        $this->api_factory = null === $api_factory
            ? static fn (string $origin): PeerTube_Token_Lifecycle_Api =>
                new PeerTube_Api_Client(new PeerTube_Http_Client($origin))
            : Closure::fromCallable($api_factory);
    }

    /** @return array<string,mixed> */
    public function refresh(string $backend_id, int $now): array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id || $now < 1) {
            return self::projection(self::STATUS_REFUSED, $backend_id);
        }

        try {
            $descriptor = $this->active_descriptor($backend_id);
            if (null === $descriptor) {
                return self::projection(self::STATUS_REFUSED, $backend_id);
            }
            $secret = $this->secrets->read($descriptor['secret_ref'], $backend_id);
            if (! self::valid_secret($secret)) {
                return self::projection(self::STATUS_REAUTHENTICATION_REQUIRED, $backend_id);
            }

            $record = $this->lifecycle->read($backend_id);
            if (null === $record || 'refresh' !== $record['action'] || 'refresh_complete' === $record['phase']) {
                return $this->initialize($record, $backend_id, 'refresh', 'refresh_ready', $secret['generation'], $now);
            }

            return match ($record['phase']) {
                'refresh_ready' => $this->perform_refresh($record, $descriptor, $secret, $now),
                'refresh_wait' => $this->resume_refresh_wait($record, $now),
                'refresh_in_flight' => $this->reconcile_refresh($record, $descriptor, $secret, $now),
                'refresh_reauthentication_required' => self::projection(self::STATUS_REAUTHENTICATION_REQUIRED, $backend_id, $record),
                'refresh_indeterminate' => self::projection(self::STATUS_INDETERMINATE, $backend_id, $record),
                default => self::projection(self::STATUS_CONFLICT, $backend_id, $record),
            };
        } catch (Throwable) {
            return self::projection(self::STATUS_INDETERMINATE, $backend_id);
        }
    }

    /** @return array<string,mixed> */
    public function disconnect(string $backend_id, int $now): array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id || $now < 1) {
            return self::projection(self::STATUS_REFUSED, $backend_id);
        }

        try {
            $record = $this->lifecycle->read($backend_id);
            if (is_array($record) && 'refresh' === $record['action'] && ! in_array(
                $record['phase'],
                array('refresh_complete', 'refresh_reauthentication_required', 'refresh_indeterminate'),
                true
            )) {
                return self::projection(self::STATUS_CONFLICT, $backend_id, $record);
            }

            $descriptor = $this->registry->get($backend_id);
            if (! is_array($descriptor) || Backend_Registry::PEERTUBE_TYPE !== ($descriptor['type'] ?? null)) {
                return self::projection(self::STATUS_REFUSED, $backend_id, $record);
            }

            if (null === $record || 'disconnect' !== $record['action']) {
                $secret = $this->secrets->read($descriptor['secret_ref'], $backend_id);
                if (! self::valid_secret($secret)) {
                    return self::projection(self::STATUS_REAUTHENTICATION_REQUIRED, $backend_id, $record);
                }
                if ('active' !== ($descriptor['state'] ?? null)) {
                    return self::projection(self::STATUS_REFUSED, $backend_id, $record);
                }
                return $this->initialize($record, $backend_id, 'disconnect', 'disconnect_ready', $secret['generation'], $now);
            }

            return match ($record['phase']) {
                'disconnect_ready' => $this->perform_revoke($record, $descriptor, $now),
                'disconnect_revoke_in_flight' => $this->mark_disconnect_indeterminate($record, $now),
                'disconnect_revoked', 'disconnect_indeterminate' => $this->plan_retirement($record, $descriptor, $now),
                'disconnect_retire_planned' => $this->retire_descriptor($record, $descriptor, $now),
                'disconnect_retired' => $this->delete_secret($record, $descriptor, $now),
                'disconnect_complete' => self::projection(self::STATUS_COMPLETE, $backend_id, $record),
                default => self::projection(self::STATUS_CONFLICT, $backend_id, $record),
            };
        } catch (Throwable) {
            return self::projection(self::STATUS_INDETERMINATE, $backend_id, $record ?? null);
        }
    }

    /** @return list<array<string,mixed>> */
    public function managed_backends(): array
    {
        $result = array();
        foreach ($this->registry->all() as $descriptor) {
            if (! is_array($descriptor) || Backend_Registry::PEERTUBE_TYPE !== ($descriptor['type'] ?? null)) {
                continue;
            }
            $backend_id = (string) ($descriptor['id'] ?? '');
            $record = $this->lifecycle->read($backend_id);
            $result[] = array(
                'backend_id' => $backend_id,
                'label' => (string) ($descriptor['label'] ?? ''),
                'origin' => is_array($descriptor['config'] ?? null) ? (string) ($descriptor['config']['origin'] ?? '') : '',
                'state' => (string) ($descriptor['state'] ?? ''),
                'lifecycle_action' => is_array($record) ? $record['action'] : '',
                'lifecycle_phase' => is_array($record) ? $record['phase'] : '',
                'lifecycle_revision' => is_array($record) ? $record['revision'] : 0,
            );
        }
        return $result;
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $descriptor @param array<string,mixed> $secret */
    private function perform_refresh(array $record, array $descriptor, array $secret, int $now): array
    {
        if ($secret['generation'] !== $record['expected_generation']) {
            return self::projection(self::STATUS_CONFLICT, $record['backend_id'], $record);
        }
        if ($secret['refresh_expires_at'] <= $now + 60) {
            return $this->transition(
                $record,
                'refresh_reauthentication_required',
                $now,
                array('reason' => 'refresh_token_unusable', 'http_status' => 0),
                0
            );
        }

        $api = ($this->api_factory)($descriptor['config']['origin']);
        $oauth = $api->local_oauth_client();
        if (! ($oauth['ok'] ?? false)) {
            $error = self::safe_remote_error($oauth['error'] ?? null);
            if ('rate_limited' === $error['reason']) {
                return $this->transition($record, 'refresh_wait', $now, $error, self::retry_after($oauth['error'] ?? null));
            }
            return self::projection(self::STATUS_REFUSED, $record['backend_id'], $record);
        }

        $claim = $this->transition_record($record, 'refresh_in_flight', $now, array(), 0);
        if (null === $claim) {
            unset($oauth, $secret);
            return self::projection(self::STATUS_CONFLICT, $record['backend_id'], $record);
        }
        $record = $claim;
        $confirmed = $this->lifecycle->read($record['backend_id']);
        if ($confirmed !== $record) {
            unset($oauth, $secret);
            return self::projection(self::STATUS_CONFLICT, $record['backend_id'], $record);
        }

        $remote = $api->refresh_token($oauth['data'], $secret['refresh_token'], $now);
        unset($oauth);
        if (! ($remote['ok'] ?? false)) {
            $error = self::safe_remote_error($remote['error'] ?? null);
            unset($secret);
            if ('authentication_required' === $error['reason']) {
                return $this->transition($record, 'refresh_reauthentication_required', $now, $error, 0);
            }
            return $this->transition($record, 'refresh_indeterminate', $now, $error, 0);
        }

        $replacement = $remote['data'];
        $replace = $this->secrets->replace_classified(
            $descriptor['secret_ref'],
            $record['backend_id'],
            $replacement,
            $record['expected_generation']
        );
        unset($remote, $replacement, $secret);
        if (Atomic_Option_Result::APPLIED !== $replace->status()) {
            return self::projection(
                Atomic_Option_Result::INDETERMINATE === $replace->status()
                    ? self::STATUS_INDETERMINATE
                    : self::STATUS_CONFLICT,
                $record['backend_id'],
                $record
            );
        }
        return self::projection(self::STATUS_ADVANCED, $record['backend_id'], $record, $replace->mutation());
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $descriptor @param array<string,mixed> $secret */
    private function reconcile_refresh(array $record, array $descriptor, array $secret, int $now): array
    {
        unset($descriptor);
        if ($secret['generation'] === $record['expected_generation'] + 1) {
            return $this->transition($record, 'refresh_complete', $now, array(), 0, self::STATUS_COMPLETE);
        }
        if ($secret['generation'] === $record['expected_generation']) {
            return $this->transition(
                $record,
                'refresh_indeterminate',
                $now,
                array('reason' => 'refresh_outcome_unknown', 'http_status' => 0),
                0,
                self::STATUS_INDETERMINATE
            );
        }
        return self::projection(self::STATUS_CONFLICT, $record['backend_id'], $record);
    }

    /** @param array<string,mixed> $record */
    private function resume_refresh_wait(array $record, int $now): array
    {
        if ($record['retry_after'] < 1 || $record['updated_at'] > PHP_INT_MAX - $record['retry_after']) {
            return self::projection(self::STATUS_REFUSED, $record['backend_id'], $record);
        }
        if ($now < $record['updated_at'] + $record['retry_after']) {
            return self::projection(self::STATUS_WAIT, $record['backend_id'], $record);
        }
        return $this->transition($record, 'refresh_ready', $now, array(), 0);
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $descriptor */
    private function perform_revoke(array $record, array $descriptor, int $now): array
    {
        if ('active' !== ($descriptor['state'] ?? null)) {
            return self::projection(self::STATUS_CONFLICT, $record['backend_id'], $record);
        }
        $secret = $this->secrets->read($descriptor['secret_ref'], $record['backend_id']);
        if (! self::valid_secret($secret) || $secret['generation'] !== $record['expected_generation']) {
            return self::projection(self::STATUS_CONFLICT, $record['backend_id'], $record);
        }
        $claim = $this->transition_record($record, 'disconnect_revoke_in_flight', $now, array(), 0);
        if (null === $claim || $this->lifecycle->read($record['backend_id']) !== $claim) {
            unset($secret);
            return self::projection(self::STATUS_CONFLICT, $record['backend_id'], $record);
        }
        $record = $claim;
        $api = ($this->api_factory)($descriptor['config']['origin']);
        $remote = $api->revoke_token($secret['access_token']);
        unset($secret);
        if (! ($remote['ok'] ?? false)) {
            return $this->transition(
                $record,
                'disconnect_indeterminate',
                $now,
                self::safe_remote_error($remote['error'] ?? null),
                0,
                self::STATUS_INDETERMINATE
            );
        }
        return $this->transition($record, 'disconnect_revoked', $now, array(), 0);
    }

    /** @param array<string,mixed> $record */
    private function mark_disconnect_indeterminate(array $record, int $now): array
    {
        return $this->transition(
            $record,
            'disconnect_indeterminate',
            $now,
            array('reason' => 'revoke_outcome_unknown', 'http_status' => 0),
            0,
            self::STATUS_INDETERMINATE
        );
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $descriptor */
    private function plan_retirement(array $record, array $descriptor, int $now): array
    {
        if ('retired' === ($descriptor['state'] ?? null)) {
            return $this->transition($record, 'disconnect_retired', $now, array(), 0);
        }
        if ('active' !== ($descriptor['state'] ?? null)) {
            return self::projection(self::STATUS_CONFLICT, $record['backend_id'], $record);
        }
        $mutation_id = self::mutation_id();
        if ('' === $mutation_id) {
            return self::projection(self::STATUS_REFUSED, $record['backend_id'], $record);
        }
        $prepared = $this->registry->prepare_peertube_retirement($descriptor, $mutation_id);
        $plan = $prepared->plan();
        if (Atomic_Option_Plan_Result::READY !== $prepared->status() || null === $plan) {
            return self::projection(
                Atomic_Option_Plan_Result::INDETERMINATE === $prepared->status()
                    ? self::STATUS_INDETERMINATE
                    : self::STATUS_CONFLICT,
                $record['backend_id'],
                $record
            );
        }
        return $this->transition($record, 'disconnect_retire_planned', $now, array(), 0, self::STATUS_ADVANCED, $plan->evidence());
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $descriptor */
    private function retire_descriptor(array $record, array $descriptor, int $now): array
    {
        if ('retired' === ($descriptor['state'] ?? null)) {
            return $this->transition($record, 'disconnect_retired', $now, array(), 0);
        }
        if ('active' !== ($descriptor['state'] ?? null) || [] === $record['last_mutation']) {
            return self::projection(self::STATUS_CONFLICT, $record['backend_id'], $record);
        }
        $result = $this->registry->reconcile_peertube_retirement($descriptor, $record['last_mutation']);
        if (Atomic_Option_Result::APPLIED !== $result->status()) {
            return self::projection(
                Atomic_Option_Result::INDETERMINATE === $result->status()
                    ? self::STATUS_INDETERMINATE
                    : self::STATUS_CONFLICT,
                $record['backend_id'],
                $record,
                $result->mutation()
            );
        }
        // A real registry write is the consequential boundary for this request.
        if (Atomic_Option_Result::MUTATION_APPLIED === $result->mutation()) {
            return self::projection(self::STATUS_ADVANCED, $record['backend_id'], $record, $result->mutation());
        }
        return $this->transition($record, 'disconnect_retired', $now, array(), 0);
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $descriptor */
    private function delete_secret(array $record, array $descriptor, int $now): array
    {
        if ('retired' !== ($descriptor['state'] ?? null)) {
            return self::projection(self::STATUS_CONFLICT, $record['backend_id'], $record);
        }
        $result = $this->secrets->delete_classified(
            $descriptor['secret_ref'],
            $record['backend_id'],
            $record['expected_generation']
        );
        if (Atomic_Option_Result::APPLIED !== $result->status()) {
            return self::projection(
                Atomic_Option_Result::INDETERMINATE === $result->status()
                    ? self::STATUS_INDETERMINATE
                    : self::STATUS_CONFLICT,
                $record['backend_id'],
                $record,
                $result->mutation()
            );
        }
        return $this->transition($record, 'disconnect_complete', $now, array(), 0, self::STATUS_COMPLETE, array(), $result->mutation());
    }

    /** @param array<string,mixed>|null $expected */
    private function initialize(
        ?array $expected,
        string $backend_id,
        string $action,
        string $phase,
        int $generation,
        int $now
    ): array {
        $record = array(
            'version' => PeerTube_Token_Lifecycle_Store::VERSION,
            'backend_id' => $backend_id,
            'action' => $action,
            'phase' => $phase,
            'expected_generation' => $generation,
            'retry_after' => 0,
            'last_error' => array(),
            'last_mutation' => array(),
            'revision' => is_array($expected) ? $expected['revision'] + 1 : 1,
            'created_at' => is_array($expected) ? $expected['created_at'] : $now,
            'updated_at' => $now,
        );
        $result = $this->lifecycle->replace($expected, $record);
        return Atomic_Option_Result::APPLIED === $result->status()
            ? self::projection(self::STATUS_ADVANCED, $backend_id, $record, $result->mutation())
            : self::projection(self::STATUS_CONFLICT, $backend_id, $expected, $result->mutation());
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $error @param array<string,mixed> $mutation */
    private function transition(
        array $record,
        string $phase,
        int $now,
        array $error,
        int $retry_after,
        string $status = self::STATUS_ADVANCED,
        array $mutation = array(),
        string $mutation_classification = Atomic_Option_Result::MUTATION_APPLIED
    ): array {
        $next = $this->transition_record($record, $phase, $now, $error, $retry_after, $mutation);
        return null === $next
            ? self::projection(self::STATUS_CONFLICT, $record['backend_id'], $record)
            : self::projection($status, $record['backend_id'], $next, $mutation_classification);
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $error @param array<string,mixed> $mutation @return array<string,mixed>|null */
    private function transition_record(
        array $record,
        string $phase,
        int $now,
        array $error,
        int $retry_after,
        array $mutation = array()
    ): ?array {
        if ($now < $record['updated_at'] || $record['revision'] >= PHP_INT_MAX) {
            return null;
        }
        $next = $record;
        $next['phase'] = $phase;
        $next['retry_after'] = min(max($retry_after, 0), 86400);
        $next['last_error'] = $error;
        $next['last_mutation'] = $mutation;
        $next['revision']++;
        $next['updated_at'] = $now;
        $result = $this->lifecycle->replace($record, $next);
        return Atomic_Option_Result::APPLIED === $result->status() ? $next : null;
    }

    /** @return array<string,mixed>|null */
    private function active_descriptor(string $backend_id): ?array
    {
        $descriptor = $this->registry->get($backend_id);
        return is_array($descriptor)
            && Backend_Registry::PEERTUBE_TYPE === ($descriptor['type'] ?? null)
            && 'active' === ($descriptor['state'] ?? null)
            ? $descriptor
            : null;
    }

    /** @param array<string,mixed>|null $secret */
    private static function valid_secret(?array $secret): bool
    {
        return is_array($secret)
            && is_string($secret['access_token'] ?? null)
            && '' !== $secret['access_token']
            && is_string($secret['refresh_token'] ?? null)
            && '' !== $secret['refresh_token']
            && is_int($secret['access_expires_at'] ?? null)
            && is_int($secret['refresh_expires_at'] ?? null)
            && is_int($secret['generation'] ?? null)
            && $secret['generation'] > 0;
    }

    /** @return array{reason:string,http_status:int} */
    private static function safe_remote_error(mixed $error): array
    {
        $reason = is_array($error) && is_string($error['status'] ?? null)
            ? $error['status']
            : 'remote_error';
        if (1 !== preg_match('/^[a-z0-9_.-]+$/D', $reason) || strlen($reason) > 120) {
            $reason = 'remote_error';
        }
        $http = is_array($error) && is_int($error['http_status'] ?? null)
            ? min(max($error['http_status'], 0), 599)
            : 0;
        return array('reason' => $reason, 'http_status' => $http);
    }

    private static function retry_after(mixed $error): int
    {
        return is_array($error) && is_int($error['retry_after'] ?? null)
            ? min(max($error['retry_after'], 0), 86400)
            : 0;
    }

    private static function mutation_id(): string
    {
        try {
            return 'mutation_' . bin2hex(random_bytes(16));
        } catch (Throwable) {
            return '';
        }
    }

    /** @param array<string,mixed>|null $record @return array<string,mixed> */
    private static function projection(
        string $status,
        string $backend_id,
        ?array $record = null,
        string $mutation = Atomic_Option_Result::MUTATION_NONE
    ): array {
        return array(
            'status' => $status,
            'mutation' => $mutation,
            'backend_id' => $backend_id,
            'action' => is_array($record) ? $record['action'] : '',
            'phase' => is_array($record) ? $record['phase'] : '',
            'revision' => is_array($record) ? $record['revision'] : 0,
            'retry_after' => is_array($record) ? $record['retry_after'] : 0,
        );
    }
}

// EOF: includes/PeerTube_Token_Lifecycle_Service.php
