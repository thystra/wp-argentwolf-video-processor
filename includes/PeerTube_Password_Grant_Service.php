<?php
/**
 * File: includes/PeerTube_Password_Grant_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use Throwable;

/**
 * Explicit password-grant bootstrap boundary.
 *
 * The authenticated administrator boundary exposes this service only through
 * one explicit form submission; it remains absent from AJAX, REST, CLI, cron,
 * activation, and upload hooks. A caller supplies ephemeral credentials for
 * one manually authorized attempt. The service journals an exact claim before
 * the credential-bearing POST and never retries an uncertain grant
 * automatically.
 */
final class PeerTube_Password_Grant_Service
{
    public const STATUS_ADVANCED = 'advanced';
    public const STATUS_READY_FOR_GRANT = 'ready_for_grant';
    public const STATUS_AWAITING_OTP = 'awaiting_otp';
    public const STATUS_AWAITING_CREDENTIALS = 'awaiting_credentials';
    public const STATUS_READY_FOR_VERIFICATION = 'ready_for_verification';
    public const STATUS_GRANT_INDETERMINATE = 'grant_indeterminate';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_INDETERMINATE = 'indeterminate';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_OUTSIDE_SCOPE = 'outside_scope';

    /** Longer than the reviewed 15-second PeerTube HTTP timeout. */
    private const STALE_ATTEMPT_SECONDS = 30;
    private const MAX_PRE_POST_MARK_AGE_SECONDS = 15;
    private const MAX_REQUEST_MARK_ATTEMPTS = 3;
    private const MAX_USERNAME_BYTES = PeerTube_Connection_Input::MAX_USERNAME_BYTES;
    private const MAX_SECRET_BYTES = PeerTube_Connection_Input::MAX_PASSWORD_BYTES;
    private const MIN_USABLE_TOKEN_LIFETIME_SECONDS = 60;
    private const MAX_TOKEN_LIFETIME_SECONDS = 315576000;

    private PeerTube_Connection_Operation_Store $operations;
    private Managed_Backend_Secret_Store $secrets;
    private Backend_Registry $registry;

    /** @var Closure(string):PeerTube_Password_Grant_Api */
    private Closure $api_factory;

    /** @var Closure(int):int */
    private Closure $clock;

    public function __construct(
        ?PeerTube_Connection_Operation_Store $operations = null,
        ?Managed_Backend_Secret_Store $secrets = null,
        ?Backend_Registry $registry = null,
        ?callable $api_factory = null,
        ?callable $clock = null
    ) {
        $this->operations = $operations ?? new PeerTube_Connection_Operation_Store();
        $this->secrets = $secrets ?? new Managed_Backend_Secret_Store();
        $this->registry = $registry ?? new Backend_Registry();
        $this->api_factory = null === $api_factory
            ? static fn (string $origin): PeerTube_Password_Grant_Api =>
                new PeerTube_Api_Client(new PeerTube_Http_Client($origin))
            : Closure::fromCallable($api_factory);
        $this->clock = null === $clock
            ? static fn (int $minimum): int => max($minimum, time())
            : Closure::fromCallable($clock);
    }

    /**
     * Claim and perform at most one password-token POST.
     *
     * Username, password, OTP, OAuth-client credentials, and returned tokens
     * never cross the bounded result or durable journal boundary.
     *
     * @return array{
     *   status:string,
     *   mutation:string,
     *   operation_id:string,
     *   backend_id:string,
     *   phase:string,
     *   record_revision:int,
     *   retry_after:int
     * }
     */
    public function submit(
        string $operation_id,
        string $username,
        string $password,
        string $otp,
        int $now
    ): array {
        $record = null;
        $oauth_client = null;
        $secret = null;
        $attempt_capability = null;
        $mutation = Atomic_Option_Result::MUTATION_NONE;
        $post_invoked = false;

        try {
            if (! self::valid_credentials($username, $password, $otp) || $now < 1) {
                return self::projection(self::STATUS_REFUSED, null, $operation_id);
            }

            $probed = $this->probe_operation($operation_id);
            if (self::STATUS_ADVANCED !== $probed['status']) {
                return $probed['projection'];
            }
            $record = $probed['record'];
            if (! $this->grant_eligible($record, $otp, $now)) {
                return self::projection(self::STATUS_REFUSED, $record);
            }

            $prerequisite = $this->prerequisite_probe($record);
            if (Atomic_Option_Store::PROBE_AFTER !== $prerequisite) {
                return self::from_probe($prerequisite, $record);
            }

            $api = ($this->api_factory)($record['origin']);
            if (
                ! $api instanceof PeerTube_Password_Grant_Api
                || $record['origin'] !== $api->origin()
            ) {
                return self::projection(self::STATUS_REFUSED, $record);
            }

            // This GET is read-only and deliberately precedes the durable
            // grant claim so its failure cannot consume or strand an attempt.
            $oauth_result = $api->local_oauth_client();
            $oauth_client = self::oauth_client($oauth_result);
            unset($oauth_result);
            if (null === $oauth_client) {
                return self::projection(self::STATUS_INDETERMINATE, $record);
            }

            // The caller's timestamp is only an input floor. Refresh it after
            // the read-only OAuth request so the grant claim is never backdated
            // by that request's latency.
            $claim_now = $this->observed_time(max($now, $record['updated_at']));

            // WordPress HTTP hooks may have changed local authority while the
            // read-only request ran. Re-prove the exact record and targets.
            $fresh = $this->probe_operation($operation_id);
            if (self::STATUS_ADVANCED !== $fresh['status']) {
                return $fresh['projection'];
            }
            if ($record !== $fresh['record']) {
                return self::projection(self::STATUS_CONFLICT, $fresh['record']);
            }
            $record = $fresh['record'];
            if (
                ! $this->grant_eligible($record, $otp, $claim_now)
                || Atomic_Option_Store::PROBE_AFTER !== $this->prerequisite_probe($record)
            ) {
                return self::projection(self::STATUS_CONFLICT, $record);
            }

            $attempt_capability = self::random_capability();
            if ('' === $attempt_capability) {
                return self::projection(self::STATUS_REFUSED, $record);
            }

            $claim = $this->persist_event(
                $record,
                PeerTube_Connection_State_Machine::EVENT_BEGIN_GRANT,
                array('attempt_capability' => $attempt_capability),
                $claim_now
            );
            if (! $claim['confirmed']) {
                return $claim['projection'];
            }
            $record = $claim['record'];
            $mutation = self::merge_mutation(
                $mutation,
                $claim['projection']['mutation']
            );

            // Journal hooks are another mutation seam. No token POST is
            // permitted unless both local targets remain the exact authority.
            if (Atomic_Option_Store::PROBE_AFTER !== $this->prerequisite_probe($record)) {
                unset($oauth_client, $username, $password, $otp);
                return $this->grant_not_sent(
                    $record,
                    $this->observed_time($record['updated_at']),
                    $mutation,
                    $attempt_capability
                );
            }

            // Narrow the claim-to-POST race after the target probes. A caller
            // that no longer owns the exact journal claim must not send the
            // credentials it still holds request-locally.
            $pre_post = $this->probe_operation($operation_id);
            if (
                self::STATUS_ADVANCED !== $pre_post['status']
                || $record !== $pre_post['record']
            ) {
                unset($oauth_client, $username, $password, $otp);
                return self::projection(
                    self::STATUS_ADVANCED === $pre_post['status']
                        ? self::STATUS_CONFLICT
                        : self::STATUS_INDETERMINATE,
                    [] !== $pre_post['record'] ? $pre_post['record'] : $record,
                    '',
                    $mutation
                );
            }

            // Move the durable stale-attempt boundary to the actual request
            // edge. A mark whose hooks/local reproof consume too much of the
            // 30-second stale window is refreshed before any credential POST.
            // Bounded exhaustion records a definite no-send outcome.
            $request_now = 0;
            $request_ready = false;
            for ($mark_attempt = 0; $mark_attempt < self::MAX_REQUEST_MARK_ATTEMPTS; $mark_attempt++) {
                $mark_now = $this->observed_time($record['updated_at']);
                $request_mark = $this->persist_event(
                    $record,
                    PeerTube_Connection_State_Machine::EVENT_MARK_GRANT_REQUEST,
                    array('attempt_capability' => $attempt_capability),
                    $mark_now,
                    $mutation
                );
                if (! $request_mark['confirmed']) {
                    unset($oauth_client, $username, $password, $otp);
                    return $request_mark['projection'];
                }
                $record = $request_mark['record'];
                $mutation = self::merge_mutation(
                    $mutation,
                    $request_mark['projection']['mutation']
                );

                // The mark's WordPress option hooks are a mutation seam of
                // their own. Re-prove both targets after every mark.
                if (Atomic_Option_Store::PROBE_AFTER !== $this->prerequisite_probe($record)) {
                    unset($oauth_client, $username, $password, $otp);
                    return $this->grant_not_sent(
                        $record,
                        $this->observed_time($record['updated_at']),
                        $mutation,
                        $attempt_capability
                    );
                }

                $marked = $this->probe_operation($operation_id);
                if (
                    self::STATUS_ADVANCED !== $marked['status']
                    || $record !== $marked['record']
                ) {
                    unset($oauth_client, $username, $password, $otp);
                    return self::projection(
                        self::STATUS_ADVANCED === $marked['status']
                            ? self::STATUS_CONFLICT
                            : self::STATUS_INDETERMINATE,
                        [] !== $marked['record'] ? $marked['record'] : $record,
                        '',
                        $mutation
                    );
                }

                // Sample freshness only after the final journal SELECT. No
                // further local database work occurs before API invocation.
                $edge_now = $this->observed_time($record['updated_at']);
                if (self::fresh_request_mark($record['grant_started_at'], $edge_now)) {
                    $request_now = $edge_now;
                    $request_ready = true;
                    break;
                }
            }

            if (! $request_ready) {
                unset($oauth_client, $username, $password, $otp);
                return $this->grant_not_sent(
                    $record,
                    $this->observed_time($record['updated_at']),
                    $mutation,
                    $attempt_capability,
                    'request_window_expired'
                );
            }

            // Once invoked, any unreviewed or uncertain outcome must never be
            // retried implicitly.
            $post_invoked = true;
            try {
                $token_result = $api->password_token(
                    $oauth_client,
                    $username,
                    $password,
                    $otp,
                    $request_now
                );
            } catch (Throwable) {
                unset($oauth_client, $username, $password, $otp, $attempt_capability);
                return $this->terminalize_post_invocation(
                    $operation_id,
                    $record,
                    'transport_error',
                    0,
                    $this->observed_time($request_now),
                    $mutation
                );
            }
            unset($oauth_client, $username, $password, $otp);
            $response_now = $this->observed_time($request_now);

            if (! self::api_success($token_result)) {
                $mapped = self::grant_error($token_result);
                unset($token_result);
                if (
                    PeerTube_Connection_State_Machine::EVENT_GRANT_INDETERMINATE
                    === $mapped['event']
                ) {
                    return $this->terminalize_post_invocation(
                        $operation_id,
                        $record,
                        $mapped['payload']['reason'],
                        $mapped['payload']['http_status'],
                        $response_now,
                        $mutation
                    );
                }

                $definite = $this->persist_mapped_error(
                    $record,
                    $mapped,
                    $response_now,
                    $mutation,
                    $attempt_capability
                );
                unset($attempt_capability);
                if ($definite['confirmed']) {
                    return $definite['projection'];
                }

                return $this->terminalize_post_invocation(
                    $operation_id,
                    $record,
                    'local_persistence_unknown',
                    0,
                    $response_now,
                    $definite['projection']['mutation']
                );
            }

            $secret = self::token_secret($token_result, $response_now);
            unset($token_result);
            unset($attempt_capability);
            if (null === $secret) {
                return $this->terminalize_post_invocation(
                    $operation_id,
                    $record,
                    'invalid_response',
                    200,
                    $response_now,
                    $mutation
                );
            }

            // The POST may itself have run WordPress HTTP hooks. Never write
            // tokens unless the exact claim and both local prerequisites still
            // agree with the pre-request authority.
            $after_post = $this->probe_operation($operation_id);
            if (
                self::STATUS_ADVANCED !== $after_post['status']
                || $record !== $after_post['record']
                || Atomic_Option_Store::PROBE_AFTER !== $this->prerequisite_probe($record)
            ) {
                unset($secret);
                return $this->terminalize_post_invocation(
                    $operation_id,
                    $record,
                    'local_persistence_unknown',
                    0,
                    $response_now,
                    $mutation
                );
            }

            $mutation_id = self::random_id('mutation_');
            if ('' === $mutation_id) {
                unset($secret);
                return $this->terminalize_post_invocation(
                    $operation_id,
                    $record,
                    'local_persistence_unknown',
                    0,
                    $response_now,
                    $mutation
                );
            }

            $prepared = $this->secrets->prepare_commit_reserved(
                $record['secret_ref'],
                $record['backend_id'],
                $record['provisioning_id'],
                $secret,
                $mutation_id
            );
            $plan = $prepared->plan();
            if (Atomic_Option_Plan_Result::READY !== $prepared->status() || null === $plan) {
                unset($secret);
                return $this->terminalize_post_invocation(
                    $operation_id,
                    $record,
                    'local_persistence_unknown',
                    0,
                    $response_now,
                    $mutation
                );
            }

            $plan_now = $this->observed_time($response_now);

            $planned = $this->persist_event(
                $record,
                PeerTube_Connection_State_Machine::EVENT_PLAN_SECRET_STORAGE,
                $plan->evidence(),
                $plan_now,
                $mutation
            );
            if (! $planned['confirmed']) {
                unset($secret, $plan);
                return $this->terminalize_post_invocation(
                    $operation_id,
                    $record,
                    'local_persistence_unknown',
                    0,
                    $plan_now,
                    $planned['projection']['mutation']
                );
            }
            $record = $planned['record'];
            $mutation = self::merge_mutation(
                $mutation,
                $planned['projection']['mutation']
            );

            // A stale reconciler fences the pending target before it may make
            // this journal terminal. Re-probe here as an early refusal; the
            // target CAS below remains the final authority if both race.
            $before_apply = $this->probe_operation($operation_id);
            if (
                self::STATUS_ADVANCED !== $before_apply['status']
                || $record !== $before_apply['record']
            ) {
                unset($secret, $plan);
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    [] !== $before_apply['record'] ? $before_apply['record'] : $record,
                    '',
                    $mutation
                );
            }

            $applied = $this->secrets->apply_commit_plan(
                $record['secret_ref'],
                $record['backend_id'],
                $record['provisioning_id'],
                $secret,
                $plan
            );
            $commit_probe = $this->secrets->probe_commit(
                $record['secret_ref'],
                $record['backend_id'],
                $record['provisioning_id'],
                $record['last_mutation']
            );
            unset($secret, $plan);

            if (Atomic_Option_Store::PROBE_AFTER === $commit_probe) {
                $journal = $this->probe_operation($operation_id);
                if (self::STATUS_ADVANCED !== $journal['status'] || $record !== $journal['record']) {
                    return self::projection(
                        self::STATUS_INDETERMINATE,
                        $journal['record'] ?? $record,
                        '',
                        self::merge_mutation($mutation, $applied->mutation())
                    );
                }

                return self::projection(
                    Atomic_Option_Result::INDETERMINATE === $applied->status()
                        ? self::STATUS_INDETERMINATE
                        : self::STATUS_ADVANCED,
                    $record,
                    '',
                    self::merge_mutation($mutation, $applied->mutation())
                );
            }

            if (Atomic_Option_Store::PROBE_INDETERMINATE === $commit_probe) {
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    $record,
                    '',
                    self::merge_mutation($mutation, $applied->mutation())
                );
            }

            return $this->resolve_secret_write(
                $record,
                $this->observed_time($plan_now),
                true,
                self::merge_mutation($mutation, $applied->mutation())
            );
        } catch (Throwable) {
            unset($oauth_client, $secret, $username, $password, $otp, $attempt_capability);
            if ($post_invoked && is_array($record)) {
                return $this->terminalize_post_invocation(
                    $operation_id,
                    $record,
                    'local_persistence_unknown',
                    0,
                    $this->observed_time(max($now, (int) ($record['updated_at'] ?? $now))),
                    $mutation
                );
            }

            return self::projection(
                self::STATUS_INDETERMINATE,
                $record,
                $operation_id,
                $mutation
            );
        } finally {
            unset($attempt_capability);
        }
    }

    /**
     * Reconcile one post-claim state without credentials or outbound HTTP.
     *
     * @return array{
     *   status:string,
     *   mutation:string,
     *   operation_id:string,
     *   backend_id:string,
     *   phase:string,
     *   record_revision:int,
     *   retry_after:int
     * }
     */
    public function reconcile(string $operation_id, int $now): array
    {
        try {
            if ($now < 1) {
                return self::projection(self::STATUS_REFUSED, null, $operation_id);
            }

            $probed = $this->probe_operation($operation_id);
            if (self::STATUS_ADVANCED !== $probed['status']) {
                return $probed['projection'];
            }
            $record = $probed['record'];
            if ($now < $record['updated_at']) {
                return self::projection(self::STATUS_REFUSED, $record);
            }

            return match ($record['phase']) {
                PeerTube_Connection_State_Machine::PHASE_DISABLED =>
                    $this->ready_for_grant($record),
                PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP =>
                    self::projection(self::STATUS_AWAITING_OTP, $record),
                PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS =>
                    self::projection(self::STATUS_AWAITING_CREDENTIALS, $record),
                PeerTube_Connection_State_Machine::PHASE_GRANT_IN_FLIGHT =>
                    $this->reconcile_in_flight($record, $now),
                PeerTube_Connection_State_Machine::PHASE_OTP_RESULT_PENDING,
                PeerTube_Connection_State_Machine::PHASE_CREDENTIAL_RESULT_PENDING =>
                    $this->reconcile_pending_result($record, $now),
                PeerTube_Connection_State_Machine::PHASE_SECRET_WRITE_PLANNED =>
                    $this->reconcile_secret_write($record, $now),
                PeerTube_Connection_State_Machine::PHASE_SECRET_STORED =>
                    $this->ready_for_verification($record),
                PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE =>
                    $this->reconcile_grant_indeterminate($record, $now),
                default => self::projection(self::STATUS_OUTSIDE_SCOPE, $record),
            };
        } catch (Throwable) {
            return self::projection(
                self::STATUS_INDETERMINATE,
                null,
                $operation_id,
                Atomic_Option_Result::MUTATION_UNKNOWN
            );
        }
    }

    /** @param array<string, mixed> $record */
    private function reconcile_in_flight(array $record, int $now): array
    {
        if (! self::stale($record['updated_at'], $now)) {
            return self::projection(self::STATUS_INDETERMINATE, $record);
        }

        return $this->grant_indeterminate($record, 'process_interrupted', 0, $now);
    }

    /** @param array<string, mixed> $record */
    private function reconcile_pending_result(array $record, int $now): array
    {
        if (! self::stale($record['updated_at'], $now)) {
            return self::projection(self::STATUS_INDETERMINATE, $record);
        }

        return $this->grant_indeterminate(
            $record,
            'local_persistence_unknown',
            0,
            $now
        );
    }

    /** @param array<string, mixed> $record */
    private function reconcile_grant_indeterminate(array $record, int $now): array
    {
        if ('secret_commit' !== $record['last_mutation']['kind']) {
            return self::projection(self::STATUS_GRANT_INDETERMINATE, $record);
        }

        return $this->resolve_terminal_secret_target(
            $record,
            $now,
            true,
            Atomic_Option_Result::MUTATION_NONE
        );
    }

    /** @param array<string, mixed> $record */
    private function reconcile_secret_write(array $record, int $now): array
    {
        if (! self::stale($record['updated_at'], $now)) {
            return $this->resolve_secret_write(
                $record,
                $now,
                false,
                Atomic_Option_Result::MUTATION_NONE
            );
        }

        return $this->resolve_secret_write(
            $record,
            $now,
            true,
            Atomic_Option_Result::MUTATION_NONE
        );
    }

    /**
     * Confirm a ready commit or fence an empty stale target before terminalizing.
     *
     * The exact non-secret fenced marker is the durable target-side authority.
     * Unlike deletion, it cannot be followed by an older absent-to-pending plan
     * that recreates the bytes expected by a stalled pending-to-ready plan. If a
     * ready write wins, the fence conflicts and fresh probes follow that branch.
     *
     * @param array<string, mixed> $record
     */
    private function resolve_secret_write(
        array $record,
        int $now,
        bool $may_fence,
        string $prior_mutation
    ): array {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $commit_probe = $this->secrets->probe_commit(
                $record['secret_ref'],
                $record['backend_id'],
                $record['provisioning_id'],
                $record['last_mutation']
            );
            $registry_probe = $this->registry->probe_disabled_peertube_state(
                self::descriptor($record)
            );
            $state = $this->secrets->provisioning_state(
                $record['secret_ref'],
                $record['backend_id'],
                $record['provisioning_id']
            );

            if (
                Atomic_Option_Store::PROBE_AFTER === $commit_probe
                && Atomic_Option_Store::PROBE_AFTER === $registry_probe
            ) {
                return $this->confirm_secret_stored(
                    $record,
                    $now,
                    $prior_mutation
                );
            }

            if (
                Atomic_Option_Store::PROBE_INDETERMINATE === $commit_probe
                || Atomic_Option_Store::PROBE_INDETERMINATE === $registry_probe
                || Managed_Backend_Secret_Store::PROVISION_INDETERMINATE === $state['state']
            ) {
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    $record,
                    '',
                    $prior_mutation
                );
            }

            if (Managed_Backend_Secret_Store::PROVISION_FENCED === $state['state']) {
                $terminal = $this->grant_indeterminate(
                    $record,
                    'local_persistence_unknown',
                    0,
                    $this->observed_time(max($now, $record['updated_at'])),
                    $prior_mutation
                );
                if (
                    self::STATUS_GRANT_INDETERMINATE === $terminal['status']
                    && PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE
                        === $terminal['phase']
                ) {
                    $terminal_probe = $this->probe_operation($record['operation_id']);
                    if (
                        self::STATUS_ADVANCED === $terminal_probe['status']
                        && PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE
                            === ($terminal_probe['record']['phase'] ?? null)
                        && $record['grant_attempt_id']
                            === ($terminal_probe['record']['grant_attempt_id'] ?? null)
                    ) {
                        return $this->resolve_terminal_secret_target(
                            $terminal_probe['record'],
                            $now,
                            true,
                            $terminal['mutation']
                        );
                    }
                }

                return $terminal;
            }

            if (! $may_fence) {
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    $record,
                    '',
                    $prior_mutation
                );
            }

            if (
                Managed_Backend_Secret_Store::PROVISION_ABSENT !== $state['state']
                && ! (
                    Managed_Backend_Secret_Store::PROVISION_PENDING === $state['state']
                    && 0 === $state['generation']
                )
            ) {
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    $record,
                    '',
                    $prior_mutation
                );
            }

            $mutation_id = self::random_id('mutation_');
            if ('' === $mutation_id) {
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    $record,
                    '',
                    $prior_mutation
                );
            }

            $prepared = $this->secrets->prepare_fence_reserved(
                $record['secret_ref'],
                $record['backend_id'],
                $record['provisioning_id'],
                $mutation_id
            );
            $plan = $prepared->plan();
            if (Atomic_Option_Plan_Result::READY === $prepared->status() && null !== $plan) {
                $fenced = $this->secrets->apply_fence_plan(
                    $record['secret_ref'],
                    $record['backend_id'],
                    $record['provisioning_id'],
                    $plan
                );
                $prior_mutation = self::merge_mutation(
                    $prior_mutation,
                    $fenced->mutation()
                );
            }

            // A ready commit, a stale reservation, and the fence all race on
            // exact snapshots. Never infer the winner from a plan/apply result;
            // the next iteration freshly proves the durable authority.
            unset($prepared, $plan);
        }

        return self::projection(
            self::STATUS_INDETERMINATE,
            $record,
            '',
            $prior_mutation
        );
    }

    /**
     * Re-prove or repair the target paired with a terminal secret-write journal.
     *
     * @param array<string, mixed> $record
     */
    private function resolve_terminal_secret_target(
        array $record,
        int $now,
        bool $may_fence,
        string $prior_mutation
    ): array {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $commit_probe = $this->secrets->probe_commit(
                $record['secret_ref'],
                $record['backend_id'],
                $record['provisioning_id'],
                $record['last_mutation']
            );
            $registry_probe = $this->registry->probe_disabled_peertube_state(
                self::descriptor($record)
            );
            $state = $this->secrets->provisioning_state(
                $record['secret_ref'],
                $record['backend_id'],
                $record['provisioning_id']
            );

            if (
                Atomic_Option_Store::PROBE_AFTER === $commit_probe
                && Atomic_Option_Store::PROBE_AFTER === $registry_probe
            ) {
                return $this->confirm_secret_stored($record, $now, $prior_mutation);
            }

            if (
                Atomic_Option_Store::PROBE_INDETERMINATE === $commit_probe
                || Atomic_Option_Store::PROBE_INDETERMINATE === $registry_probe
                || Managed_Backend_Secret_Store::PROVISION_INDETERMINATE === $state['state']
            ) {
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    $record,
                    '',
                    $prior_mutation
                );
            }

            if (Managed_Backend_Secret_Store::PROVISION_FENCED === $state['state']) {
                return self::projection(
                    self::STATUS_GRANT_INDETERMINATE,
                    $record,
                    '',
                    $prior_mutation
                );
            }

            if (
                ! $may_fence
                || (
                    Managed_Backend_Secret_Store::PROVISION_ABSENT !== $state['state']
                    && ! (
                        Managed_Backend_Secret_Store::PROVISION_PENDING === $state['state']
                        && 0 === $state['generation']
                    )
                )
            ) {
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    $record,
                    '',
                    $prior_mutation
                );
            }

            $mutation_id = self::random_id('mutation_');
            if ('' === $mutation_id) {
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    $record,
                    '',
                    $prior_mutation
                );
            }

            $prepared = $this->secrets->prepare_fence_reserved(
                $record['secret_ref'],
                $record['backend_id'],
                $record['provisioning_id'],
                $mutation_id
            );
            $plan = $prepared->plan();
            if (Atomic_Option_Plan_Result::READY === $prepared->status() && null !== $plan) {
                $fenced = $this->secrets->apply_fence_plan(
                    $record['secret_ref'],
                    $record['backend_id'],
                    $record['provisioning_id'],
                    $plan
                );
                $prior_mutation = self::merge_mutation(
                    $prior_mutation,
                    $fenced->mutation()
                );
            }

            unset($prepared, $plan);
        }

        return self::projection(
            self::STATUS_INDETERMINATE,
            $record,
            '',
            $prior_mutation
        );
    }

    /** @param array<string, mixed> $record */
    private function confirm_secret_stored(
        array $record,
        int $now,
        string $prior_mutation
    ): array {
        $confirmed = $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_CONFIRM_SECRET_STORED,
            array(),
            $this->observed_time(max($now, $record['updated_at'])),
            $prior_mutation
        );

        return $confirmed['confirmed']
            ? $this->ready_for_verification(
                $confirmed['record'],
                $confirmed['projection']['mutation']
            )
            : $confirmed['projection'];
    }

    /** @param array<string, mixed> $record */
    private function ready_for_grant(array $record): array
    {
        $probe = $this->prerequisite_probe($record);
        return Atomic_Option_Store::PROBE_AFTER === $probe
            ? self::projection(self::STATUS_READY_FOR_GRANT, $record)
            : self::from_probe($probe, $record);
    }

    /** @param array<string, mixed> $record */
    private function ready_for_verification(
        array $record,
        string $prior_mutation = Atomic_Option_Result::MUTATION_NONE
    ): array
    {
        $commit_probe = $this->secrets->probe_commit(
            $record['secret_ref'],
            $record['backend_id'],
            $record['provisioning_id'],
            $record['last_mutation']
        );
        $state = $this->secrets->provisioning_state(
            $record['secret_ref'],
            $record['backend_id'],
            $record['provisioning_id']
        );
        $registry_probe = $this->registry->probe_disabled_peertube_state(self::descriptor($record));

        if (
            Atomic_Option_Store::PROBE_AFTER === $commit_probe
            && Managed_Backend_Secret_Store::PROVISION_READY === $state['state']
            && 1 === $state['generation']
            && Atomic_Option_Store::PROBE_AFTER === $registry_probe
        ) {
            return self::projection(
                self::STATUS_READY_FOR_VERIFICATION,
                $record,
                '',
                $prior_mutation
            );
        }

        if (
            Atomic_Option_Store::PROBE_INDETERMINATE === $commit_probe
            || Managed_Backend_Secret_Store::PROVISION_INDETERMINATE === $state['state']
            || Atomic_Option_Store::PROBE_INDETERMINATE === $registry_probe
        ) {
            return self::projection(self::STATUS_INDETERMINATE, $record, '', $prior_mutation);
        }

        return self::projection(self::STATUS_CONFLICT, $record, '', $prior_mutation);
    }

    /** @param array<string, mixed> $record */
    private function grant_not_sent(
        array $record,
        int $now,
        string $prior_mutation,
        string $attempt_capability,
        string $reason = 'local_prerequisite_changed'
    ): array
    {
        $transition = $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_GRANT_NOT_SENT,
            array(
                'reason'             => $reason,
                'http_status'        => 0,
                'retry_after'        => 0,
                'attempt_capability' => $attempt_capability,
            ),
            $now,
            $prior_mutation
        );

        return $transition['confirmed']
            ? self::projection(
                self::STATUS_AWAITING_CREDENTIALS,
                $transition['record'],
                '',
                $transition['projection']['mutation']
            )
            : $transition['projection'];
    }

    /** @param array<string, mixed> $record */
    private function grant_indeterminate(
        array $record,
        string $reason,
        int $http_status,
        int $now,
        string $prior_mutation = Atomic_Option_Result::MUTATION_NONE
    ): array {
        $transition = $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_GRANT_INDETERMINATE,
            array('reason' => $reason, 'http_status' => $http_status),
            $now,
            $prior_mutation
        );

        return $transition['confirmed']
            ? self::projection(
                self::STATUS_GRANT_INDETERMINATE,
                $transition['record'],
                '',
                $transition['projection']['mutation']
            )
            : $transition['projection'];
    }

    /**
     * @param array{event:string,payload:array<string,mixed>} $mapped
     * @param array<string, mixed> $record
     * @return array{confirmed:bool,record:array<string,mixed>,projection:array<string,mixed>}
     */
    private function persist_mapped_error(
        array $record,
        array $mapped,
        int $now,
        string $prior_mutation,
        string $attempt_capability
    ): array
    {
        if (PeerTube_Connection_State_Machine::EVENT_GRANT_INDETERMINATE === $mapped['event']) {
            return self::transition_result(
                false,
                $record,
                self::projection(self::STATUS_REFUSED, $record, '', $prior_mutation)
            );
        }

        $pending = $this->persist_event(
            $record,
            $mapped['event'],
            $mapped['payload'],
            $now,
            $prior_mutation
        );
        if (! $pending['confirmed']) {
            return $pending;
        }

        $confirmed = $this->persist_event(
            $pending['record'],
            PeerTube_Connection_State_Machine::EVENT_CONFIRM_GRANT_RESULT,
            array('attempt_capability' => $attempt_capability),
            $this->observed_time(max($now, $pending['record']['updated_at'])),
            $pending['projection']['mutation']
        );
        if (! $confirmed['confirmed']) {
            return $confirmed;
        }

        return self::transition_result(
            true,
            $confirmed['record'],
            self::projection(
                PeerTube_Connection_State_Machine::EVENT_OTP_REQUIRED === $mapped['event']
                    ? self::STATUS_AWAITING_OTP
                    : self::STATUS_AWAITING_CREDENTIALS,
                $confirmed['record'],
                '',
                $confirmed['projection']['mutation']
            )
        );
    }

    /**
     * Freshly classify any outcome after the credential-bearing POST began.
     *
     * A stale pre-request record is never sufficient terminalization authority.
     * The current record is re-probed, its timestamp is used as a floor, and a
     * bounded CAS retry closes ordinary hook/process races. A planned secret
     * write is already non-retryable and is left for target-fenced reconcile.
     *
     * @param array<string, mixed> $claimed
     */
    private function terminalize_post_invocation(
        string $operation_id,
        array $claimed,
        string $reason,
        int $http_status,
        int $now,
        string $prior_mutation
    ): array {
        $current = $claimed;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $probed = $this->probe_operation($operation_id);
            if (self::STATUS_ADVANCED !== $probed['status']) {
                continue;
            }
            $current = $probed['record'];

            // A post outcome belongs only to the exact committed attempt. A
            // newer attempt may already have begun after a capability-proved
            // retry result; never terminalize that independent authority.
            if ($current['grant_attempt_id'] !== $claimed['grant_attempt_id']) {
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    $current,
                    '',
                    $prior_mutation
                );
            }

            // Entry into either retryable phase requires the request-local
            // attempt capability. Once observed, it is durable proof that the
            // owning request classified a definite retry-safe outcome; an
            // unobservable confirmation write does not make the remote result
            // uncertain.
            if (
                PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP
                === $current['phase']
                || PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS
                === $current['phase']
            ) {
                return self::projection(
                    PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP
                        === $current['phase']
                        ? self::STATUS_AWAITING_OTP
                        : self::STATUS_AWAITING_CREDENTIALS,
                    $current,
                    '',
                    $prior_mutation
                );
            }

            if (
                PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE
                === $current['phase']
            ) {
                return self::projection(
                    self::STATUS_GRANT_INDETERMINATE,
                    $current,
                    '',
                    $prior_mutation
                );
            }

            if (
                PeerTube_Connection_State_Machine::PHASE_SECRET_WRITE_PLANNED
                === $current['phase']
            ) {
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    $current,
                    '',
                    $prior_mutation
                );
            }

            if (! in_array(
                $current['phase'],
                array(
                    PeerTube_Connection_State_Machine::PHASE_GRANT_IN_FLIGHT,
                    PeerTube_Connection_State_Machine::PHASE_OTP_RESULT_PENDING,
                    PeerTube_Connection_State_Machine::PHASE_CREDENTIAL_RESULT_PENDING,
                ),
                true
            )) {
                return self::projection(
                    self::STATUS_INDETERMINATE,
                    $current,
                    '',
                    $prior_mutation
                );
            }

            $exact_claim = $claimed === $current;
            $transition = $this->grant_indeterminate(
                $current,
                $exact_claim ? $reason : 'local_persistence_unknown',
                $exact_claim ? $http_status : 0,
                $this->observed_time(max($now, $current['updated_at'])),
                $prior_mutation
            );
            $prior_mutation = self::merge_mutation(
                $prior_mutation,
                $transition['mutation']
            );
            if (
                self::STATUS_GRANT_INDETERMINATE === $transition['status']
                && PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE
                    === $transition['phase']
            ) {
                return $transition;
            }
        }

        return self::projection(
            self::STATUS_INDETERMINATE,
            $current,
            '',
            $prior_mutation
        );
    }

    /**
     * Persist and authoritatively classify one exact pure state transition.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $payload
     * @return array{confirmed:bool,record:array<string,mixed>,projection:array<string,mixed>}
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
            return self::transition_result(
                false,
                $record,
                self::projection(self::STATUS_REFUSED, $record, '', $prior_mutation)
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
            $current = is_array($probe['record']) ? $probe['record'] : $record;
            if (
                $next === $current
                && Atomic_Option_Result::APPLIED === $write->status()
            ) {
                return self::transition_result(
                    true,
                    $current,
                    self::projection(self::STATUS_ADVANCED, $current, '', $mutation)
                );
            }

            if ($record === $current) {
                return self::transition_result(
                    false,
                    $current,
                    self::from_atomic($write, $current, $prior_mutation)
                );
            }

            return self::transition_result(
                false,
                $current,
                self::projection(
                    Atomic_Option_Result::INDETERMINATE === $write->status()
                    || Atomic_Option_Result::MUTATION_NONE !== $mutation
                        ? self::STATUS_INDETERMINATE
                        : self::STATUS_CONFLICT,
                    $current,
                    '',
                    $mutation
                )
            );
        }

        return self::transition_result(
            false,
            $record,
            self::projection(self::STATUS_INDETERMINATE, $record, '', $mutation)
        );
    }

    /** @return array{status:string,record:array<string,mixed>,projection:array<string,mixed>} */
    private function probe_operation(string $operation_id): array
    {
        $probe = $this->operations->probe($operation_id);
        if (PeerTube_Connection_Operation_Store::PROBE_PRESENT === $probe['status']) {
            $record = is_array($probe['record']) ? $probe['record'] : array();
            if (PeerTube_Connection_State_Machine::valid($record)) {
                return array(
                    'status'     => self::STATUS_ADVANCED,
                    'record'     => $record,
                    'projection' => self::projection(self::STATUS_ADVANCED, $record),
                );
            }
        }

        $status = match ($probe['status']) {
            PeerTube_Connection_Operation_Store::PROBE_INDETERMINATE => self::STATUS_INDETERMINATE,
            PeerTube_Connection_Operation_Store::PROBE_ABSENT => self::STATUS_CONFLICT,
            default => self::STATUS_REFUSED,
        };

        return array(
            'status'     => $status,
            'record'     => array(),
            'projection' => self::projection($status, null, $operation_id),
        );
    }

    /** @param array<string, mixed> $record */
    private function grant_eligible(array $record, string $otp, int $now): bool
    {
        if (
            $now < $record['updated_at']
            || $record['grant_attempt_no'] >= PeerTube_Connection_State_Machine::MAX_GRANT_ATTEMPTS
            || ! in_array(
                $record['phase'],
                array(
                    PeerTube_Connection_State_Machine::PHASE_DISABLED,
                    PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP,
                    PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS,
                ),
                true
            )
            || (PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP === $record['phase'] && '' === $otp)
        ) {
            return false;
        }

        $retry_after = $record['last_error']['retry_after'];
        if ($retry_after < 1) {
            return true;
        }

        return $record['updated_at'] <= PHP_INT_MAX - $retry_after
            && $now >= $record['updated_at'] + $retry_after;
    }

    /** @param array<string, mixed> $record */
    private function prerequisite_probe(array $record): string
    {
        $state = $this->secrets->provisioning_state(
            $record['secret_ref'],
            $record['backend_id'],
            $record['provisioning_id']
        );
        $secret_probe = match ($state['state']) {
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
        $registry_probe = $this->registry->probe_disabled_peertube_state(self::descriptor($record));

        if (
            Atomic_Option_Store::PROBE_INDETERMINATE === $secret_probe
            || Atomic_Option_Store::PROBE_INDETERMINATE === $registry_probe
        ) {
            return Atomic_Option_Store::PROBE_INDETERMINATE;
        }
        if (
            Atomic_Option_Store::PROBE_REFUSED === $secret_probe
            || Atomic_Option_Store::PROBE_REFUSED === $registry_probe
        ) {
            return Atomic_Option_Store::PROBE_REFUSED;
        }

        return Atomic_Option_Store::PROBE_AFTER === $secret_probe
            && Atomic_Option_Store::PROBE_AFTER === $registry_probe
                ? Atomic_Option_Store::PROBE_AFTER
                : Atomic_Option_Store::PROBE_OTHER;
    }

    /** @param array<string, mixed> $record */
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

    /** @param array<string, mixed> $result */
    private static function oauth_client(array $result): ?array
    {
        if (
            array('ok', 'data', 'error') !== array_keys($result)
            || true !== $result['ok']
            || ! is_array($result['data'])
            || null !== $result['error']
            || array('client_id', 'client_secret') !== array_keys($result['data'])
            || ! self::bounded_no_whitespace($result['data']['client_id'], self::MAX_USERNAME_BYTES)
            || ! self::bounded_no_whitespace($result['data']['client_secret'], self::MAX_SECRET_BYTES)
        ) {
            return null;
        }

        return $result['data'];
    }

    /** @param array<string, mixed> $result */
    private static function api_success(array $result): bool
    {
        return array('ok', 'data', 'error') === array_keys($result)
            && true === $result['ok']
            && is_array($result['data'])
            && null === $result['error'];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>|null
     */
    private static function token_secret(array $result, int $now): ?array
    {
        $data = $result['data'] ?? null;
        if (
            ! is_array($data)
            || array(
                'access_token',
                'refresh_token',
                'access_expires_at',
                'refresh_expires_at',
            ) !== array_keys($data)
            || ! self::bounded_no_whitespace($data['access_token'], self::MAX_SECRET_BYTES)
            || ! self::bounded_no_whitespace($data['refresh_token'], self::MAX_SECRET_BYTES)
            || $data['access_token'] === $data['refresh_token']
            || ! is_int($data['access_expires_at'])
            || ! is_int($data['refresh_expires_at'])
            || ! self::usable_expiry($data['access_expires_at'], $now)
            || ! self::usable_expiry($data['refresh_expires_at'], $now)
        ) {
            return null;
        }

        return $data;
    }

    /**
     * Convert only reviewed bounded API classifications into state events.
     *
     * @param array<string, mixed> $result
     * @return array{event:string,payload:array<string,mixed>}
     */
    private static function grant_error(array $result): array
    {
        if (
            array('ok', 'data', 'error') !== array_keys($result)
            || false !== $result['ok']
            || null !== $result['data']
            || ! is_array($result['error'])
        ) {
            return array(
                'event'   => PeerTube_Connection_State_Machine::EVENT_GRANT_INDETERMINATE,
                'payload' => array('reason' => 'invalid_response', 'http_status' => 200),
            );
        }

        $error = $result['error'] ?? null;
        $status = is_array($error) && is_string($error['status'] ?? null)
            ? $error['status']
            : '';
        $http_status = is_array($error) && is_int($error['http_status'] ?? null)
            ? $error['http_status']
            : 0;
        $code = is_array($error) && is_string($error['code'] ?? null)
            ? $error['code']
            : '';
        $retry_after = is_array($error) && is_int($error['retry_after'] ?? null)
            ? $error['retry_after']
            : 0;

        if (
            'otp_required' === $status
            && 401 === $http_status
            && in_array($code, array('', 'missing_two_factor', 'invalid_grant'), true)
        ) {
            return array(
                'event'   => PeerTube_Connection_State_Machine::EVENT_OTP_REQUIRED,
                'payload' => array('http_status' => 401, 'retry_after' => 0),
            );
        }

        $reason = match (true) {
            400 === $http_status && 'invalid_two_factor' === $code => 'invalid_otp',
            in_array($http_status, array(400, 401), true) && 'invalid_client' === $code => 'invalid_client',
            400 === $http_status
                && in_array($code, array('invalid_grant', 'invalid_token', 'too_long_password'), true) =>
                    'invalid_credentials',
            'permission_denied' === $status && in_array($http_status, array(400, 403), true) =>
                    'permission_denied',
            'rate_limited' === $status && 429 === $http_status
                && $retry_after >= 0 && $retry_after <= 86400 => 'rate_limited',
            default => '',
        };
        if ('' !== $reason) {
            return array(
                'event'   => PeerTube_Connection_State_Machine::EVENT_CREDENTIALS_REJECTED,
                'payload' => array(
                    'reason'      => $reason,
                    'http_status' => $http_status,
                    'retry_after' => 'rate_limited' === $reason ? $retry_after : 0,
                ),
            );
        }

        if (in_array($status, array('transport_error', 'tls_error'), true) && 0 === $http_status) {
            return array(
                'event'   => PeerTube_Connection_State_Machine::EVENT_GRANT_INDETERMINATE,
                'payload' => array('reason' => 'transport_error', 'http_status' => 0),
            );
        }
        if ('remote_error' === $status && $http_status >= 500 && $http_status <= 599) {
            return array(
                'event'   => PeerTube_Connection_State_Machine::EVENT_GRANT_INDETERMINATE,
                'payload' => array('reason' => 'remote_error', 'http_status' => $http_status),
            );
        }

        return array(
            'event'   => PeerTube_Connection_State_Machine::EVENT_GRANT_INDETERMINATE,
            'payload' => array(
                'reason'      => 'invalid_response',
                'http_status' => $http_status >= 200 && $http_status <= 499 && 429 !== $http_status
                    ? $http_status
                    : 200,
            ),
        );
    }

    private static function usable_expiry(int $expires_at, int $received_at): bool
    {
        if (
            $received_at > PHP_INT_MAX - self::MAX_TOKEN_LIFETIME_SECONDS
        ) {
            return false;
        }

        return $expires_at > $received_at + self::MIN_USABLE_TOKEN_LIFETIME_SECONDS
            && $expires_at <= $received_at + self::MAX_TOKEN_LIFETIME_SECONDS;
    }

    private static function valid_credentials(
        string $username,
        string $password,
        string $otp
    ): bool {
        return PeerTube_Connection_Input::valid_credentials($username, $password, $otp);
    }

    private static function bounded_no_whitespace(mixed $value, int $maximum_bytes): bool
    {
        return is_string($value)
            && self::bounded_request_text($value, $maximum_bytes, false);
    }

    private static function bounded_request_text(
        string $value,
        int $maximum_bytes,
        bool $allow_whitespace
    ): bool {
        if (
            '' === $value
            || strlen($value) > $maximum_bytes
            || 1 !== preg_match('//u', $value)
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            return false;
        }

        return $allow_whitespace || 1 !== preg_match('/\s/u', $value);
    }

    private static function random_id(string $prefix): string
    {
        try {
            return $prefix . bin2hex(random_bytes(16));
        } catch (Throwable) {
            return '';
        }
    }

    private static function random_capability(): string
    {
        try {
            return bin2hex(random_bytes(32));
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * Return a fresh monotonic event time without accepting a regressing clock.
     */
    private function observed_time(int $minimum): int
    {
        try {
            $observed = ($this->clock)($minimum);
        } catch (Throwable) {
            return $minimum;
        }

        return is_int($observed) && $observed >= $minimum
            ? $observed
            : $minimum;
    }

    private static function stale(int $updated_at, int $now): bool
    {
        return $updated_at <= PHP_INT_MAX - self::STALE_ATTEMPT_SECONDS
            && $now > $updated_at + self::STALE_ATTEMPT_SECONDS;
    }

    private static function fresh_request_mark(int $started_at, int $edge_now): bool
    {
        return $started_at > 0
            && $edge_now >= $started_at
            && $started_at <= PHP_INT_MAX - self::MAX_PRE_POST_MARK_AGE_SECONDS
            && $edge_now <= $started_at + self::MAX_PRE_POST_MARK_AGE_SECONDS;
    }

    /** @param array<string, mixed>|null $record */
    private static function from_probe(string $probe, ?array $record): array
    {
        return self::projection(
            match ($probe) {
                Atomic_Option_Store::PROBE_INDETERMINATE => self::STATUS_INDETERMINATE,
                Atomic_Option_Store::PROBE_REFUSED => self::STATUS_REFUSED,
                default => self::STATUS_CONFLICT,
            },
            $record
        );
    }

    /** @param array<string, mixed>|null $record */
    private static function from_atomic(
        Atomic_Option_Result $result,
        ?array $record,
        string $prior_mutation = Atomic_Option_Result::MUTATION_NONE
    ): array {
        return self::projection(
            match ($result->status()) {
                Atomic_Option_Result::APPLIED => self::STATUS_ADVANCED,
                Atomic_Option_Result::CONFLICT => self::STATUS_CONFLICT,
                Atomic_Option_Result::INDETERMINATE => self::STATUS_INDETERMINATE,
                default => self::STATUS_REFUSED,
            },
            $record,
            '',
            self::merge_mutation($prior_mutation, $result->mutation())
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
     * @param array<string, mixed> $record
     * @param array<string, mixed> $projection
     * @return array{confirmed:bool,record:array<string,mixed>,projection:array<string,mixed>}
     */
    private static function transition_result(
        bool $confirmed,
        array $record,
        array $projection
    ): array {
        return array(
            'confirmed'  => $confirmed,
            'record'     => $record,
            'projection' => $projection,
        );
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
     *   record_revision:int,
     *   retry_after:int
     * }
     */
    private static function projection(
        string $status,
        ?array $record = null,
        string $operation_id = '',
        string $mutation = Atomic_Option_Result::MUTATION_NONE
    ): array {
        $record_operation_id = is_array($record)
            ? self::projected_operation_id($record['operation_id'] ?? null)
            : '';
        $retry_after = is_array($record) && is_array($record['last_error'] ?? null)
            && is_int($record['last_error']['retry_after'] ?? null)
                ? min(max($record['last_error']['retry_after'], 0), 86400)
                : 0;

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
            'retry_after'     => $retry_after,
        );
    }
}

// EOF: includes/PeerTube_Password_Grant_Service.php
