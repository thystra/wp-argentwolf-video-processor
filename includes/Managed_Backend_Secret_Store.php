<?php
/**
 * File: includes/Managed_Backend_Secret_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

final class Managed_Backend_Secret_Store implements Backend_Secret_Store
{
    public const OPTION = 'argentwolf_video_processor_backend_secrets';

    public const PROVISION_ABSENT = 'absent';
    public const PROVISION_PENDING = 'pending';
    public const PROVISION_READY = 'ready';
    public const PROVISION_CONFLICT = 'conflict';
    public const PROVISION_UNREADABLE = 'unreadable';
    public const PROVISION_INDETERMINATE = 'indeterminate';

    private const MANIFEST_VERSION = 1;
    private const LEGACY_RECORD_VERSION = 1;
    private const RECORD_VERSION = 2;
    private const STATE_PENDING = 'pending';
    private const STATE_READY = 'ready';
    private const NONAUTOLOAD_VALUES = array('no', 'off', 'auto-off');

    public function available(): bool
    {
        global $wpdb;

        return Backend_Secret_Crypto::available()
            && class_exists(Atomic_Option_Store::class)
            && class_exists(Atomic_Option_Result::class)
            && class_exists(Atomic_Option_Mutation_Plan::class)
            && class_exists(Atomic_Option_Plan_Result::class)
            && is_object($wpdb)
            && isset($wpdb->options)
            && function_exists('do_action')
            && function_exists('wp_cache_delete');
    }

    /**
     * Establish only the versioned provider manifest.
     *
     * Reservation planning and execution deliberately require this manifest
     * to exist already. Keeping initialization separate prevents a caller
     * from mistaking a manifest-only partial mutation for a reserved slot.
     */
    public function initialize_classified(): Atomic_Option_Result
    {
        if (! $this->available()) {
            return Atomic_Option_Result::refused();
        }

        return $this->ensure_manifest_classified();
    }

    /**
     * Prospectively describe creation of one exact empty managed slot.
     *
     * This method never mutates either the manifest or the record option.
     */
    public function prepare_reservation(
        string $secret_ref,
        string $backend_id,
        string $provisioning_id,
        string $mutation_id
    ): Atomic_Option_Plan_Result {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if (
            ! self::valid_ref($secret_ref)
            || '' === $backend_id
            || ! self::valid_provisioning_id($provisioning_id)
            || ! $this->available()
        ) {
            return Atomic_Option_Plan_Result::refused();
        }

        $manifest = $this->existing_manifest_classified();
        if (Atomic_Option_Result::INDETERMINATE === $manifest->status()) {
            return Atomic_Option_Plan_Result::indeterminate();
        }
        if (Atomic_Option_Result::APPLIED !== $manifest->status()) {
            return Atomic_Option_Plan_Result::refused();
        }

        $store = new Atomic_Option_Store(self::record_option($secret_ref));
        $snapshot = $store->snapshot();

        return $store->prepare_compare_exchange(
            $snapshot,
            self::pending_record($backend_id, $provisioning_id),
            'secret_reserve',
            $mutation_id
        );
    }

    /**
     * Apply only a prospectively validated exact pending-slot plan.
     */
    public function apply_reservation_plan(
        string $secret_ref,
        string $backend_id,
        string $provisioning_id,
        Atomic_Option_Mutation_Plan $plan
    ): Atomic_Option_Result {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if (
            ! self::valid_ref($secret_ref)
            || '' === $backend_id
            || ! self::valid_provisioning_id($provisioning_id)
            || ! $this->available()
        ) {
            return Atomic_Option_Result::refused();
        }

        $manifest = $this->existing_manifest_classified();
        if (Atomic_Option_Result::APPLIED !== $manifest->status()) {
            return $manifest;
        }
        if (! $this->valid_reservation_plan(
            $plan,
            $secret_ref,
            $backend_id,
            $provisioning_id
        )) {
            return Atomic_Option_Result::refused();
        }

        return (new Atomic_Option_Store(self::record_option($secret_ref)))->apply_plan($plan);
    }

    /**
     * Classify the authoritative record against journaled mutation evidence.
     *
     * An `after` result additionally proves that the current record is the
     * exact non-secret pending slot bound to this provisioning operation.
     * A ready or foreign record is always `other`; it is never accepted as a
     * completed reservation.
     *
     * @param array<string, mixed> $evidence
     */
    public function probe_reservation(
        string $secret_ref,
        string $backend_id,
        string $provisioning_id,
        array $evidence
    ): string {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if (
            ! self::valid_ref($secret_ref)
            || '' === $backend_id
            || ! self::valid_provisioning_id($provisioning_id)
            || ! $this->available()
            || 'secret_reserve' !== ($evidence['kind'] ?? null)
        ) {
            return Atomic_Option_Store::PROBE_REFUSED;
        }

        $manifest = $this->existing_manifest_classified();
        if (Atomic_Option_Result::INDETERMINATE === $manifest->status()) {
            return Atomic_Option_Store::PROBE_INDETERMINATE;
        }
        if (Atomic_Option_Result::APPLIED !== $manifest->status()) {
            return Atomic_Option_Store::PROBE_REFUSED;
        }

        $store = new Atomic_Option_Store(self::record_option($secret_ref));
        $probe = $store->probe_evidence($evidence);
        if (Atomic_Option_Store::PROBE_AFTER !== $probe) {
            return $probe;
        }

        $snapshot = $store->snapshot();
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return Atomic_Option_Store::PROBE_INDETERMINATE;
        }
        if (! self::supported_present_snapshot($snapshot)) {
            return $snapshot->is_absent()
                ? Atomic_Option_Store::PROBE_OTHER
                : Atomic_Option_Store::PROBE_REFUSED;
        }

        $record = self::normalize_record($snapshot->value());
        if (self::pending_record($backend_id, $provisioning_id) !== $record) {
            return Atomic_Option_Store::PROBE_OTHER;
        }

        return $store->probe_evidence($evidence);
    }

    /**
     * Reconcile one already-journaled reservation plan.
     *
     * The exact recorded `after` state is satisfied. The exact recorded
     * `before` state may be applied using the same mutation ID and evidence.
     * Every other state is preserved and reported without overwrite/delete.
     *
     * @param array<string, mixed> $evidence
     */
    public function reconcile_reservation(
        string $secret_ref,
        string $backend_id,
        string $provisioning_id,
        array $evidence
    ): Atomic_Option_Result {
        $probe = $this->probe_reservation(
            $secret_ref,
            $backend_id,
            $provisioning_id,
            $evidence
        );

        if (Atomic_Option_Store::PROBE_AFTER === $probe) {
            return Atomic_Option_Result::satisfied();
        }
        if (Atomic_Option_Store::PROBE_INDETERMINATE === $probe) {
            return Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_NONE,
                Atomic_Option_Result::PHASE_VALIDATION
            );
        }
        if (Atomic_Option_Store::PROBE_REFUSED === $probe) {
            return Atomic_Option_Result::refused();
        }
        if (Atomic_Option_Store::PROBE_BEFORE !== $probe) {
            return Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
        }

        $mutation_id = $evidence['mutation_id'] ?? null;
        if (! is_string($mutation_id)) {
            return Atomic_Option_Result::refused();
        }

        $prepared = $this->prepare_reservation(
            $secret_ref,
            $backend_id,
            $provisioning_id,
            $mutation_id
        );
        $plan = $prepared->plan();
        if (
            Atomic_Option_Plan_Result::READY !== $prepared->status()
            || null === $plan
            || $plan->evidence() !== $evidence
        ) {
            return self::plan_failure($prepared);
        }

        return $this->apply_reservation_plan(
            $secret_ref,
            $backend_id,
            $provisioning_id,
            $plan
        );
    }

    /**
     * Reserve an empty, non-secret managed slot before any password grant.
     */
    public function reserve(
        string $secret_ref,
        string $backend_id,
        string $provisioning_id
    ): Atomic_Option_Result {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if (
            ! self::valid_ref($secret_ref)
            || '' === $backend_id
            || ! self::valid_provisioning_id($provisioning_id)
            || ! $this->available()
        ) {
            return Atomic_Option_Result::refused();
        }

        $manifest = $this->ensure_manifest_classified();
        if (Atomic_Option_Result::APPLIED !== $manifest->status()) {
            return $manifest;
        }

        $record = array(
            'version'         => self::RECORD_VERSION,
            'state'           => self::STATE_PENDING,
            'backend_id'      => $backend_id,
            'provisioning_id' => $provisioning_id,
            'generation'      => 0,
            'envelope'        => array(),
        );

        $store = new Atomic_Option_Store(self::record_option($secret_ref));
        $snapshot = $store->snapshot();
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return self::combine_manifest_reservation_result(
                $manifest,
                self::snapshot_indeterminate()
            );
        }
        if (Atomic_Option_Snapshot::REFUSED === $snapshot->state()) {
            return self::combine_manifest_reservation_result(
                $manifest,
                Atomic_Option_Result::refused()
            );
        }

        if ($snapshot->is_present()) {
            if (! self::supported_present_snapshot($snapshot)) {
                return self::combine_manifest_reservation_result(
                    $manifest,
                    Atomic_Option_Result::refused()
                );
            }

            $existing = self::normalize_record($snapshot->value());
            $result = is_array($existing)
                && self::STATE_PENDING === $existing['state']
                && self::RECORD_VERSION === $existing['version']
                && $backend_id === $existing['backend_id']
                && $provisioning_id === $existing['provisioning_id']
                    ? Atomic_Option_Result::satisfied()
                    : Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);

            return self::combine_manifest_reservation_result($manifest, $result);
        }

        return self::combine_manifest_reservation_result(
            $manifest,
            $store->compare_exchange($snapshot, $record)
        );
    }

    /**
     * Fill only the exact pending slot reserved for this provisioning ID.
     *
     * @param array<string, mixed> $secret
     */
    public function commit_reserved(
        string $secret_ref,
        string $backend_id,
        string $provisioning_id,
        array $secret
    ): Atomic_Option_Result {
        $backend_id = Backend_Identity::sanitize($backend_id);
        $secret = self::sanitize_secret($secret);
        if (
            ! self::valid_ref($secret_ref)
            || '' === $backend_id
            || ! self::valid_provisioning_id($provisioning_id)
            || [] === $secret
            || ! $this->available()
        ) {
            return Atomic_Option_Result::refused();
        }

        $manifest = $this->existing_manifest_classified();
        if (Atomic_Option_Result::APPLIED !== $manifest->status()) {
            return $manifest;
        }

        $store = new Atomic_Option_Store(self::record_option($secret_ref));
        $snapshot = $store->snapshot();
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return self::snapshot_indeterminate();
        }
        if (! $snapshot->is_present()) {
            return Atomic_Option_Snapshot::ABSENT === $snapshot->state()
                ? Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION)
                : Atomic_Option_Result::refused();
        }
        if (! self::supported_present_snapshot($snapshot)) {
            return Atomic_Option_Result::refused();
        }

        $record = self::normalize_record($snapshot->value());
        if (
            ! is_array($record)
            || self::RECORD_VERSION !== $record['version']
            || $backend_id !== $record['backend_id']
            || $provisioning_id !== $record['provisioning_id']
        ) {
            return Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
        }

        if (self::STATE_READY === $record['state']) {
            if (1 !== $record['generation']) {
                return Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
            }

            $stored_secret = $this->decrypt_record($secret_ref, $record);
            if (null === $stored_secret) {
                return self::snapshot_indeterminate();
            }

            return $secret === $stored_secret
                ? Atomic_Option_Result::satisfied()
                : Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
        }

        if (self::STATE_PENDING !== $record['state'] || 0 !== $record['generation']) {
            return Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
        }

        try {
            $envelope = Backend_Secret_Crypto::encrypt(
                $secret,
                self::aad_v2($secret_ref, $backend_id, $provisioning_id, 1)
            );
        } catch (Throwable) {
            return Atomic_Option_Result::refused();
        }

        $ready = array(
            'version'         => self::RECORD_VERSION,
            'state'           => self::STATE_READY,
            'backend_id'      => $backend_id,
            'provisioning_id' => $provisioning_id,
            'generation'      => 1,
            'envelope'        => $envelope,
        );

        return $store->compare_exchange($snapshot, $ready);
    }

    /**
     * Probe only safe provisioning state; decrypted token material never
     * crosses this boundary.
     *
     * @return array{state:string,generation:int}
     */
    public function provisioning_state(
        string $secret_ref,
        string $backend_id,
        string $provisioning_id
    ): array {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if (
            ! self::valid_ref($secret_ref)
            || '' === $backend_id
            || ! self::valid_provisioning_id($provisioning_id)
            || ! $this->available()
        ) {
            return self::provision_state(self::PROVISION_UNREADABLE, 0);
        }

        $manifest = $this->existing_manifest_classified();
        if (Atomic_Option_Result::INDETERMINATE === $manifest->status()) {
            return self::provision_state(self::PROVISION_INDETERMINATE, 0);
        }
        if (Atomic_Option_Result::APPLIED !== $manifest->status()) {
            return self::provision_state(self::PROVISION_UNREADABLE, 0);
        }

        $snapshot = (new Atomic_Option_Store(self::record_option($secret_ref)))->snapshot();
        if ($snapshot->is_absent()) {
            return self::provision_state(self::PROVISION_ABSENT, 0);
        }
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return self::provision_state(self::PROVISION_INDETERMINATE, 0);
        }
        if (! $snapshot->is_present()) {
            return self::provision_state(self::PROVISION_UNREADABLE, 0);
        }
        if (! self::supported_present_snapshot($snapshot)) {
            return self::provision_state(self::PROVISION_UNREADABLE, 0);
        }

        $record = self::normalize_record($snapshot->value());
        if (
            ! is_array($record)
            || self::RECORD_VERSION !== $record['version']
            || $backend_id !== $record['backend_id']
            || $provisioning_id !== $record['provisioning_id']
        ) {
            return self::provision_state(self::PROVISION_CONFLICT, 0);
        }

        if (self::STATE_PENDING === $record['state']) {
            return self::provision_state(self::PROVISION_PENDING, 0);
        }

        if (null === $this->decrypt_record($secret_ref, $record)) {
            return self::provision_state(self::PROVISION_UNREADABLE, $record['generation']);
        }

        return self::provision_state(self::PROVISION_READY, $record['generation']);
    }

    /**
     * Remove only a still-empty exact reservation. A ready record is never
     * deleted by pre-grant cleanup.
     */
    public function delete_reserved_if_pending(
        string $secret_ref,
        string $backend_id,
        string $provisioning_id
    ): Atomic_Option_Result {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if (
            ! self::valid_ref($secret_ref)
            || '' === $backend_id
            || ! self::valid_provisioning_id($provisioning_id)
            || ! $this->available()
        ) {
            return Atomic_Option_Result::refused();
        }

        $manifest = $this->existing_manifest_classified();
        if (Atomic_Option_Result::APPLIED !== $manifest->status()) {
            return $manifest;
        }

        $store = new Atomic_Option_Store(self::record_option($secret_ref));
        $snapshot = $store->snapshot();
        if ($snapshot->is_absent()) {
            return Atomic_Option_Result::satisfied();
        }
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return self::snapshot_indeterminate();
        }
        if (! $snapshot->is_present()) {
            return Atomic_Option_Result::refused();
        }
        if (! self::supported_present_snapshot($snapshot)) {
            return Atomic_Option_Result::refused();
        }

        $record = self::normalize_record($snapshot->value());
        if (
            ! is_array($record)
            || self::RECORD_VERSION !== $record['version']
            || self::STATE_PENDING !== $record['state']
            || $backend_id !== $record['backend_id']
            || $provisioning_id !== $record['provisioning_id']
        ) {
            return Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
        }

        return $store->compare_delete($snapshot);
    }

    public function read(string $secret_ref, string $backend_id): ?array
    {
        if (! $this->manifest_valid() || ! self::valid_ref($secret_ref)) {
            return null;
        }

        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id) {
            return null;
        }

        $snapshot = (new Atomic_Option_Store(self::record_option($secret_ref)))->snapshot();
        if (! self::supported_present_snapshot($snapshot)) {
            return null;
        }

        $record = self::normalize_record($snapshot->value());
        if (
            ! is_array($record)
            || self::STATE_READY !== $record['state']
            || $backend_id !== $record['backend_id']
        ) {
            return null;
        }

        $secret = $this->decrypt_record($secret_ref, $record);
        if (null === $secret) {
            return null;
        }

        $secret['generation'] = $record['generation'];
        return $secret;
    }

    public function replace(
        string $secret_ref,
        string $backend_id,
        array $secret,
        int $expected_generation
    ): bool {
        $result = $this->replace_classified(
            $secret_ref,
            $backend_id,
            $secret,
            $expected_generation
        );

        return Atomic_Option_Result::APPLIED === $result->status()
            && Atomic_Option_Result::MUTATION_APPLIED === $result->mutation();
    }

    /**
     * Classified exact-generation replacement for future refresh callers.
     *
     * @param array<string, mixed> $secret
     */
    public function replace_classified(
        string $secret_ref,
        string $backend_id,
        array $secret,
        int $expected_generation
    ): Atomic_Option_Result {
        $backend_id = Backend_Identity::sanitize($backend_id);
        $secret = self::sanitize_secret($secret);
        if (
            ! self::valid_ref($secret_ref)
            || '' === $backend_id
            || [] === $secret
            || $expected_generation < 1
            || $expected_generation >= PHP_INT_MAX
            || ! $this->available()
        ) {
            return Atomic_Option_Result::refused();
        }

        $manifest = $this->existing_manifest_classified();
        if (Atomic_Option_Result::APPLIED !== $manifest->status()) {
            return $manifest;
        }

        $store = new Atomic_Option_Store(self::record_option($secret_ref));
        $snapshot = $store->snapshot();
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return self::snapshot_indeterminate();
        }
        if (! $snapshot->is_present()) {
            return Atomic_Option_Snapshot::ABSENT === $snapshot->state()
                ? Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION)
                : Atomic_Option_Result::refused();
        }
        if (! self::supported_present_snapshot($snapshot)) {
            return Atomic_Option_Result::refused();
        }

        $record = self::normalize_record($snapshot->value());
        if (
            ! is_array($record)
            || self::STATE_READY !== $record['state']
            || $backend_id !== $record['backend_id']
            || $expected_generation !== $record['generation']
        ) {
            return Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
        }

        $generation = $expected_generation + 1;
        try {
            $envelope = Backend_Secret_Crypto::encrypt(
                $secret,
                self::aad_for_fields(
                    $secret_ref,
                    $record['version'],
                    $backend_id,
                    $record['provisioning_id'],
                    $generation
                )
            );
        } catch (Throwable) {
            return Atomic_Option_Result::refused();
        }

        if (self::LEGACY_RECORD_VERSION === $record['version']) {
            $replacement = array(
                'version'    => self::LEGACY_RECORD_VERSION,
                'backend_id' => $backend_id,
                'generation' => $generation,
                'envelope'   => $envelope,
            );
        } else {
            $replacement = array(
                'version'         => self::RECORD_VERSION,
                'state'           => self::STATE_READY,
                'backend_id'      => $backend_id,
                'provisioning_id' => $record['provisioning_id'],
                'generation'      => $generation,
                'envelope'        => $envelope,
            );
        }

        return $store->compare_exchange($snapshot, $replacement);
    }

    public function delete(
        string $secret_ref,
        string $backend_id,
        int $expected_generation
    ): bool {
        $result = $this->delete_classified(
            $secret_ref,
            $backend_id,
            $expected_generation
        );
        return Atomic_Option_Result::APPLIED === $result->status()
            && Atomic_Option_Result::MUTATION_APPLIED === $result->mutation();
    }

    public function delete_classified(
        string $secret_ref,
        string $backend_id,
        int $expected_generation
    ): Atomic_Option_Result {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if (
            ! self::valid_ref($secret_ref)
            || '' === $backend_id
            || $expected_generation < 1
            || ! $this->available()
        ) {
            return Atomic_Option_Result::refused();
        }

        $manifest = $this->existing_manifest_classified();
        if (Atomic_Option_Result::APPLIED !== $manifest->status()) {
            return $manifest;
        }

        $store = new Atomic_Option_Store(self::record_option($secret_ref));
        $snapshot = $store->snapshot();
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return self::snapshot_indeterminate();
        }
        if (! $snapshot->is_present()) {
            return Atomic_Option_Snapshot::ABSENT === $snapshot->state()
                ? Atomic_Option_Result::satisfied()
                : Atomic_Option_Result::refused();
        }
        if (! self::supported_present_snapshot($snapshot)) {
            return Atomic_Option_Result::refused();
        }

        $record = self::normalize_record($snapshot->value());
        if (
            ! is_array($record)
            || self::STATE_READY !== $record['state']
            || $backend_id !== $record['backend_id']
            || $expected_generation !== $record['generation']
        ) {
            return Atomic_Option_Result::conflict(Atomic_Option_Result::PHASE_VALIDATION);
        }

        return $store->compare_delete($snapshot);
    }

    /** @return array<string, mixed> */
    private static function pending_record(
        string $backend_id,
        string $provisioning_id
    ): array {
        return array(
            'version'         => self::RECORD_VERSION,
            'state'           => self::STATE_PENDING,
            'backend_id'      => $backend_id,
            'provisioning_id' => $provisioning_id,
            'generation'      => 0,
            'envelope'        => array(),
        );
    }

    private function valid_reservation_plan(
        Atomic_Option_Mutation_Plan $plan,
        string $secret_ref,
        string $backend_id,
        string $provisioning_id
    ): bool {
        $before = $plan->before();
        $written = $plan->written();

        return self::record_option($secret_ref) === $plan->option()
            && 'secret_reserve' === $plan->kind()
            && $before->is_absent()
            && self::supported_present_snapshot($written)
            && self::pending_record($backend_id, $provisioning_id) === $written->value();
    }

    private static function plan_failure(
        Atomic_Option_Plan_Result $prepared
    ): Atomic_Option_Result {
        return match ($prepared->status()) {
            Atomic_Option_Plan_Result::CONFLICT => Atomic_Option_Result::conflict(
                Atomic_Option_Result::PHASE_VALIDATION
            ),
            Atomic_Option_Plan_Result::INDETERMINATE => Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_NONE,
                Atomic_Option_Result::PHASE_VALIDATION
            ),
            default => Atomic_Option_Result::refused(),
        };
    }

    private function ensure_manifest_classified(): Atomic_Option_Result
    {
        $store = new Atomic_Option_Store(self::OPTION);
        $snapshot = $store->snapshot();
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return self::snapshot_indeterminate();
        }
        if (Atomic_Option_Snapshot::REFUSED === $snapshot->state()) {
            return Atomic_Option_Result::refused();
        }

        $manifest = array('version' => self::MANIFEST_VERSION);
        if ($snapshot->is_present()) {
            return $manifest === $snapshot->value()
                && in_array((string) $snapshot->autoload(), self::NONAUTOLOAD_VALUES, true)
                    ? Atomic_Option_Result::satisfied()
                    : Atomic_Option_Result::refused();
        }

        return $store->compare_exchange($snapshot, $manifest);
    }

    /**
     * Preserve a manifest mutation that occurred earlier in reserve(). A
     * later slot failure is a known partial mutation, never a no-mutation
     * conflict/refusal. A satisfied pre-existing slot still means this call
     * applied the missing manifest.
     */
    private static function combine_manifest_reservation_result(
        Atomic_Option_Result $manifest,
        Atomic_Option_Result $reservation
    ): Atomic_Option_Result {
        if (Atomic_Option_Result::MUTATION_APPLIED !== $manifest->mutation()) {
            return $reservation;
        }

        if (Atomic_Option_Result::APPLIED === $reservation->status()) {
            return Atomic_Option_Result::MUTATION_APPLIED === $reservation->mutation()
                ? $reservation
                : Atomic_Option_Result::applied();
        }

        $mutation = Atomic_Option_Result::MUTATION_UNKNOWN === $reservation->mutation()
            ? Atomic_Option_Result::MUTATION_UNKNOWN
            : Atomic_Option_Result::MUTATION_APPLIED;

        return Atomic_Option_Result::indeterminate($mutation, $reservation->phase());
    }

    private function manifest_valid(): bool
    {
        return Atomic_Option_Result::APPLIED
            === $this->existing_manifest_classified()->status();
    }

    private function existing_manifest_classified(): Atomic_Option_Result
    {
        if (! $this->available()) {
            return Atomic_Option_Result::refused();
        }

        $snapshot = (new Atomic_Option_Store(self::OPTION))->snapshot();
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return self::snapshot_indeterminate();
        }
        if (! $snapshot->is_present()) {
            return Atomic_Option_Result::refused();
        }

        return array('version' => self::MANIFEST_VERSION) === $snapshot->value()
            && in_array((string) $snapshot->autoload(), self::NONAUTOLOAD_VALUES, true)
                ? Atomic_Option_Result::satisfied()
                : Atomic_Option_Result::refused();
    }

    /**
     * @param mixed $value
     * @return array{
     *   version:int,
     *   state:string,
     *   backend_id:string,
     *   provisioning_id:string,
     *   generation:int,
     *   envelope:array<string,mixed>
     * }|null
     */
    private static function normalize_record(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $version = $value['version'] ?? null;
        if (self::LEGACY_RECORD_VERSION === $version) {
            if (! self::has_exact_keys(
                $value,
                array('version', 'backend_id', 'generation', 'envelope')
            )) {
                return null;
            }

            $backend_id = Backend_Identity::sanitize($value['backend_id'] ?? null);
            $generation = self::positive_int($value['generation'] ?? null);
            if ('' === $backend_id || $generation < 1 || ! is_array($value['envelope'] ?? null)) {
                return null;
            }

            return array(
                'version'         => self::LEGACY_RECORD_VERSION,
                'state'           => self::STATE_READY,
                'backend_id'      => $backend_id,
                'provisioning_id' => '',
                'generation'      => $generation,
                'envelope'        => $value['envelope'],
            );
        }

        if (
            self::RECORD_VERSION !== $version
            || ! self::has_exact_keys(
                $value,
                array(
                    'version',
                    'state',
                    'backend_id',
                    'provisioning_id',
                    'generation',
                    'envelope',
                )
            )
        ) {
            return null;
        }

        $state = $value['state'] ?? null;
        $backend_id = Backend_Identity::sanitize($value['backend_id'] ?? null);
        $provisioning_id = $value['provisioning_id'] ?? null;
        $generation = self::nonnegative_int($value['generation'] ?? null);
        $envelope = $value['envelope'] ?? null;
        if (
            ! is_string($state)
            || ! in_array($state, array(self::STATE_PENDING, self::STATE_READY), true)
            || '' === $backend_id
            || ! self::valid_provisioning_id($provisioning_id)
            || $generation < 0
            || ! is_array($envelope)
        ) {
            return null;
        }

        if (
            (self::STATE_PENDING === $state && (0 !== $generation || [] !== $envelope))
            || (self::STATE_READY === $state && ($generation < 1 || [] === $envelope))
        ) {
            return null;
        }

        return array(
            'version'         => self::RECORD_VERSION,
            'state'           => $state,
            'backend_id'      => $backend_id,
            'provisioning_id' => $provisioning_id,
            'generation'      => $generation,
            'envelope'        => $envelope,
        );
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>|null
     */
    private function decrypt_record(string $secret_ref, array $record): ?array
    {
        if (self::STATE_READY !== ($record['state'] ?? '')) {
            return null;
        }

        $secret = Backend_Secret_Crypto::decrypt(
            $record['envelope'],
            self::aad_for_fields(
                $secret_ref,
                $record['version'],
                $record['backend_id'],
                $record['provisioning_id'],
                $record['generation']
            )
        );
        if (! is_array($secret)) {
            return null;
        }

        $secret = self::sanitize_secret($secret);
        return [] === $secret ? null : $secret;
    }

    private static function aad_for_fields(
        string $secret_ref,
        int $version,
        string $backend_id,
        string $provisioning_id,
        int $generation
    ): string {
        return self::LEGACY_RECORD_VERSION === $version
            ? self::aad_v1($secret_ref, $backend_id, $generation)
            : self::aad_v2(
                $secret_ref,
                $backend_id,
                $provisioning_id,
                $generation
            );
    }

    private static function aad_v1(
        string $secret_ref,
        string $backend_id,
        int $generation
    ): string {
        return 'awvp-secret|' . $secret_ref . '|' . $backend_id . '|' . $generation;
    }

    private static function aad_v2(
        string $secret_ref,
        string $backend_id,
        string $provisioning_id,
        int $generation
    ): string {
        return 'awvp-secret-v2|'
            . $secret_ref . '|'
            . $backend_id . '|'
            . $provisioning_id . '|'
            . $generation;
    }

    /**
     * @param array<string, mixed> $secret
     * @return array<string, mixed>
     */
    private static function sanitize_secret(array $secret): array
    {
        $allowed = array(
            'access_token',
            'refresh_token',
            'access_expires_at',
            'refresh_expires_at',
        );

        foreach (array_keys($secret) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                return array();
            }
        }

        $access_token = self::bounded_secret($secret['access_token'] ?? null);
        $refresh_token = self::bounded_secret($secret['refresh_token'] ?? null);
        $access_expires_at = self::positive_int($secret['access_expires_at'] ?? null);
        $refresh_expires_at = self::positive_int($secret['refresh_expires_at'] ?? null);

        if (
            '' === $access_token
            || $access_expires_at < 1
            || ('' !== $refresh_token && $refresh_expires_at < 1)
            || ('' === $refresh_token && $refresh_expires_at > 0)
        ) {
            return array();
        }

        return array(
            'access_token'       => $access_token,
            'refresh_token'      => $refresh_token,
            'access_expires_at'  => $access_expires_at,
            'refresh_expires_at' => $refresh_expires_at,
        );
    }

    private static function bounded_secret(mixed $value): string
    {
        return is_string($value) && '' !== $value && strlen($value) <= 16384
            ? $value
            : '';
    }

    private static function valid_ref(mixed $value): bool
    {
        return is_string($value)
            && 1 === preg_match('/^managed_[a-f0-9]{32}$/D', $value);
    }

    private static function valid_provisioning_id(mixed $value): bool
    {
        return is_string($value)
            && 1 === preg_match('/^provision_[a-f0-9]{32}$/D', $value);
    }

    private static function positive_int(mixed $value): int
    {
        $value = self::nonnegative_int($value);
        return $value > 0 ? $value : 0;
    }

    private static function nonnegative_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : -1;
        }

        if (! is_string($value) || 1 !== preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
            return -1;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0)));
        return false !== $parsed && (string) $parsed === $value ? (int) $parsed : -1;
    }

    private static function record_option(string $secret_ref): string
    {
        return self::OPTION . '_' . $secret_ref;
    }

    private static function supported_present_snapshot(
        Atomic_Option_Snapshot $snapshot
    ): bool {
        return $snapshot->is_present()
            && in_array((string) $snapshot->autoload(), self::NONAUTOLOAD_VALUES, true);
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

    /** @return array{state:string,generation:int} */
    private static function provision_state(string $state, int $generation): array
    {
        return array(
            'state'      => $state,
            'generation' => $generation,
        );
    }

    private static function snapshot_indeterminate(): Atomic_Option_Result
    {
        return Atomic_Option_Result::indeterminate(
            Atomic_Option_Result::MUTATION_NONE,
            Atomic_Option_Result::PHASE_VALIDATION
        );
    }
}

// EOF: includes/Managed_Backend_Secret_Store.php
