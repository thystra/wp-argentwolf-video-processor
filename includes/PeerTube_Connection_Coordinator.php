<?php
/**
 * File: includes/PeerTube_Connection_Coordinator.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * Restart-safe local preparation for one prospective PeerTube connection.
 *
 * This coordinator deliberately stops at the durable disabled descriptor. It
 * accepts no credentials, performs no HTTP, and never begins a remote grant.
 * Each call advances at most one local persistence boundary so a later request
 * can reconcile the exact journaled plan after interruption.
 */
final class PeerTube_Connection_Coordinator
{
    public const STATUS_ADVANCED = 'advanced';
    public const STATUS_READY_FOR_GRANT = 'ready_for_grant';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_INDETERMINATE = 'indeterminate';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_OUTSIDE_SCOPE = 'outside_scope';

    private PeerTube_Connection_Operation_Store $operations;
    private Managed_Backend_Secret_Store $secrets;
    private Backend_Registry $registry;

    public function __construct(
        ?PeerTube_Connection_Operation_Store $operations = null,
        ?Managed_Backend_Secret_Store $secrets = null,
        ?Backend_Registry $registry = null
    ) {
        $this->operations = $operations ?? new PeerTube_Connection_Operation_Store();
        $this->secrets = $secrets ?? new Managed_Backend_Secret_Store();
        $this->registry = $registry ?? new Backend_Registry();
    }

    /**
     * Create only the first durable operation record and its stable local IDs.
     *
     * @param array<string, mixed> $intent
     * @return array{
     *   status:string,
     *   mutation:string,
     *   operation_id:string,
     *   backend_id:string,
     *   phase:string,
     *   record_revision:int
     * }
     */
    public function start(array $intent, int $actor_id, int $now): array
    {
        $candidate = null;
        $result = null;

        try {
            if (
                ! self::start_intent_shape($intent)
                || $actor_id < 1
                || $now < 1
                || ! $this->secrets->available()
            ) {
                return self::projection(self::STATUS_REFUSED);
            }

            $registry_probe = $this->registry->probe_peertube_id($intent['backend_id']);
            if (Backend_Registry::PEERTUBE_ID_INDETERMINATE === $registry_probe) {
                return self::projection(self::STATUS_INDETERMINATE);
            }
            if (Backend_Registry::PEERTUBE_ID_AVAILABLE !== $registry_probe) {
                return self::projection(self::STATUS_REFUSED);
            }

            $begun = $this->operations->begin($intent, $actor_id, $now);
            $candidate = is_array($begun['record'] ?? null) ? $begun['record'] : null;
            $result = $begun['result'] ?? null;
            if (! $result instanceof Atomic_Option_Result || null === $candidate) {
                return $result instanceof Atomic_Option_Result
                    ? self::from_atomic($result, $candidate)
                    : self::projection(self::STATUS_REFUSED);
            }

            $probe = $this->operations->probe($candidate['operation_id']);
            if (PeerTube_Connection_Operation_Store::PROBE_PRESENT === $probe['status']) {
                $current = is_array($probe['record']) ? $probe['record'] : null;
                if ($candidate !== $current) {
                    return self::projection(
                        Atomic_Option_Result::INDETERMINATE === $result->status()
                        || Atomic_Option_Result::MUTATION_NONE !== $result->mutation()
                            ? self::STATUS_INDETERMINATE
                            : self::STATUS_CONFLICT,
                        self::merge_mutation($result->mutation()),
                        $current ?? $candidate
                    );
                }

                if (Atomic_Option_Result::INDETERMINATE === $result->status()) {
                    return self::projection(
                        self::STATUS_INDETERMINATE,
                        self::merge_mutation($result->mutation()),
                        $current
                    );
                }

                return self::projection(
                    self::STATUS_ADVANCED,
                    Atomic_Option_Result::APPLIED === $result->status()
                        ? $result->mutation()
                        : Atomic_Option_Result::MUTATION_NONE,
                    $current
                );
            }

            if (PeerTube_Connection_Operation_Store::PROBE_ABSENT === $probe['status']) {
                return in_array(
                    $result->status(),
                    array(Atomic_Option_Result::CONFLICT, Atomic_Option_Result::REFUSED),
                    true
                )
                    ? self::from_atomic($result, null)
                    : self::projection(
                        self::STATUS_INDETERMINATE,
                        self::merge_mutation($result->mutation()),
                        self::candidate_for_uncertain_write($result, $candidate)
                    );
            }

            $projected_candidate = self::candidate_for_uncertain_write(
                $result,
                $candidate
            );

            return self::projection(
                PeerTube_Connection_Operation_Store::PROBE_INDETERMINATE === $probe['status']
                || Atomic_Option_Result::INDETERMINATE === $result->status()
                || Atomic_Option_Result::MUTATION_NONE !== $result->mutation()
                    ? self::STATUS_INDETERMINATE
                    : self::STATUS_REFUSED,
                self::merge_mutation($result->mutation()),
                $projected_candidate
            );
        } catch (Throwable) {
            return self::projection(
                self::STATUS_INDETERMINATE,
                Atomic_Option_Result::MUTATION_UNKNOWN,
                $result instanceof Atomic_Option_Result
                    ? self::candidate_for_uncertain_write($result, $candidate)
                    : null
            );
        }
    }

    /**
     * Advance or reconcile at most one local preparation boundary.
     *
     * @return array{
     *   status:string,
     *   mutation:string,
     *   operation_id:string,
     *   backend_id:string,
     *   phase:string,
     *   record_revision:int
     * }
     */
    public function resume(string $operation_id, int $now): array
    {
        $record = null;

        try {
            if ($now < 1) {
                return self::projection(
                    self::STATUS_REFUSED,
                    Atomic_Option_Result::MUTATION_NONE,
                    null,
                    $operation_id
                );
            }

            $probe = $this->operations->probe($operation_id);
            if (PeerTube_Connection_Operation_Store::PROBE_INDETERMINATE === $probe['status']) {
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    Atomic_Option_Result::MUTATION_NONE,
                    null,
                    $operation_id
                );
            }
            if (PeerTube_Connection_Operation_Store::PROBE_PRESENT !== $probe['status']) {
                return self::projection(
                    self::STATUS_REFUSED,
                    Atomic_Option_Result::MUTATION_NONE,
                    null,
                    $operation_id
                );
            }

            $record = is_array($probe['record']) ? $probe['record'] : null;
            if (
                null === $record
                || ! PeerTube_Connection_State_Machine::valid($record)
                || $now < $record['updated_at']
            ) {
                return self::projection(self::STATUS_REFUSED, Atomic_Option_Result::MUTATION_NONE, $record);
            }

            return match ($record['phase']) {
                PeerTube_Connection_State_Machine::PHASE_PREPARED =>
                    $this->prepare_secret_reservation($record, $now),
                PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED =>
                    $this->reserve_secret($record, $now),
                PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVED =>
                    $this->prepare_disabled_link($record, $now),
                PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED =>
                    $this->link_disabled_backend($record, $now),
                PeerTube_Connection_State_Machine::PHASE_DISABLED =>
                    $this->ready_result($record),
                default => self::projection(
                    self::STATUS_OUTSIDE_SCOPE,
                    Atomic_Option_Result::MUTATION_NONE,
                    $record
                ),
            };
        } catch (Throwable) {
            return self::projection(
                self::STATUS_INDETERMINATE,
                Atomic_Option_Result::MUTATION_UNKNOWN,
                $record,
                $operation_id
            );
        }
    }

    /** @param array<string, mixed> $record */
    private function prepare_secret_reservation(array $record, int $now): array
    {
        $manifest = $this->secrets->initialize_classified();
        if (Atomic_Option_Result::APPLIED !== $manifest->status()) {
            return self::from_atomic($manifest, $record);
        }

        // A newly created shared provider manifest is its own consequential
        // boundary. The next request will persist the operation-specific plan.
        if (Atomic_Option_Result::MUTATION_APPLIED === $manifest->mutation()) {
            return self::projection(self::STATUS_ADVANCED, $manifest->mutation(), $record);
        }

        $mutation_id = self::mutation_id();
        if ('' === $mutation_id) {
            return self::projection(self::STATUS_REFUSED, Atomic_Option_Result::MUTATION_NONE, $record);
        }

        $prepared = $this->secrets->prepare_reservation(
            $record['secret_ref'],
            $record['backend_id'],
            $record['provisioning_id'],
            $mutation_id
        );
        $plan = $prepared->plan();
        if (Atomic_Option_Plan_Result::READY !== $prepared->status() || null === $plan) {
            return self::from_plan($prepared, $record);
        }

        return $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_PLAN_SECRET_RESERVATION,
            $plan->evidence(),
            $now
        );
    }

    /** @param array<string, mixed> $record */
    private function reserve_secret(array $record, int $now): array
    {
        $evidence = $record['last_mutation'];
        $result = $this->secrets->reconcile_reservation(
            $record['secret_ref'],
            $record['backend_id'],
            $record['provisioning_id'],
            $evidence
        );

        if (Atomic_Option_Result::CONFLICT === $result->status()) {
            $probe = $this->secrets->probe_reservation(
                $record['secret_ref'],
                $record['backend_id'],
                $record['provisioning_id'],
                $evidence
            );
            if (Atomic_Option_Store::PROBE_AFTER !== $probe) {
                return self::from_probe($probe, $record);
            }
            $result = Atomic_Option_Result::satisfied();
        }

        if (Atomic_Option_Result::APPLIED !== $result->status()) {
            return self::from_atomic($result, $record);
        }

        $probe = $this->secrets->probe_reservation(
            $record['secret_ref'],
            $record['backend_id'],
            $record['provisioning_id'],
            $evidence
        );
        if (Atomic_Option_Store::PROBE_AFTER !== $probe) {
            return self::from_probe($probe, $record, $result->mutation());
        }

        if (Atomic_Option_Result::MUTATION_NONE !== $result->mutation()) {
            return self::projection(self::STATUS_ADVANCED, $result->mutation(), $record);
        }

        return $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_CONFIRM_SECRET_RESERVED,
            array(),
            $now,
            $result->mutation()
        );
    }

    /** @param array<string, mixed> $record */
    private function prepare_disabled_link(array $record, int $now): array
    {
        $secret_probe = $this->secrets->probe_reservation(
            $record['secret_ref'],
            $record['backend_id'],
            $record['provisioning_id'],
            $record['last_mutation']
        );
        if (Atomic_Option_Store::PROBE_AFTER !== $secret_probe) {
            return self::from_probe($secret_probe, $record);
        }

        $mutation_id = self::mutation_id();
        if ('' === $mutation_id) {
            return self::projection(self::STATUS_REFUSED, Atomic_Option_Result::MUTATION_NONE, $record);
        }

        $prepared = $this->registry->prepare_disabled_peertube(
            self::descriptor($record),
            $mutation_id
        );
        $plan = $prepared->plan();
        if (Atomic_Option_Plan_Result::READY !== $prepared->status() || null === $plan) {
            return self::from_plan($prepared, $record);
        }

        return $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_PLAN_DISABLED_LINK,
            $plan->evidence(),
            $now
        );
    }

    /** @param array<string, mixed> $record */
    private function link_disabled_backend(array $record, int $now): array
    {
        $secret_probe = $this->pending_secret_probe($record);
        if (Atomic_Option_Store::PROBE_AFTER !== $secret_probe) {
            return self::from_probe($secret_probe, $record);
        }

        $descriptor = self::descriptor($record);
        $evidence = $record['last_mutation'];
        $result = $this->registry->reconcile_disabled_peertube($descriptor, $evidence);

        if (Atomic_Option_Result::CONFLICT === $result->status()) {
            $probe = $this->registry->probe_disabled_peertube($descriptor, $evidence);
            if (Atomic_Option_Store::PROBE_AFTER === $probe) {
                $result = Atomic_Option_Result::satisfied();
            } elseif (
                Atomic_Option_Store::PROBE_OTHER === $probe
                && Atomic_Option_Result::MUTATION_NONE === $result->mutation()
                && Atomic_Option_Result::PHASE_SQL === $result->phase()
            ) {
                return $this->replan_disabled_link($record, $descriptor, $now);
            } else {
                return self::from_probe($probe, $record);
            }
        }

        if (Atomic_Option_Result::APPLIED !== $result->status()) {
            return self::from_atomic($result, $record);
        }

        $probe = $this->registry->probe_disabled_peertube(
            $descriptor,
            $evidence
        );
        if (Atomic_Option_Store::PROBE_AFTER !== $probe) {
            return self::from_probe($probe, $record, $result->mutation());
        }

        if (Atomic_Option_Result::MUTATION_NONE !== $result->mutation()) {
            return self::projection(self::STATUS_ADVANCED, $result->mutation(), $record);
        }

        return $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_CONFIRM_DISABLED_LINK,
            array(),
            $now,
            $result->mutation()
        );
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $descriptor
     */
    private function replan_disabled_link(array $record, array $descriptor, int $now): array
    {
        $mutation_id = self::mutation_id();
        if ('' === $mutation_id) {
            return self::projection(self::STATUS_REFUSED, Atomic_Option_Result::MUTATION_NONE, $record);
        }

        $prepared = $this->registry->prepare_disabled_peertube($descriptor, $mutation_id);
        $plan = $prepared->plan();
        if (Atomic_Option_Plan_Result::READY !== $prepared->status() || null === $plan) {
            return self::from_plan($prepared, $record);
        }

        return $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_REPLAN_DISABLED_LINK,
            $plan->evidence(),
            $now
        );
    }

    /** @param array<string, mixed> $record */
    private function ready_result(array $record): array
    {
        $secret_probe = $this->pending_secret_probe($record);
        $registry_probe = $this->registry->probe_disabled_peertube_state(
            self::descriptor($record)
        );

        foreach (array($secret_probe, $registry_probe) as $probe) {
            if (Atomic_Option_Store::PROBE_AFTER !== $probe) {
                return self::from_probe($probe, $record);
            }
        }

        return self::projection(
            self::STATUS_READY_FOR_GRANT,
            Atomic_Option_Result::MUTATION_NONE,
            $record
        );
    }

    /**
     * Persist one pure state transition and classify its authoritative record.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $payload
     */
    private function persist_event(
        array $record,
        string $event,
        array $payload,
        int $now,
        string $prior_mutation = Atomic_Option_Result::MUTATION_NONE
    ): array {
        $next = PeerTube_Connection_State_Machine::apply($record, $event, $payload, $now);
        if (null === $next) {
            return self::projection(
                self::STATUS_REFUSED,
                $prior_mutation,
                $record
            );
        }

        $write = $this->operations->apply_event(
            $record['operation_id'],
            $record['record_revision'],
            $event,
            $payload,
            $now
        );
        $mutation = self::merge_mutation($prior_mutation, $write->mutation());
        $probe = $this->operations->probe($record['operation_id']);

        if (PeerTube_Connection_Operation_Store::PROBE_PRESENT === $probe['status']) {
            $current = is_array($probe['record']) ? $probe['record'] : null;
            if ($next === $current) {
                if (Atomic_Option_Result::INDETERMINATE === $write->status()) {
                    return self::projection(self::STATUS_INDETERMINATE, $mutation, $current);
                }

                $confirmed_mutation = Atomic_Option_Result::APPLIED === $write->status()
                    ? $mutation
                    : self::merge_mutation($prior_mutation);

                return self::projection(
                    self::STATUS_ADVANCED,
                    $confirmed_mutation,
                    $current
                );
            }

            if ($record === $current) {
                if (Atomic_Option_Result::APPLIED === $write->status()) {
                    return self::projection(
                        self::STATUS_INDETERMINATE,
                        $mutation,
                        $current
                    );
                }

                return self::from_atomic($write, $current, $prior_mutation);
            }

            return self::projection(
                Atomic_Option_Result::INDETERMINATE === $write->status()
                || Atomic_Option_Result::MUTATION_NONE !== $mutation
                    ? self::STATUS_INDETERMINATE
                    : self::STATUS_CONFLICT,
                $mutation,
                $current ?? $record
            );
        }

        if (PeerTube_Connection_Operation_Store::PROBE_INDETERMINATE === $probe['status']) {
            return self::projection(self::STATUS_INDETERMINATE, $mutation, $record);
        }

        return self::projection(
            self::STATUS_INDETERMINATE,
            $mutation,
            $record
        );
    }

    /** @param array<string, mixed> $record */
    private function pending_secret_probe(array $record): string
    {
        $state = $this->secrets->provisioning_state(
            $record['secret_ref'],
            $record['backend_id'],
            $record['provisioning_id']
        );

        return match ($state['state']) {
            Managed_Backend_Secret_Store::PROVISION_PENDING =>
                0 === $state['generation']
                    ? Atomic_Option_Store::PROBE_AFTER
                    : Atomic_Option_Store::PROBE_OTHER,
            Managed_Backend_Secret_Store::PROVISION_INDETERMINATE =>
                Atomic_Option_Store::PROBE_INDETERMINATE,
            Managed_Backend_Secret_Store::PROVISION_UNREADABLE =>
                Atomic_Option_Store::PROBE_REFUSED,
            default => Atomic_Option_Store::PROBE_OTHER,
        };
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function descriptor(array $record): array
    {
        return array(
            'id'                  => $record['backend_id'],
            'type'                => 'peertube',
            'label'               => $record['label'],
            'state'               => 'disabled',
            'default_destination' => '',
            'secret_ref'          => $record['secret_ref'],
            'config_version'      => 1,
            'config'              => array('origin' => $record['origin']),
        );
    }

    private static function mutation_id(): string
    {
        try {
            return 'mutation_' . bin2hex(random_bytes(16));
        } catch (Throwable) {
            return '';
        }
    }

    /** @param array<string, mixed> $intent */
    private static function start_intent_shape(array $intent): bool
    {
        $expected = array('backend_id', 'origin', 'label');
        if (count($intent) !== count($expected)) {
            return false;
        }

        foreach ($expected as $key) {
            if (! array_key_exists($key, $intent)) {
                return false;
            }
        }

        foreach (array_keys($intent) as $key) {
            if (! is_string($key) || ! in_array($key, $expected, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private static function from_plan(
        Atomic_Option_Plan_Result $result,
        ?array $record
    ): array {
        $status = match ($result->status()) {
            Atomic_Option_Plan_Result::CONFLICT => self::STATUS_CONFLICT,
            Atomic_Option_Plan_Result::INDETERMINATE => self::STATUS_INDETERMINATE,
            default => self::STATUS_REFUSED,
        };

        return self::projection($status, Atomic_Option_Result::MUTATION_NONE, $record);
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private static function from_probe(
        string $probe,
        ?array $record,
        string $mutation = Atomic_Option_Result::MUTATION_NONE
    ): array {
        $status = match ($probe) {
            Atomic_Option_Store::PROBE_INDETERMINATE => self::STATUS_INDETERMINATE,
            Atomic_Option_Store::PROBE_REFUSED => self::STATUS_REFUSED,
            default => self::STATUS_CONFLICT,
        };

        if (
            self::STATUS_INDETERMINATE !== $status
            && Atomic_Option_Result::MUTATION_NONE !== $mutation
        ) {
            $status = self::STATUS_INDETERMINATE;
        }

        return self::projection($status, $mutation, $record);
    }

    /**
     * @param array<string, mixed>|null $record
     */
    private static function from_atomic(
        Atomic_Option_Result $result,
        ?array $record,
        string $prior_mutation = Atomic_Option_Result::MUTATION_NONE
    ): array {
        $status = match ($result->status()) {
            Atomic_Option_Result::APPLIED => self::STATUS_ADVANCED,
            Atomic_Option_Result::CONFLICT => self::STATUS_CONFLICT,
            Atomic_Option_Result::INDETERMINATE => self::STATUS_INDETERMINATE,
            default => self::STATUS_REFUSED,
        };

        return self::projection(
            $status,
            self::merge_mutation($prior_mutation, $result->mutation()),
            $record
        );
    }

    private static function merge_mutation(string ...$mutations): string
    {
        if (in_array(Atomic_Option_Result::MUTATION_UNKNOWN, $mutations, true)) {
            return Atomic_Option_Result::MUTATION_UNKNOWN;
        }
        if (in_array(Atomic_Option_Result::MUTATION_APPLIED, $mutations, true)) {
            return Atomic_Option_Result::MUTATION_APPLIED;
        }

        return Atomic_Option_Result::MUTATION_NONE;
    }

    /**
     * Retain a generated operation identity only when a write may have crossed
     * the persistence boundary and the authoritative follow-up read could not
     * establish the candidate's presence. Definite no-mutation outcomes must
     * not expose a phantom resumable identity.
     *
     * @param array<string, mixed>|null $candidate
     * @return array<string, mixed>|null
     */
    private static function candidate_for_uncertain_write(
        Atomic_Option_Result $result,
        ?array $candidate
    ): ?array {
        return Atomic_Option_Result::MUTATION_NONE !== $result->mutation()
            && is_array($candidate)
            && PeerTube_Connection_State_Machine::valid($candidate)
                ? $candidate
                : null;
    }

    private static function projected_operation_id(mixed $value): string
    {
        return PeerTube_Connection_Input::operation_id($value);
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array{
     *   status:string,
     *   mutation:string,
     *   operation_id:string,
     *   backend_id:string,
     *   phase:string,
     *   record_revision:int
     * }
     */
    private static function projection(
        string $status,
        string $mutation = Atomic_Option_Result::MUTATION_NONE,
        ?array $record = null,
        string $operation_id = ''
    ): array {
        $record_operation_id = is_array($record)
            ? self::projected_operation_id($record['operation_id'] ?? null)
            : '';

        return array(
            'status'          => $status,
            'mutation'        => $mutation,
            'operation_id'    => '' !== $record_operation_id
                ? $record_operation_id
                : self::projected_operation_id($operation_id),
            'backend_id'      => is_array($record) && is_string($record['backend_id'] ?? null)
                ? $record['backend_id']
                : '',
            'phase'           => is_array($record) && is_string($record['phase'] ?? null)
                ? $record['phase']
                : '',
            'record_revision' => is_array($record) && is_int($record['record_revision'] ?? null)
                ? $record['record_revision']
                : 0,
        );
    }
}

// EOF: includes/PeerTube_Connection_Coordinator.php
