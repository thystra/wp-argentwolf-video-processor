<?php
/**
 * File: includes/PeerTube_Backend_Activation_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * Restart-safe R40 activation of one verified PeerTube backend descriptor.
 *
 * No method performs PeerTube HTTP or media work. Each call crosses at most
 * one local persistence boundary: journal a plan, apply the exact registry
 * CAS, confirm the registry outcome, or close the journal after eligibility
 * has been independently re-proved.
 */
final class PeerTube_Backend_Activation_Service
{
    public const STATUS_ADVANCED = 'advanced';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_INDETERMINATE = 'indeterminate';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_OUTSIDE_SCOPE = 'outside_scope';

    public function __construct(
        private readonly PeerTube_Connection_Operation_Store $operations,
        private readonly Managed_Backend_Secret_Store $secrets,
        private readonly Backend_Registry $registry,
        private readonly Backend_Adapter_Factory $factory
    ) {
    }

    /** @return array<string, mixed> */
    public function advance(string $operation_id, int $now): array
    {
        $record = null;

        try {
            if ($now < 1) {
                return self::projection(self::STATUS_REFUSED, null, $operation_id);
            }

            $probe = $this->operations->probe($operation_id);
            if (PeerTube_Connection_Operation_Store::PROBE_INDETERMINATE === $probe['status']) {
                return self::projection(self::STATUS_INDETERMINATE, null, $operation_id);
            }
            if (PeerTube_Connection_Operation_Store::PROBE_PRESENT !== $probe['status']) {
                return self::projection(
                    PeerTube_Connection_Operation_Store::PROBE_ABSENT === $probe['status']
                        ? self::STATUS_CONFLICT
                        : self::STATUS_REFUSED,
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
                return self::projection(self::STATUS_REFUSED, $record, $operation_id);
            }

            return match ($record['phase']) {
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY =>
                    $this->plan_activation($record, $now),
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_PLANNED =>
                    $this->activate_registry($record, $now),
                PeerTube_Connection_State_Machine::PHASE_ACTIVE_PENDING_CLOSE =>
                    $this->close_activation($record, $now),
                PeerTube_Connection_State_Machine::PHASE_COMPLETE =>
                    self::projection(self::STATUS_ACTIVE, $record),
                default => self::projection(self::STATUS_OUTSIDE_SCOPE, $record),
            };
        } catch (Throwable) {
            return self::projection(
                self::STATUS_INDETERMINATE,
                $record,
                $operation_id,
                Atomic_Option_Result::MUTATION_UNKNOWN
            );
        }
    }

    /** @param array<string, mixed> $record */
    private function plan_activation(array $record, int $now): array
    {
        if (! $this->adapter_available()) {
            return self::projection(self::STATUS_REFUSED, $record);
        }

        $prerequisite = $this->disabled_prerequisite_probe($record);
        if (Atomic_Option_Store::PROBE_AFTER !== $prerequisite) {
            return self::from_probe($prerequisite, $record);
        }

        $mutation_id = self::mutation_id();
        if ('' === $mutation_id) {
            return self::projection(self::STATUS_REFUSED, $record);
        }

        $prepared = $this->registry->prepare_peertube_activation(
            self::disabled_descriptor($record),
            $record['selected_destination'],
            $mutation_id
        );
        $plan = $prepared->plan();
        if (Atomic_Option_Plan_Result::READY !== $prepared->status() || null === $plan) {
            return self::from_plan($prepared, $record);
        }

        return $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_PLAN_ACTIVATION,
            $plan->evidence(),
            $now
        );
    }

    /** @param array<string, mixed> $record */
    private function activate_registry(array $record, int $now): array
    {
        if (! $this->adapter_available()) {
            return self::projection(self::STATUS_REFUSED, $record);
        }

        $secret_probe = $this->secret_probe($record);
        if (Atomic_Option_Store::PROBE_AFTER !== $secret_probe) {
            return self::from_probe($secret_probe, $record);
        }

        $descriptor = self::disabled_descriptor($record);
        $destination = $record['selected_destination'];
        $evidence = $record['last_mutation'];
        $result = $this->registry->reconcile_peertube_activation(
            $descriptor,
            $destination,
            $evidence
        );

        if (Atomic_Option_Result::CONFLICT === $result->status()) {
            $probe = $this->registry->probe_peertube_activation(
                $descriptor,
                $destination,
                $evidence
            );
            if (Atomic_Option_Store::PROBE_AFTER === $probe) {
                $result = Atomic_Option_Result::satisfied();
            } elseif (
                Atomic_Option_Store::PROBE_OTHER === $probe
                && Atomic_Option_Result::MUTATION_NONE === $result->mutation()
            ) {
                // The whole-registry evidence may be stale because this option
                // is shared. Replanning is permitted only after a separate
                // authoritative semantic probe proves that our exact target
                // descriptor is still disabled and unchanged.
                $disabled_probe = $this->registry->probe_disabled_peertube_state($descriptor);
                if (Atomic_Option_Store::PROBE_AFTER === $disabled_probe) {
                    return $this->replan_activation($record, $now);
                }
                return self::from_probe($disabled_probe, $record);
            } else {
                return self::from_probe($probe, $record);
            }
        }

        if (Atomic_Option_Result::APPLIED !== $result->status()) {
            return self::from_atomic($result, $record);
        }

        $probe = $this->registry->probe_peertube_activation(
            $descriptor,
            $destination,
            $evidence
        );
        if (Atomic_Option_Store::PROBE_AFTER !== $probe) {
            return self::from_probe($probe, $record, $result->mutation());
        }

        // The registry write is one consequential boundary. Confirmation is a
        // separate explicit request after the target mutation is authoritative.
        if (Atomic_Option_Result::MUTATION_NONE !== $result->mutation()) {
            return self::projection(self::STATUS_ADVANCED, $record, '', $result->mutation());
        }

        return $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_CONFIRM_ACTIVATION,
            array(),
            $now
        );
    }

    /** @param array<string, mixed> $record */
    private function replan_activation(array $record, int $now): array
    {
        $mutation_id = self::mutation_id();
        if ('' === $mutation_id) {
            return self::projection(self::STATUS_REFUSED, $record);
        }

        $prepared = $this->registry->prepare_peertube_activation(
            self::disabled_descriptor($record),
            $record['selected_destination'],
            $mutation_id
        );
        $plan = $prepared->plan();
        if (Atomic_Option_Plan_Result::READY !== $prepared->status() || null === $plan) {
            return self::from_plan($prepared, $record);
        }

        return $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_REPLAN_ACTIVATION,
            $plan->evidence(),
            $now
        );
    }

    /** @param array<string, mixed> $record */
    private function close_activation(array $record, int $now): array
    {
        $secret_probe = $this->secret_probe($record);
        if (Atomic_Option_Store::PROBE_AFTER !== $secret_probe) {
            return self::from_probe($secret_probe, $record);
        }

        $registry_probe = $this->registry->probe_active_peertube_state(
            self::disabled_descriptor($record),
            $record['selected_destination']
        );
        if (Atomic_Option_Store::PROBE_AFTER !== $registry_probe) {
            return self::from_probe($registry_probe, $record);
        }

        if (
            ! $this->registry->eligible(
                $record['backend_id'],
                Backend_Capabilities::DELIVERY_EMBED,
                $this->factory
            )
        ) {
            return self::projection(self::STATUS_REFUSED, $record);
        }

        return $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_COMPLETE,
            array(),
            $now,
            self::STATUS_ACTIVE
        );
    }

    /** @param array<string, mixed> $record */
    private function disabled_prerequisite_probe(array $record): string
    {
        $secret = $this->secret_probe($record);
        $registry = $this->registry->probe_disabled_peertube_state(
            self::disabled_descriptor($record)
        );

        if (
            Atomic_Option_Store::PROBE_INDETERMINATE === $secret
            || Atomic_Option_Store::PROBE_INDETERMINATE === $registry
        ) {
            return Atomic_Option_Store::PROBE_INDETERMINATE;
        }
        if (
            Atomic_Option_Store::PROBE_REFUSED === $secret
            || Atomic_Option_Store::PROBE_REFUSED === $registry
        ) {
            return Atomic_Option_Store::PROBE_REFUSED;
        }

        return Atomic_Option_Store::PROBE_AFTER === $secret
            && Atomic_Option_Store::PROBE_AFTER === $registry
                ? Atomic_Option_Store::PROBE_AFTER
                : Atomic_Option_Store::PROBE_OTHER;
    }

    /** @param array<string, mixed> $record */
    private function secret_probe(array $record): string
    {
        $state = $this->secrets->provisioning_state(
            $record['secret_ref'],
            $record['backend_id'],
            $record['provisioning_id']
        );

        return match ($state['state']) {
            Managed_Backend_Secret_Store::PROVISION_READY =>
                $record['secret_generation'] === $state['generation']
                    ? Atomic_Option_Store::PROBE_AFTER
                    : Atomic_Option_Store::PROBE_OTHER,
            Managed_Backend_Secret_Store::PROVISION_INDETERMINATE =>
                Atomic_Option_Store::PROBE_INDETERMINATE,
            Managed_Backend_Secret_Store::PROVISION_UNREADABLE =>
                Atomic_Option_Store::PROBE_REFUSED,
            default => Atomic_Option_Store::PROBE_OTHER,
        };
    }

    private function adapter_available(): bool
    {
        return $this->factory->has(Backend_Registry::PEERTUBE_TYPE);
    }

    /**
     * Persist one pure journal transition and classify the authoritative row.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $payload
     */
    private function persist_event(
        array $record,
        string $event,
        array $payload,
        int $now,
        string $confirmed_status = self::STATUS_ADVANCED
    ): array {
        $next = PeerTube_Connection_State_Machine::apply($record, $event, $payload, $now);
        if (null === $next) {
            return self::projection(self::STATUS_REFUSED, $record);
        }

        $write = $this->operations->apply_event(
            $record['operation_id'],
            $record['record_revision'],
            $event,
            $payload,
            $now
        );
        $probe = $this->operations->probe($record['operation_id']);

        if (PeerTube_Connection_Operation_Store::PROBE_PRESENT === $probe['status']) {
            $current = is_array($probe['record']) ? $probe['record'] : $record;
            if ($next === $current && Atomic_Option_Result::APPLIED === $write->status()) {
                return self::projection($confirmed_status, $current, '', $write->mutation());
            }
            if ($record === $current) {
                return self::from_atomic($write, $current);
            }

            return self::projection(
                Atomic_Option_Result::INDETERMINATE === $write->status()
                || Atomic_Option_Result::MUTATION_NONE !== $write->mutation()
                    ? self::STATUS_INDETERMINATE
                    : self::STATUS_CONFLICT,
                $current,
                '',
                $write->mutation()
            );
        }

        return self::projection(
            self::STATUS_INDETERMINATE,
            $record,
            '',
            $write->mutation()
        );
    }

    /** @param array<string, mixed> $record */
    private static function disabled_descriptor(array $record): array
    {
        return array(
            'id'                  => $record['backend_id'],
            'type'                => Backend_Registry::PEERTUBE_TYPE,
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

    /** @param array<string, mixed>|null $record */
    private static function from_plan(Atomic_Option_Plan_Result $result, ?array $record): array
    {
        $status = match ($result->status()) {
            Atomic_Option_Plan_Result::CONFLICT => self::STATUS_CONFLICT,
            Atomic_Option_Plan_Result::INDETERMINATE => self::STATUS_INDETERMINATE,
            default => self::STATUS_REFUSED,
        };

        return self::projection($status, $record);
    }

    /** @param array<string, mixed>|null $record */
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

        return self::projection($status, $record, '', $mutation);
    }

    /** @param array<string, mixed>|null $record */
    private static function from_atomic(Atomic_Option_Result $result, ?array $record): array
    {
        $status = match ($result->status()) {
            Atomic_Option_Result::APPLIED => self::STATUS_ADVANCED,
            Atomic_Option_Result::CONFLICT => self::STATUS_CONFLICT,
            Atomic_Option_Result::INDETERMINATE => self::STATUS_INDETERMINATE,
            default => self::STATUS_REFUSED,
        };

        return self::projection($status, $record, '', $result->mutation());
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array<string, mixed>
     */
    private static function projection(
        string $status,
        ?array $record = null,
        string $operation_id = '',
        string $mutation = Atomic_Option_Result::MUTATION_NONE
    ): array {
        $record_operation_id = is_array($record)
            ? PeerTube_Connection_Input::operation_id($record['operation_id'] ?? null)
            : '';

        return array(
            'status'          => $status,
            'mutation'        => $mutation,
            'operation_id'    => '' !== $record_operation_id
                ? $record_operation_id
                : PeerTube_Connection_Input::operation_id($operation_id),
            'backend_id'      => is_array($record) && is_string($record['backend_id'] ?? null)
                ? $record['backend_id']
                : '',
            'phase'           => is_array($record) && is_string($record['phase'] ?? null)
                ? $record['phase']
                : '',
            'record_revision' => is_array($record) && is_int($record['record_revision'] ?? null)
                ? $record['record_revision']
                : 0,
            'retry_after'     => 0,
        );
    }
}

// EOF: includes/PeerTube_Backend_Activation_Service.php
