<?php
/**
 * File: includes/Atomic_Option_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use InvalidArgumentException;
use ReflectionReference;
use Throwable;

// This narrowly reviewed direct-query path is required because the Options API
// does not expose an exact old-byte predicate. Cache handling below preserves
// WordPress option-read coherence after a definite or possible mutation.
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
final class Atomic_Option_Store
{
    public const MAX_SERIALIZED_BYTES = 1048576;

    public const PROBE_BEFORE = 'before';
    public const PROBE_AFTER = 'after';
    public const PROBE_OTHER = 'other';
    public const PROBE_REFUSED = 'refused';
    public const PROBE_INDETERMINATE = 'indeterminate';

    private const AUTOLOAD_VALUES = array(
        'yes',
        'no',
        'on',
        'off',
        'auto',
        'auto-on',
        'auto-off',
    );

    private const NONAUTOLOAD_VALUES = array('no', 'off', 'auto-off');

    public function __construct(
        private string $option,
        private int $maximum_bytes = self::MAX_SERIALIZED_BYTES
    ) {
        if (
            '' === $option
            || strlen($option) > 191
            || 1 !== preg_match(
                '/^(?:argent_video_processor|argentwolf_video_processor)_[a-z0-9][a-z0-9_.-]*$/D',
                $option
            )
            || $maximum_bytes < 1
            || $maximum_bytes > self::MAX_SERIALIZED_BYTES
        ) {
            throw new InvalidArgumentException('Invalid fixed-scope atomic option configuration.');
        }
    }

    /**
     * Read the authoritative row without consulting option filters or caches.
     */
    public function snapshot(): Atomic_Option_Snapshot
    {
        global $wpdb;

        if (! is_object($wpdb) || ! isset($wpdb->options)) {
            return Atomic_Option_Snapshot::indeterminate($this->option);
        }

        try {
            $query = $wpdb->prepare(
                'SELECT CASE
                            WHEN OCTET_LENGTH(option_value) <= %d THEN option_value
                            ELSE NULL
                        END AS option_value,
                        autoload,
                        OCTET_LENGTH(option_value) AS byte_length
                 FROM %i
                 WHERE option_name = %s
                   AND BINARY option_name = BINARY %s
                 LIMIT 1',
                $this->maximum_bytes,
                $wpdb->options,
                $this->option,
                $this->option
            );
            $row = $wpdb->get_row($query, ARRAY_A);
        } catch (Throwable) {
            return Atomic_Option_Snapshot::indeterminate($this->option);
        }

        if (null === $row) {
            return '' !== (string) ($wpdb->last_error ?? '')
                ? Atomic_Option_Snapshot::indeterminate($this->option)
                : Atomic_Option_Snapshot::absent($this->option);
        }

        if (! is_array($row)) {
            return Atomic_Option_Snapshot::indeterminate($this->option);
        }

        $raw = $row['option_value'] ?? null;
        $autoload = $row['autoload'] ?? null;
        $byte_length = self::nonnegative_int($row['byte_length'] ?? null);

        if (
            ! is_string($raw)
            || ! is_string($autoload)
            || ! in_array($autoload, self::AUTOLOAD_VALUES, true)
            || $byte_length < 1
            || $byte_length > $this->maximum_bytes
            || strlen($raw) !== $byte_length
        ) {
            return Atomic_Option_Snapshot::refused($this->option);
        }

        $value = self::decode_canonical_array($raw);
        if (null === $value) {
            return Atomic_Option_Snapshot::refused($this->option);
        }

        return Atomic_Option_Snapshot::present(
            $this->option,
            $raw,
            $autoload,
            $value
        );
    }

    /**
     * Prepare, but do not execute, one exact compare-and-swap mutation.
     *
     * The returned plan is request-local, single-use authority. Only its
     * bounded non-secret evidence is suitable for durable journaling.
     *
     * @param array<string|int, mixed> $replacement
     */
    public function prepare_compare_exchange(
        Atomic_Option_Snapshot $expected,
        array $replacement,
        string $kind,
        string $mutation_id
    ): Atomic_Option_Plan_Result {
        if (
            $this->option !== $expected->option()
            || ! self::valid_mutation_identity($kind, $mutation_id)
        ) {
            return Atomic_Option_Plan_Result::refused();
        }

        if (Atomic_Option_Snapshot::INDETERMINATE === $expected->state()) {
            return Atomic_Option_Plan_Result::indeterminate();
        }

        if (! $expected->is_present() && ! $expected->is_absent()) {
            return Atomic_Option_Plan_Result::refused();
        }

        if (
            $expected->is_present()
            && (
                ! $this->valid_present_snapshot($expected)
                || ! in_array((string) $expected->autoload(), self::NONAUTOLOAD_VALUES, true)
            )
        ) {
            return Atomic_Option_Plan_Result::refused();
        }

        if (
            ('secret_reserve' === $kind && ! $expected->is_absent())
            || (in_array($kind, array('secret_commit', 'registry_activate'), true)
                && ! $expected->is_present())
        ) {
            return Atomic_Option_Plan_Result::conflict();
        }

        $raw = $this->encode_canonical_array($replacement);
        if (null === $raw) {
            return Atomic_Option_Plan_Result::refused();
        }

        // State-machine evidence deliberately describes value bytes, not an
        // autoload-only normalization. Keep the existing compare_exchange()
        // behavior available to legacy callers, but refuse such a plan.
        if ($expected->is_present() && $raw === $expected->raw()) {
            return Atomic_Option_Plan_Result::refused();
        }

        $written = Atomic_Option_Snapshot::present(
            $this->option,
            $raw,
            $this->canonical_nonautoload_value(),
            $replacement
        );
        $evidence = $this->mutation_evidence(
            $kind,
            $mutation_id,
            $expected,
            $written
        );
        if (null === $evidence) {
            return Atomic_Option_Plan_Result::refused();
        }

        return Atomic_Option_Plan_Result::ready(
            Atomic_Option_Mutation_Plan::create(
                $this->option,
                $kind,
                $mutation_id,
                $expected,
                $written,
                $evidence
            )
        );
    }

    /**
     * Execute one exact request-local plan at most once.
     */
    public function apply_plan(
        Atomic_Option_Mutation_Plan $plan
    ): Atomic_Option_Result {
        $plan_evidence = $plan->evidence();
        if (
            $this->option !== $plan->option()
            || $this->option !== $plan->before()->option()
            || $this->option !== $plan->written()->option()
            || ! $plan->written()->is_present()
            || ! $this->valid_present_snapshot($plan->written())
            || $this->canonical_nonautoload_value() !== $plan->written()->autoload()
            || ! $this->valid_mutation_evidence($plan_evidence)
        ) {
            return Atomic_Option_Result::refused();
        }

        $evidence = $this->mutation_evidence(
            $plan->kind(),
            $plan->mutation_id(),
            $plan->before(),
            $plan->written()
        );
        $replacement = $plan->written()->value();
        if (
            null === $evidence
            || $evidence !== $plan_evidence
            || ! is_array($replacement)
            || ! $plan->consume()
        ) {
            return Atomic_Option_Result::refused();
        }

        return $this->compare_exchange($plan->before(), $replacement);
    }

    /**
     * Classify the authoritative current row against durable plan evidence.
     *
     * @param array<string, mixed> $evidence
     */
    public function probe_evidence(array $evidence): string
    {
        if (! $this->valid_mutation_evidence($evidence)) {
            return self::PROBE_REFUSED;
        }

        $snapshot = $this->snapshot();
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return self::PROBE_INDETERMINATE;
        }

        if (Atomic_Option_Snapshot::REFUSED === $snapshot->state()) {
            return self::PROBE_REFUSED;
        }

        if ($snapshot->is_absent()) {
            return false === $evidence['before_exists']
                ? self::PROBE_BEFORE
                : self::PROBE_OTHER;
        }

        if (
            ! $snapshot->is_present()
            || ! in_array((string) $snapshot->autoload(), self::NONAUTOLOAD_VALUES, true)
        ) {
            return self::PROBE_REFUSED;
        }

        if ($this->snapshot_matches_evidence($snapshot, $evidence, 'before')) {
            return self::PROBE_BEFORE;
        }

        if ($this->snapshot_matches_evidence($snapshot, $evidence, 'after')) {
            return self::PROBE_AFTER;
        }

        return self::PROBE_OTHER;
    }

    /**
     * Replace a present exact row, or create only while the observed row is
     * still absent. The replacement is always explicitly non-autoloaded.
     *
     * WordPress pre-update filters are intentionally outside this primitive.
     * Its caller must supply the final, prospectively validated array.
     *
     * @param array<string|int, mixed> $replacement
     */
    public function compare_exchange(
        Atomic_Option_Snapshot $expected,
        array $replacement
    ): Atomic_Option_Result {
        if ($this->option !== $expected->option()) {
            return Atomic_Option_Result::refused();
        }

        if (Atomic_Option_Snapshot::INDETERMINATE === $expected->state()) {
            return Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_NONE,
                Atomic_Option_Result::PHASE_VALIDATION
            );
        }

        if (! $expected->is_present() && ! $expected->is_absent()) {
            return Atomic_Option_Result::refused();
        }

        if (
            $expected->is_present()
            && (
                ! $this->valid_present_snapshot($expected)
                || ! in_array((string) $expected->autoload(), self::NONAUTOLOAD_VALUES, true)
            )
        ) {
            // Autoload repair is a separate Options API operation followed by
            // a fresh snapshot. Refusing here guarantees that a later exact
            // rollback can never restore an autoload=true row.
            return Atomic_Option_Result::refused();
        }

        $raw = $this->encode_canonical_array($replacement);
        if (null === $raw) {
            return Atomic_Option_Result::refused();
        }

        $autoload = $this->canonical_nonautoload_value();
        if (
            $expected->is_present()
            && $raw === $expected->raw()
            && $autoload === $expected->autoload()
        ) {
            return Atomic_Option_Result::refused();
        }

        $written = Atomic_Option_Snapshot::present(
            $this->option,
            $raw,
            $autoload,
            $replacement
        );

        return $expected->is_absent()
            ? $this->create_if_absent($expected, $written)
            : $this->update_if_unchanged($expected, $written, true);
    }

    /**
     * Reverse only a proven compare_exchange() while its exact written bytes
     * are still current. Indeterminate writes are never rollback authority.
     */
    public function rollback(Atomic_Option_Result $write): Atomic_Option_Result
    {
        $before = $write->before();
        $written = $write->written();

        if (
            Atomic_Option_Result::APPLIED !== $write->status()
            || null === $before
            || null === $written
            || $this->option !== $before->option()
            || $this->option !== $written->option()
            || ! $written->is_present()
            || ! $this->valid_present_snapshot($written)
            || ! in_array((string) $written->autoload(), self::NONAUTOLOAD_VALUES, true)
        ) {
            return Atomic_Option_Result::refused();
        }

        if ($before->is_absent()) {
            return $this->perform_delete_if_unchanged($written);
        }

        if (
            ! $before->is_present()
            || ! $this->valid_present_snapshot($before)
            || ! in_array((string) $before->autoload(), self::NONAUTOLOAD_VALUES, true)
        ) {
            return Atomic_Option_Result::refused();
        }

        return $this->update_if_unchanged($written, $before, false);
    }

    /**
     * Delete a row only while the caller's exact authoritative observation is
     * still current. This supports reconciliation in a later request, where
     * the original compare_exchange() result object no longer exists.
     */
    public function compare_delete(
        Atomic_Option_Snapshot $expected
    ): Atomic_Option_Result {
        if ($this->option !== $expected->option()) {
            return Atomic_Option_Result::refused();
        }

        if (Atomic_Option_Snapshot::INDETERMINATE === $expected->state()) {
            return Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_NONE,
                Atomic_Option_Result::PHASE_VALIDATION
            );
        }

        if (
            ! $expected->is_present()
            || ! $this->valid_present_snapshot($expected)
            || ! in_array((string) $expected->autoload(), self::NONAUTOLOAD_VALUES, true)
        ) {
            return Atomic_Option_Result::refused();
        }

        return $this->perform_delete_if_unchanged($expected);
    }

    private function create_if_absent(
        Atomic_Option_Snapshot $before,
        Atomic_Option_Snapshot $written
    ): Atomic_Option_Result {
        global $wpdb;

        $value = $written->value();
        if (null === $value) {
            return Atomic_Option_Result::refused(Atomic_Option_Result::PHASE_PRE_ACTION);
        }

        $action_before = $this->snapshot();
        $action_guard = $this->classify_action_baseline($action_before);
        if (null !== $action_guard) {
            return $action_guard;
        }

        $pre_action = $this->pre_action('add', null, $value);
        if (false === $pre_action) {
            return Atomic_Option_Result::refused(Atomic_Option_Result::PHASE_PRE_ACTION);
        }
        if (null === $pre_action) {
            return $this->classify_pre_action_exception($action_before);
        }

        $post_action_guard = $this->classify_normal_pre_action($action_before);
        if (null !== $post_action_guard) {
            return $post_action_guard;
        }

        try {
            $query = $wpdb->prepare(
                'INSERT INTO %i (option_name, option_value, autoload)
                 SELECT %s, %s, %s FROM DUAL
                 WHERE NOT EXISTS (
                     SELECT 1 FROM %i
                     WHERE option_name = %s
                       AND BINARY option_name = BINARY %s
                 )',
                $wpdb->options,
                $this->option,
                (string) $written->raw(),
                (string) $written->autoload(),
                $wpdb->options,
                $this->option,
                $this->option
            );
            $affected = $wpdb->query($query);
        } catch (Throwable) {
            $affected = false;
        }

        return $this->classify_mutation(
            $affected,
            $before,
            $written,
            'add'
        );
    }

    private function update_if_unchanged(
        Atomic_Option_Snapshot $before,
        Atomic_Option_Snapshot $written,
        bool $rollback_authority
    ): Atomic_Option_Result {
        global $wpdb;

        $old_value = $before->value();
        $new_value = $written->value();
        $old_raw = $before->raw();

        if (null === $old_value || null === $new_value || null === $old_raw) {
            return Atomic_Option_Result::refused(Atomic_Option_Result::PHASE_PRE_ACTION);
        }

        $action_before = $this->snapshot();
        $action_guard = $this->classify_action_baseline($action_before);
        if (null !== $action_guard) {
            return $action_guard;
        }

        $pre_action = $this->pre_action('update', $old_value, $new_value);
        if (false === $pre_action) {
            return Atomic_Option_Result::refused(Atomic_Option_Result::PHASE_PRE_ACTION);
        }
        if (null === $pre_action) {
            return $this->classify_pre_action_exception($action_before);
        }

        $post_action_guard = $this->classify_normal_pre_action($action_before);
        if (null !== $post_action_guard) {
            return $post_action_guard;
        }

        try {
            $query = $wpdb->prepare(
                'UPDATE %i
                 SET option_value = %s, autoload = %s
                 WHERE option_name = %s
                   AND OCTET_LENGTH(option_value) = %d
                   AND BINARY option_value = BINARY %s
                   AND BINARY autoload = BINARY %s
                   AND BINARY option_name = BINARY %s',
                $wpdb->options,
                (string) $written->raw(),
                (string) $written->autoload(),
                $this->option,
                strlen($old_raw),
                $old_raw,
                (string) $before->autoload(),
                $this->option
            );
            $affected = $wpdb->query($query);
        } catch (Throwable) {
            $affected = false;
        }

        $result = $this->classify_mutation(
            $affected,
            $rollback_authority ? $before : null,
            $written,
            'update',
            $before
        );

        return $result;
    }

    private function perform_delete_if_unchanged(
        Atomic_Option_Snapshot $before
    ): Atomic_Option_Result {
        global $wpdb;

        $raw = $before->raw();
        if (null === $raw) {
            return Atomic_Option_Result::refused(Atomic_Option_Result::PHASE_PRE_ACTION);
        }

        $action_before = $this->snapshot();
        $action_guard = $this->classify_action_baseline($action_before);
        if (null !== $action_guard) {
            return $action_guard;
        }

        $pre_action = $this->pre_action('delete', null, null);
        if (false === $pre_action) {
            return Atomic_Option_Result::refused(Atomic_Option_Result::PHASE_PRE_ACTION);
        }
        if (null === $pre_action) {
            return $this->classify_pre_action_exception($action_before);
        }

        $post_action_guard = $this->classify_normal_pre_action($action_before);
        if (null !== $post_action_guard) {
            return $post_action_guard;
        }

        try {
            $query = $wpdb->prepare(
                'DELETE FROM %i
                 WHERE option_name = %s
                   AND OCTET_LENGTH(option_value) = %d
                   AND BINARY option_value = BINARY %s
                   AND BINARY autoload = BINARY %s
                   AND BINARY option_name = BINARY %s',
                $wpdb->options,
                $this->option,
                strlen($raw),
                $raw,
                (string) $before->autoload(),
                $this->option
            );
            $affected = $wpdb->query($query);
        } catch (Throwable) {
            $affected = false;
        }

        $absent = Atomic_Option_Snapshot::absent($this->option);
        return $this->classify_mutation($affected, null, $absent, 'delete');
    }

    private function classify_mutation(
        mixed $affected,
        ?Atomic_Option_Snapshot $rollback_before,
        Atomic_Option_Snapshot $written,
        string $operation,
        ?Atomic_Option_Snapshot $action_before = null
    ): Atomic_Option_Result {
        if (0 === $affected) {
            return $this->invalidate_caches()
                ? Atomic_Option_Result::conflict()
                : Atomic_Option_Result::indeterminate(
                    Atomic_Option_Result::MUTATION_NONE,
                    Atomic_Option_Result::PHASE_CACHE
                );
        }

        if (1 !== $affected) {
            $this->invalidate_caches();
            return Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_UNKNOWN,
                Atomic_Option_Result::PHASE_SQL
            );
        }

        $cache_ok = $this->invalidate_caches();
        $current_before_actions = $this->snapshot();
        $postcondition_ok = $this->same_snapshot($written, $current_before_actions);
        $post_action_ok = $this->post_action($operation, $action_before, $written);
        $final_cache_ok = $this->invalidate_caches();
        $current_after_actions = $this->snapshot();
        $postcondition_ok = $postcondition_ok
            && $this->same_snapshot($written, $current_after_actions);

        if (! $postcondition_ok) {
            return Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_APPLIED,
                Atomic_Option_Result::PHASE_POSTCONDITION
            );
        }

        if (! $cache_ok || ! $final_cache_ok) {
            return Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_APPLIED,
                Atomic_Option_Result::PHASE_CACHE
            );
        }

        if (! $post_action_ok) {
            return Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_APPLIED,
                Atomic_Option_Result::PHASE_POST_ACTION
            );
        }

        return Atomic_Option_Result::applied($rollback_before, $written);
    }

    /** @param array<string|int, mixed>|null $old_value
     *  @param array<string|int, mixed>|null $new_value
     */
    private function pre_action(string $operation, ?array $old_value, ?array $new_value): ?bool
    {
        if (! function_exists('do_action')) {
            return false;
        }

        try {
            if ('add' === $operation && null !== $new_value) {
                do_action('add_option', $this->option, $new_value);
            } elseif ('update' === $operation && null !== $old_value && null !== $new_value) {
                do_action('update_option', $this->option, $old_value, $new_value);
            } elseif ('delete' === $operation) {
                do_action('delete_option', $this->option);
            } else {
                return false;
            }
        } catch (Throwable) {
            return null;
        }

        return true;
    }

    /**
     * A throwing pre-action may itself have changed the target row. Re-read
     * after cache invalidation before claiming that no mutation occurred.
     */
    private function classify_pre_action_exception(
        Atomic_Option_Snapshot $expected
    ): Atomic_Option_Result {
        $cache_ok = $this->invalidate_caches();
        $current = $this->snapshot();

        if (! $this->same_snapshot($expected, $current)) {
            return Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_UNKNOWN,
                Atomic_Option_Result::PHASE_PRE_ACTION
            );
        }

        return $cache_ok
            ? Atomic_Option_Result::refused(Atomic_Option_Result::PHASE_PRE_ACTION)
            : Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_NONE,
                Atomic_Option_Result::PHASE_CACHE
            );
    }

    /**
     * Refuse to invoke an option action when its immediate authoritative
     * baseline cannot be classified. This baseline lets the normal-returning
     * path distinguish a hook-side target mutation from a later zero-row CAS.
     */
    private function classify_action_baseline(
        Atomic_Option_Snapshot $snapshot
    ): ?Atomic_Option_Result {
        if (Atomic_Option_Snapshot::INDETERMINATE === $snapshot->state()) {
            return Atomic_Option_Result::indeterminate(
                Atomic_Option_Result::MUTATION_NONE,
                Atomic_Option_Result::PHASE_PRE_ACTION
            );
        }

        if (! $snapshot->is_present() && ! $snapshot->is_absent()) {
            return Atomic_Option_Result::refused(Atomic_Option_Result::PHASE_PRE_ACTION);
        }

        return null;
    }

    /**
     * A normally returning pre-action is not proof that it was inert. Compare
     * the target with the immediately preceding authoritative baseline before
     * allowing SQL. Any difference is a possible hook-side partial mutation,
     * so it cannot become SQL-phase no-mutation/replan authority.
     */
    private function classify_normal_pre_action(
        Atomic_Option_Snapshot $before_action
    ): ?Atomic_Option_Result {
        $current = $this->snapshot();
        if ($this->same_snapshot($before_action, $current)) {
            return null;
        }

        $this->invalidate_caches();
        return Atomic_Option_Result::indeterminate(
            Atomic_Option_Result::MUTATION_UNKNOWN,
            Atomic_Option_Result::PHASE_PRE_ACTION
        );
    }

    private function post_action(
        string $operation,
        ?Atomic_Option_Snapshot $before,
        Atomic_Option_Snapshot $written
    ): bool {
        if (! function_exists('do_action')) {
            return false;
        }

        try {
            if ('add' === $operation) {
                $value = $written->value();
                if (null === $value) {
                    return false;
                }
                do_action('add_option_' . $this->option, $this->option, $value);
                do_action('added_option', $this->option, $value);
            } elseif ('update' === $operation && null !== $before) {
                $old_value = $before->value();
                $new_value = $written->value();
                if (null === $old_value || null === $new_value) {
                    return false;
                }
                do_action('update_option_' . $this->option, $old_value, $new_value, $this->option);
                do_action('updated_option', $this->option, $old_value, $new_value);
            } elseif ('delete' === $operation) {
                do_action('delete_option_' . $this->option, $this->option);
                do_action('deleted_option', $this->option);
            } else {
                return false;
            }
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function invalidate_caches(): bool
    {
        if (! function_exists('wp_cache_delete')) {
            return false;
        }

        $success = true;
        foreach (array($this->option, 'alloptions', 'notoptions') as $key) {
            try {
                // A false return can mean only that the cache entry was
                // already absent, so only an exception is a classified cache
                // failure here.
                wp_cache_delete($key, 'options');
            } catch (Throwable) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mutation_evidence(
        string $kind,
        string $mutation_id,
        Atomic_Option_Snapshot $before,
        Atomic_Option_Snapshot $written
    ): ?array {
        if (
            ! self::valid_mutation_identity($kind, $mutation_id)
            || $this->option !== $before->option()
            || $this->option !== $written->option()
            || (! $before->is_present() && ! $before->is_absent())
            || ! $written->is_present()
        ) {
            return null;
        }

        $before_raw = $before->raw();
        $after_raw = $written->raw();
        if (
            ($before->is_present() && ! is_string($before_raw))
            || ! is_string($after_raw)
        ) {
            return null;
        }

        $evidence = array(
            'kind'          => $kind,
            'mutation_id'   => $mutation_id,
            'before_exists' => $before->is_present(),
            'before_sha256' => $before->is_present() ? hash('sha256', $before_raw) : '',
            'before_bytes'  => $before->is_present() ? strlen($before_raw) : 0,
            'after_exists'  => true,
            'after_sha256'  => hash('sha256', $after_raw),
            'after_bytes'   => strlen($after_raw),
        );

        return $this->valid_mutation_evidence($evidence) ? $evidence : null;
    }

    /** @param array<string, mixed> $evidence */
    private function valid_mutation_evidence(array $evidence): bool
    {
        if (! self::has_exact_keys(
            $evidence,
            array(
                'kind',
                'mutation_id',
                'before_exists',
                'before_sha256',
                'before_bytes',
                'after_exists',
                'after_sha256',
                'after_bytes',
            )
        )) {
            return false;
        }

        $nodes = 0;
        if (! self::safe_array_value($evidence, 0, $nodes)) {
            return false;
        }

        $kind = $evidence['kind'];
        $mutation_id = $evidence['mutation_id'];
        $before_exists = $evidence['before_exists'];
        $before_sha256 = $evidence['before_sha256'];
        $before_bytes = $evidence['before_bytes'];
        $after_exists = $evidence['after_exists'];
        $after_sha256 = $evidence['after_sha256'];
        $after_bytes = $evidence['after_bytes'];

        if (
            ! is_string($kind)
            || ! is_string($mutation_id)
            || ! self::valid_mutation_identity($kind, $mutation_id)
            || ! is_bool($before_exists)
            || ! is_string($before_sha256)
            || ! is_int($before_bytes)
            || ! is_bool($after_exists)
            || true !== $after_exists
            || ! is_string($after_sha256)
            || ! is_int($after_bytes)
            || $before_bytes < 0
            || $before_bytes > $this->maximum_bytes
            || $after_bytes < 1
            || $after_bytes > $this->maximum_bytes
            || ! self::valid_evidence_fields($before_exists, $before_sha256, $before_bytes)
            || ! self::valid_evidence_fields(true, $after_sha256, $after_bytes)
        ) {
            return false;
        }

        if (
            ('secret_reserve' === $kind && $before_exists)
            || (in_array($kind, array('secret_commit', 'registry_activate'), true)
                && ! $before_exists)
            || ($before_exists
                && $before_sha256 === $after_sha256
                && $before_bytes === $after_bytes)
        ) {
            return false;
        }

        return true;
    }

    private static function valid_mutation_identity(string $kind, string $mutation_id): bool
    {
        return in_array(
            $kind,
            array(
                'secret_reserve',
                'secret_fence',
                'registry_link',
                'secret_commit',
                'registry_activate',
                'registry_retire',
            ),
            true
        ) && 1 === preg_match('/^mutation_[a-f0-9]{32}$/D', $mutation_id);
    }

    private static function valid_evidence_fields(bool $exists, string $sha256, int $bytes): bool
    {
        return $exists
            ? $bytes > 0 && 1 === preg_match('/^[a-f0-9]{64}$/D', $sha256)
            : '' === $sha256 && 0 === $bytes;
    }

    /** @param array<string, mixed> $evidence */
    private function snapshot_matches_evidence(
        Atomic_Option_Snapshot $snapshot,
        array $evidence,
        string $prefix
    ): bool {
        $raw = $snapshot->raw();
        if (
            ! is_string($raw)
            || true !== $evidence[$prefix . '_exists']
            || strlen($raw) !== $evidence[$prefix . '_bytes']
        ) {
            return false;
        }

        return hash_equals(
            $evidence[$prefix . '_sha256'],
            hash('sha256', $raw)
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

    private function same_snapshot(
        Atomic_Option_Snapshot $expected,
        Atomic_Option_Snapshot $current
    ): bool {
        if ($expected->is_absent()) {
            return $current->is_absent();
        }

        return $expected->is_present()
            && $current->is_present()
            && $expected->autoload() === $current->autoload()
            && is_string($expected->raw())
            && is_string($current->raw())
            && hash_equals($expected->raw(), $current->raw());
    }

    private function valid_present_snapshot(Atomic_Option_Snapshot $snapshot): bool
    {
        if (! $snapshot->is_present()) {
            return false;
        }

        $raw = $snapshot->raw();
        $value = $snapshot->value();
        if (! is_string($raw) || ! is_array($value)) {
            return false;
        }

        $canonical = $this->encode_canonical_array($value);
        return is_string($canonical) && hash_equals($canonical, $raw);
    }

    /** @param array<string|int, mixed> $value */
    private function encode_canonical_array(array $value): ?string
    {
        // Validate caller-owned values before serialize() can invoke an
        // object's magic serialization hooks or traverse a reference graph.
        $nodes = 0;
        if (! self::safe_array_value($value, 0, $nodes)) {
            return null;
        }

        try {
            $raw = serialize($value);
        } catch (Throwable) {
            return null;
        }

        if (strlen($raw) > $this->maximum_bytes) {
            return null;
        }

        $decoded = self::decode_canonical_array($raw);
        if (null === $decoded) {
            return null;
        }

        try {
            return $decoded === $value ? $raw : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string|int, mixed>|null */
    private static function decode_canonical_array(string $raw): ?array
    {
        if (! str_starts_with($raw, 'a:')) {
            return null;
        }

        try {
            $value = @unserialize(
                $raw,
                array(
                    'allowed_classes' => false,
                    'max_depth'       => 16,
                )
            );
        } catch (Throwable) {
            return null;
        }

        if (! is_array($value)) {
            return null;
        }

        $nodes = 0;
        if (! self::safe_array_value($value, 0, $nodes)) {
            return null;
        }

        try {
            return serialize($value) === $raw ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function canonical_nonautoload_value(): string
    {
        return function_exists('wp_autoload_values_to_autoload') ? 'off' : 'no';
    }

    private static function safe_array_value(mixed $value, int $depth, int &$nodes): bool
    {
        $nodes++;
        if ($depth > 16 || $nodes > 100000) {
            return false;
        }

        if (is_array($value)) {
            foreach (array_keys($value) as $key) {
                if (
                    null !== ReflectionReference::fromArrayElement($value, $key)
                    || ! self::safe_array_value($value[$key], $depth + 1, $nodes)
                ) {
                    return false;
                }
            }

            return true;
        }

        return is_string($value)
            || is_int($value)
            || is_float($value)
            || is_bool($value)
            || null === $value;
    }

    private static function nonnegative_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : -1;
        }

        if (! is_string($value) || 1 !== preg_match('/^[0-9]+$/D', $value)) {
            return -1;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0)));
        return false !== $integer ? (int) $integer : -1;
    }
}

// EOF: includes/Atomic_Option_Store.php
