<?php
/**
 * File: includes/PeerTube_Connection_State_Machine.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use ReflectionReference;

/**
 * Pure, bounded state contract for one prospective PeerTube connection.
 *
 * This class performs no persistence, HTTP, secret-store, registry, option,
 * hook, or authorization work. Callers submit an allowlisted event; callers
 * never select the resulting phase.
 */
final class PeerTube_Connection_State_Machine
{
    public const VERSION = 1;

    public const PHASE_PREPARED = 'prepared';
    public const PHASE_SECRET_RESERVE_PLANNED = 'secret_reserve_planned';
    public const PHASE_SECRET_RESERVED = 'secret_reserved';
    public const PHASE_LINK_PLANNED = 'link_planned';
    public const PHASE_DISABLED = 'disabled';
    public const PHASE_GRANT_IN_FLIGHT = 'grant_in_flight';
    public const PHASE_OTP_RESULT_PENDING = 'otp_result_pending';
    public const PHASE_CREDENTIAL_RESULT_PENDING = 'credential_result_pending';
    public const PHASE_AWAITING_OTP = 'awaiting_otp';
    public const PHASE_AWAITING_CREDENTIALS = 'awaiting_credentials';
    public const PHASE_GRANT_INDETERMINATE = 'grant_indeterminate';
    public const PHASE_SECRET_WRITE_PLANNED = 'secret_write_planned';
    public const PHASE_SECRET_STORED = 'secret_stored';
    public const PHASE_VERIFICATION_IN_FLIGHT = 'verification_in_flight';
    public const PHASE_VERIFICATION_FAILED = 'verification_failed';
    public const PHASE_AWAITING_DESTINATION = 'awaiting_destination';
    public const PHASE_ACTIVATION_READY = 'activation_ready';
    public const PHASE_ACTIVATION_PLANNED = 'activation_planned';
    public const PHASE_ACTIVE_PENDING_CLOSE = 'active_pending_close';
    public const PHASE_COMPLETE = 'complete';

    public const EVENT_PLAN_SECRET_RESERVATION = 'plan_secret_reservation';
    public const EVENT_CONFIRM_SECRET_RESERVED = 'confirm_secret_reserved';
    public const EVENT_PLAN_DISABLED_LINK = 'plan_disabled_link';
    public const EVENT_REPLAN_DISABLED_LINK = 'replan_disabled_link_after_conflict';
    public const EVENT_CONFIRM_DISABLED_LINK = 'confirm_disabled_link';
    public const EVENT_BEGIN_GRANT = 'begin_grant';
    public const EVENT_MARK_GRANT_REQUEST = 'mark_grant_request';
    public const EVENT_GRANT_NOT_SENT = 'grant_not_sent';
    public const EVENT_OTP_REQUIRED = 'otp_required';
    public const EVENT_CREDENTIALS_REJECTED = 'credentials_rejected';
    public const EVENT_CONFIRM_GRANT_RESULT = 'confirm_grant_result';
    public const EVENT_GRANT_INDETERMINATE = 'grant_indeterminate';
    public const EVENT_PLAN_SECRET_STORAGE = 'plan_secret_storage';
    public const EVENT_CONFIRM_SECRET_STORED = 'confirm_secret_stored';
    public const EVENT_BEGIN_VERIFICATION = 'begin_verification';
    public const EVENT_VERIFICATION_FAILED = 'verification_failed';
    public const EVENT_VERIFICATION_SUCCEEDED = 'verification_succeeded';
    public const EVENT_SELECT_DESTINATION = 'select_destination';
    public const EVENT_PLAN_ACTIVATION = 'plan_activation';
    public const EVENT_REPLAN_ACTIVATION = 'replan_activation_after_conflict';
    public const EVENT_CONFIRM_ACTIVATION = 'confirm_activation';
    public const EVENT_COMPLETE = 'complete';

    public const MAX_GRANT_ATTEMPTS = 8;

    private const MAX_RECORD_BYTES = 16384;
    private const MAX_OPTION_VALUE_BYTES = 1048576;
    private const MAX_RETRY_AFTER = 86400;
    private const GRANT_ATTEMPT_DOMAIN = 'awvp-peertube-grant-attempt-v1:';

    /**
     * Create the first durable journal value.
     *
     * @param array<string, mixed> $intent
     * @return array<string, mixed>|null
     */
    public static function create(array $intent, int $actor_id, int $now): ?array
    {
        if (
            self::contains_reference($intent)
            || ! self::has_exact_keys(
                $intent,
                array(
                    'operation_id',
                    'backend_id',
                    'origin',
                    'label',
                    'secret_ref',
                    'provisioning_id',
                )
            )
        ) {
            return null;
        }

        $operation_id = self::operation_id($intent['operation_id']);
        $backend_id = Backend_Identity::sanitize($intent['backend_id']);
        $origin = PeerTube_Origin::sanitize($intent['origin']);
        $label = self::label($intent['label']);
        $secret_ref = self::secret_ref($intent['secret_ref']);
        $provisioning_id = self::provisioning_id($intent['provisioning_id']);

        if (
            '' === $operation_id
            || '' === $backend_id
            || 'local' === $backend_id
            || '' === $origin
            || $origin !== $intent['origin']
            || '' === $label
            || '' === $secret_ref
            || '' === $provisioning_id
            || $actor_id < 1
            || $now < 1
        ) {
            return null;
        }

        $record = array(
            'version'                       => self::VERSION,
            'operation_id'                  => $operation_id,
            'record_revision'               => 1,
            'backend_id'                    => $backend_id,
            'origin'                        => $origin,
            'label'                         => $label,
            'secret_ref'                    => $secret_ref,
            'provisioning_id'               => $provisioning_id,
            'phase'                         => self::PHASE_PREPARED,
            'grant_attempt_no'              => 0,
            'grant_attempt_id'              => '',
            'grant_started_at'              => 0,
            'last_mutation'                 => self::empty_mutation(),
            'secret_record_sha256'          => '',
            'secret_generation'             => 0,
            'verified_identity'             => self::empty_identity(),
            'selected_destination'          => '',
            'verified_secret_generation'    => 0,
            'verified_at'                   => 0,
            'activation_requested_by'       => 0,
            'activation_requested_at'       => 0,
            'last_error'                    => self::empty_error(),
            'created_by'                    => $actor_id,
            'created_at'                    => $now,
            'updated_at'                    => $now,
        );

        return self::valid($record) ? $record : null;
    }

    /**
     * Apply one allowlisted event to an exact valid record.
     *
     * A null result means the event, payload, phase, revision, or timestamp was
     * refused. The caller's input record is never modified.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $payload
     * @return array<string, mixed>|null
     */
    public static function apply(array $record, string $event, array $payload, int $now): ?array
    {
        if (
            ! self::valid($record)
            || $now < 1
            || $now < $record['updated_at']
            || $record['record_revision'] >= PHP_INT_MAX
            || self::contains_reference($payload)
            || self::contains_forbidden_key($payload)
        ) {
            return null;
        }

        $next = $record;
        $phase = $record['phase'];

        if (self::EVENT_PLAN_SECRET_RESERVATION === $event) {
            if (self::PHASE_PREPARED !== $phase || ! self::valid_mutation($payload, 'secret_reserve')) {
                return null;
            }

            $next['phase'] = self::PHASE_SECRET_RESERVE_PLANNED;
            $next['last_mutation'] = $payload;
        } elseif (self::EVENT_CONFIRM_SECRET_RESERVED === $event) {
            if (self::PHASE_SECRET_RESERVE_PLANNED !== $phase || [] !== $payload) {
                return null;
            }

            $next['phase'] = self::PHASE_SECRET_RESERVED;
        } elseif (self::EVENT_PLAN_DISABLED_LINK === $event) {
            if (self::PHASE_SECRET_RESERVED !== $phase || ! self::valid_mutation($payload, 'registry_link')) {
                return null;
            }

            $next['phase'] = self::PHASE_LINK_PLANNED;
            $next['last_mutation'] = $payload;
        } elseif (self::EVENT_REPLAN_DISABLED_LINK === $event) {
            // This event is authority only after a definite no-mutation CAS
            // conflict. Indeterminate writes must reconcile the existing plan.
            if (
                self::PHASE_LINK_PLANNED !== $phase
                || ! self::valid_mutation($payload, 'registry_link')
                || $payload['mutation_id'] === $record['last_mutation']['mutation_id']
            ) {
                return null;
            }

            $next['last_mutation'] = $payload;
        } elseif (self::EVENT_CONFIRM_DISABLED_LINK === $event) {
            if (self::PHASE_LINK_PLANNED !== $phase || [] !== $payload) {
                return null;
            }

            $next['phase'] = self::PHASE_DISABLED;
        } elseif (self::EVENT_BEGIN_GRANT === $event) {
            if (
                ! in_array(
                    $phase,
                    array(
                        self::PHASE_DISABLED,
                        self::PHASE_AWAITING_OTP,
                        self::PHASE_AWAITING_CREDENTIALS,
                    ),
                    true
                )
                || ! self::has_exact_keys($payload, array('attempt_capability'))
                || '' === self::attempt_commitment($payload['attempt_capability'])
                || hash_equals(
                    $record['grant_attempt_id'],
                    self::attempt_commitment($payload['attempt_capability'])
                )
                || $record['grant_attempt_no'] >= self::MAX_GRANT_ATTEMPTS
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_GRANT_IN_FLIGHT;
            $next['grant_attempt_no'] = $record['grant_attempt_no'] + 1;
            $next['grant_attempt_id'] = self::attempt_commitment($payload['attempt_capability']);
            $next['grant_started_at'] = $now;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_MARK_GRANT_REQUEST === $event) {
            if (
                self::PHASE_GRANT_IN_FLIGHT !== $phase
                || ! self::has_exact_keys($payload, array('attempt_capability'))
                || ! self::attempt_capability_matches(
                    $payload['attempt_capability'],
                    $record['grant_attempt_id']
                )
            ) {
                return null;
            }

            // Refresh the durable stale-attempt boundary immediately before
            // the caller performs the one credential-bearing request.
            $next['grant_started_at'] = $now;
        } elseif (self::EVENT_GRANT_NOT_SENT === $event) {
            $reason = $payload['reason'] ?? null;
            if (
                self::PHASE_GRANT_IN_FLIGHT !== $phase
                || ! self::has_exact_keys(
                    $payload,
                    array('attempt_capability', 'reason', 'http_status', 'retry_after')
                )
                || ! self::attempt_capability_matches(
                    $payload['attempt_capability'],
                    $record['grant_attempt_id']
                )
                || ! in_array(
                    $reason,
                    array('local_prerequisite_changed', 'request_window_expired'),
                    true
                )
                || 0 !== $payload['http_status']
                || 0 !== $payload['retry_after']
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_AWAITING_CREDENTIALS;
            $next['last_error'] = self::error(
                'peertube.auth.grant_not_sent',
                $payload['http_status'],
                $payload['retry_after']
            );
        } elseif (self::EVENT_OTP_REQUIRED === $event) {
            if (
                self::PHASE_GRANT_IN_FLIGHT !== $phase
                || ! self::has_exact_keys($payload, array('http_status', 'retry_after'))
                || 401 !== $payload['http_status']
                || 0 !== $payload['retry_after']
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_OTP_RESULT_PENDING;
            $next['last_error'] = self::error(
                'peertube.auth.otp_required',
                $payload['http_status'],
                $payload['retry_after']
            );
        } elseif (self::EVENT_CREDENTIALS_REJECTED === $event) {
            $reason = $payload['reason'] ?? null;
            if (
                self::PHASE_GRANT_IN_FLIGHT !== $phase
                || ! self::has_exact_keys($payload, array('reason', 'http_status', 'retry_after'))
                || ! is_string($reason)
                || ! isset(self::credential_error_codes()[$reason])
                || ! self::valid_credential_error(
                    $reason,
                    $payload['http_status'],
                    $payload['retry_after']
                )
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_CREDENTIAL_RESULT_PENDING;
            $next['last_error'] = self::error(
                self::credential_error_codes()[$reason],
                $payload['http_status'],
                $payload['retry_after']
            );
        } elseif (self::EVENT_CONFIRM_GRANT_RESULT === $event) {
            if (
                ! self::has_exact_keys($payload, array('attempt_capability'))
                || ! self::attempt_capability_matches(
                    $payload['attempt_capability'],
                    $record['grant_attempt_id']
                )
            ) {
                return null;
            }

            if (self::PHASE_OTP_RESULT_PENDING === $phase) {
                $next['phase'] = self::PHASE_AWAITING_OTP;
            } elseif (self::PHASE_CREDENTIAL_RESULT_PENDING === $phase) {
                $next['phase'] = self::PHASE_AWAITING_CREDENTIALS;
            } else {
                return null;
            }
        } elseif (self::EVENT_GRANT_INDETERMINATE === $event) {
            $reason = $payload['reason'] ?? null;
            if (
                ! in_array(
                    $phase,
                    array(
                        self::PHASE_GRANT_IN_FLIGHT,
                        self::PHASE_OTP_RESULT_PENDING,
                        self::PHASE_CREDENTIAL_RESULT_PENDING,
                        self::PHASE_AWAITING_OTP,
                        self::PHASE_AWAITING_CREDENTIALS,
                        self::PHASE_SECRET_WRITE_PLANNED,
                    ),
                    true
                )
                || ! self::has_exact_keys($payload, array('reason', 'http_status'))
                || ! is_string($reason)
                || ! isset(self::indeterminate_error_codes()[$reason])
                || ! self::valid_indeterminate_error($reason, $payload['http_status'])
                || (
                    in_array(
                        $phase,
                        array(
                            self::PHASE_AWAITING_OTP,
                            self::PHASE_AWAITING_CREDENTIALS,
                            self::PHASE_OTP_RESULT_PENDING,
                            self::PHASE_CREDENTIAL_RESULT_PENDING,
                            self::PHASE_SECRET_WRITE_PLANNED,
                        ),
                        true
                    )
                    && 'local_persistence_unknown' !== $reason
                )
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_GRANT_INDETERMINATE;
            $next['last_error'] = self::error(
                self::indeterminate_error_codes()[$reason],
                $payload['http_status'],
                0
            );
        } elseif (self::EVENT_PLAN_SECRET_STORAGE === $event) {
            if (self::PHASE_GRANT_IN_FLIGHT !== $phase || ! self::valid_mutation($payload, 'secret_commit')) {
                return null;
            }

            $next['phase'] = self::PHASE_SECRET_WRITE_PLANNED;
            $next['last_mutation'] = $payload;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_CONFIRM_SECRET_STORED === $event) {
            if (
                [] !== $payload
                || ! (
                    self::PHASE_SECRET_WRITE_PLANNED === $phase
                    || (
                        self::PHASE_GRANT_INDETERMINATE === $phase
                        && 'secret_commit' === $record['last_mutation']['kind']
                    )
                )
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_SECRET_STORED;
            $next['secret_record_sha256'] = $record['last_mutation']['after_sha256'];
            $next['secret_generation'] = 1;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_BEGIN_VERIFICATION === $event) {
            if (
                ! in_array($phase, array(self::PHASE_SECRET_STORED, self::PHASE_VERIFICATION_FAILED), true)
                || [] !== $payload
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_VERIFICATION_IN_FLIGHT;
            $next['verified_identity'] = self::empty_identity();
            $next['verified_secret_generation'] = 0;
            $next['verified_at'] = 0;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_VERIFICATION_FAILED === $event) {
            $reason = $payload['reason'] ?? null;
            if (
                self::PHASE_VERIFICATION_IN_FLIGHT !== $phase
                || ! self::has_exact_keys($payload, array('reason', 'http_status', 'retry_after'))
                || ! is_string($reason)
                || ! isset(self::verification_error_codes()[$reason])
                || ! self::valid_verification_error(
                    $reason,
                    $payload['http_status'],
                    $payload['retry_after']
                )
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_VERIFICATION_FAILED;
            $next['last_error'] = self::error(
                self::verification_error_codes()[$reason],
                $payload['http_status'],
                $payload['retry_after']
            );
        } elseif (self::EVENT_VERIFICATION_SUCCEEDED === $event) {
            if (
                self::PHASE_VERIFICATION_IN_FLIGHT !== $phase
                || ! self::has_exact_keys($payload, array('identity', 'secret_generation'))
                || ! self::valid_identity($payload['identity'], false)
                || ! is_int($payload['secret_generation'])
                || $payload['secret_generation'] !== $record['secret_generation']
            ) {
                return null;
            }

            $next['verified_identity'] = $payload['identity'];
            $next['verified_secret_generation'] = $payload['secret_generation'];
            $next['verified_at'] = $now;
            $next['last_error'] = self::empty_error();
            $next['phase'] = '' === $record['selected_destination']
                ? self::PHASE_AWAITING_DESTINATION
                : self::PHASE_ACTIVATION_READY;
        } elseif (self::EVENT_SELECT_DESTINATION === $event) {
            if (
                self::PHASE_AWAITING_DESTINATION !== $phase
                || ! self::has_exact_keys($payload, array('destination_id', 'actor_id'))
                || '' === self::decimal_id($payload['destination_id'])
                || ! is_int($payload['actor_id'])
                || $payload['actor_id'] < 1
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_VERIFICATION_IN_FLIGHT;
            $next['selected_destination'] = $payload['destination_id'];
            $next['verified_identity'] = self::empty_identity();
            $next['verified_secret_generation'] = 0;
            $next['verified_at'] = 0;
            $next['activation_requested_by'] = $payload['actor_id'];
            $next['activation_requested_at'] = $now;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_PLAN_ACTIVATION === $event) {
            if (self::PHASE_ACTIVATION_READY !== $phase || ! self::valid_mutation($payload, 'registry_activate')) {
                return null;
            }

            $next['phase'] = self::PHASE_ACTIVATION_PLANNED;
            $next['last_mutation'] = $payload;
        } elseif (self::EVENT_REPLAN_ACTIVATION === $event) {
            // This event is authority only after a definite no-mutation CAS
            // conflict. Indeterminate writes must reconcile the existing plan.
            if (
                self::PHASE_ACTIVATION_PLANNED !== $phase
                || ! self::valid_mutation($payload, 'registry_activate')
                || $payload['mutation_id'] === $record['last_mutation']['mutation_id']
            ) {
                return null;
            }

            $next['last_mutation'] = $payload;
        } elseif (self::EVENT_CONFIRM_ACTIVATION === $event) {
            if (self::PHASE_ACTIVATION_PLANNED !== $phase || [] !== $payload) {
                return null;
            }

            $next['phase'] = self::PHASE_ACTIVE_PENDING_CLOSE;
        } elseif (self::EVENT_COMPLETE === $event) {
            if (self::PHASE_ACTIVE_PENDING_CLOSE !== $phase || [] !== $payload) {
                return null;
            }

            $next['phase'] = self::PHASE_COMPLETE;
        } else {
            return null;
        }

        $next['record_revision'] = $record['record_revision'] + 1;
        $next['updated_at'] = $now;

        return self::valid($next) ? $next : null;
    }

    /** @param mixed $record */
    public static function valid(mixed $record): bool
    {
        if (! is_array($record) || ! self::has_exact_keys($record, self::record_keys())) {
            return false;
        }

        if (
            self::contains_reference($record)
            || self::contains_forbidden_key($record)
            || self::VERSION !== ($record['version'] ?? null)
            || '' === self::operation_id($record['operation_id'] ?? null)
            || ! is_int($record['record_revision'])
            || $record['record_revision'] < 1
            || '' === Backend_Identity::sanitize($record['backend_id'] ?? null)
            || 'local' === $record['backend_id']
            || '' === PeerTube_Origin::sanitize($record['origin'] ?? null)
            || PeerTube_Origin::sanitize($record['origin']) !== $record['origin']
            || '' === self::label($record['label'] ?? null)
            || '' === self::secret_ref($record['secret_ref'] ?? null)
            || '' === self::provisioning_id($record['provisioning_id'] ?? null)
            || ! is_string($record['phase'])
            || ! in_array($record['phase'], self::phases(), true)
            || ! is_int($record['grant_attempt_no'])
            || $record['grant_attempt_no'] < 0
            || $record['grant_attempt_no'] > self::MAX_GRANT_ATTEMPTS
            || ! is_string($record['grant_attempt_id'])
            || ('' !== $record['grant_attempt_id'] && '' === self::attempt_id($record['grant_attempt_id']))
            || ! is_int($record['grant_started_at'])
            || $record['grant_started_at'] < 0
            || ! self::valid_mutation($record['last_mutation'], null, true)
            || ! is_string($record['secret_record_sha256'])
            || ('' !== $record['secret_record_sha256'] && ! self::is_sha256($record['secret_record_sha256']))
            || ! is_int($record['secret_generation'])
            || $record['secret_generation'] < 0
            || ! self::valid_identity($record['verified_identity'], true)
            || ! is_string($record['selected_destination'])
            || ('' !== $record['selected_destination'] && '' === self::decimal_id($record['selected_destination']))
            || ! is_int($record['verified_secret_generation'])
            || $record['verified_secret_generation'] < 0
            || ! is_int($record['verified_at'])
            || $record['verified_at'] < 0
            || ! is_int($record['activation_requested_by'])
            || $record['activation_requested_by'] < 0
            || ! is_int($record['activation_requested_at'])
            || $record['activation_requested_at'] < 0
            || ! self::valid_error($record['last_error'])
            || ! is_int($record['created_by'])
            || $record['created_by'] < 1
            || ! is_int($record['created_at'])
            || $record['created_at'] < 1
            || ! is_int($record['updated_at'])
            || $record['updated_at'] < $record['created_at']
            || ($record['grant_started_at'] > 0 && (
                $record['grant_started_at'] < $record['created_at']
                || $record['grant_started_at'] > $record['updated_at']
            ))
            || ($record['verified_at'] > 0 && (
                $record['verified_at'] < $record['created_at']
                || $record['verified_at'] > $record['updated_at']
            ))
            || ($record['activation_requested_at'] > 0 && (
                $record['activation_requested_at'] < $record['created_at']
                || $record['activation_requested_at'] > $record['updated_at']
            ))
            || ! self::valid_phase_state($record)
        ) {
            return false;
        }

        return strlen(serialize($record)) <= self::MAX_RECORD_BYTES;
    }

    /** @return list<string> */
    private static function record_keys(): array
    {
        return array(
            'version',
            'operation_id',
            'record_revision',
            'backend_id',
            'origin',
            'label',
            'secret_ref',
            'provisioning_id',
            'phase',
            'grant_attempt_no',
            'grant_attempt_id',
            'grant_started_at',
            'last_mutation',
            'secret_record_sha256',
            'secret_generation',
            'verified_identity',
            'selected_destination',
            'verified_secret_generation',
            'verified_at',
            'activation_requested_by',
            'activation_requested_at',
            'last_error',
            'created_by',
            'created_at',
            'updated_at',
        );
    }

    /** @return list<string> */
    private static function phases(): array
    {
        return array(
            self::PHASE_PREPARED,
            self::PHASE_SECRET_RESERVE_PLANNED,
            self::PHASE_SECRET_RESERVED,
            self::PHASE_LINK_PLANNED,
            self::PHASE_DISABLED,
            self::PHASE_GRANT_IN_FLIGHT,
            self::PHASE_OTP_RESULT_PENDING,
            self::PHASE_CREDENTIAL_RESULT_PENDING,
            self::PHASE_AWAITING_OTP,
            self::PHASE_AWAITING_CREDENTIALS,
            self::PHASE_GRANT_INDETERMINATE,
            self::PHASE_SECRET_WRITE_PLANNED,
            self::PHASE_SECRET_STORED,
            self::PHASE_VERIFICATION_IN_FLIGHT,
            self::PHASE_VERIFICATION_FAILED,
            self::PHASE_AWAITING_DESTINATION,
            self::PHASE_ACTIVATION_READY,
            self::PHASE_ACTIVATION_PLANNED,
            self::PHASE_ACTIVE_PENDING_CLOSE,
            self::PHASE_COMPLETE,
        );
    }

    /** @param array<string, mixed> $record */
    private static function valid_phase_state(array $record): bool
    {
        $phase = $record['phase'];
        $has_attempt = $record['grant_attempt_no'] > 0
            && '' !== $record['grant_attempt_id']
            && $record['grant_started_at'] > 0;
        $no_attempt = 0 === $record['grant_attempt_no']
            && '' === $record['grant_attempt_id']
            && 0 === $record['grant_started_at'];
        $has_secret = 1 === $record['secret_generation']
            && self::is_sha256($record['secret_record_sha256']);
        $no_secret = 0 === $record['secret_generation']
            && '' === $record['secret_record_sha256'];
        $has_identity = self::valid_identity($record['verified_identity'], false)
            && $record['verified_secret_generation'] === $record['secret_generation']
            && $record['verified_at'] > 0;
        $no_identity = self::valid_identity($record['verified_identity'], true)
            && '' === $record['verified_identity']['user_id']
            && 0 === $record['verified_secret_generation']
            && 0 === $record['verified_at'];
        $has_destination = '' !== $record['selected_destination']
            && $record['activation_requested_by'] > 0
            && $record['activation_requested_at'] > 0;
        $no_destination = '' === $record['selected_destination']
            && 0 === $record['activation_requested_by']
            && 0 === $record['activation_requested_at'];
        $no_error = self::empty_error() === $record['last_error'];

        if (in_array($phase, array(
            self::PHASE_PREPARED,
            self::PHASE_SECRET_RESERVE_PLANNED,
            self::PHASE_SECRET_RESERVED,
            self::PHASE_LINK_PLANNED,
            self::PHASE_DISABLED,
        ), true)) {
            if (! $no_attempt || ! $no_secret || ! $no_identity || ! $no_destination || ! $no_error) {
                return false;
            }

            $kind = $record['last_mutation']['kind'];
            return match ($phase) {
                self::PHASE_PREPARED => '' === $kind,
                self::PHASE_SECRET_RESERVE_PLANNED,
                self::PHASE_SECRET_RESERVED => 'secret_reserve' === $kind,
                self::PHASE_LINK_PLANNED,
                self::PHASE_DISABLED => 'registry_link' === $kind,
                default => false,
            };
        }

        if (in_array($phase, array(
            self::PHASE_GRANT_IN_FLIGHT,
            self::PHASE_OTP_RESULT_PENDING,
            self::PHASE_CREDENTIAL_RESULT_PENDING,
            self::PHASE_AWAITING_OTP,
            self::PHASE_AWAITING_CREDENTIALS,
            self::PHASE_GRANT_INDETERMINATE,
            self::PHASE_SECRET_WRITE_PLANNED,
        ), true)) {
            if (! $has_attempt || ! $no_secret || ! $no_identity || ! $no_destination) {
                return false;
            }

            if (self::PHASE_SECRET_WRITE_PLANNED === $phase) {
                return $no_error && 'secret_commit' === $record['last_mutation']['kind'];
            }

            if (
                self::PHASE_GRANT_INDETERMINATE === $phase
                && 'secret_commit' === $record['last_mutation']['kind']
            ) {
                return 'peertube.auth.grant_indeterminate' === $record['last_error']['code']
                    && 0 === $record['last_error']['http_status']
                    && 0 === $record['last_error']['retry_after'];
            }

            if ('registry_link' !== $record['last_mutation']['kind']) {
                return false;
            }

            if (self::PHASE_GRANT_IN_FLIGHT === $phase) {
                return $no_error;
            }

            if ($no_error) {
                return false;
            }

            return match ($phase) {
                self::PHASE_OTP_RESULT_PENDING,
                self::PHASE_AWAITING_OTP => self::valid_otp_record_error($record['last_error']),
                self::PHASE_CREDENTIAL_RESULT_PENDING => self::valid_credential_record_error(
                    $record['last_error']
                ),
                self::PHASE_AWAITING_CREDENTIALS => self::valid_credential_record_error($record['last_error'])
                    || self::valid_grant_not_sent_record_error($record['last_error']),
                self::PHASE_GRANT_INDETERMINATE => self::valid_indeterminate_record_error(
                    $record['last_error']
                ),
                default => false,
            };
        }

        if (! $has_attempt || ! $has_secret) {
            return false;
        }

        if (! in_array($phase, array(
            self::PHASE_ACTIVATION_PLANNED,
            self::PHASE_ACTIVE_PENDING_CLOSE,
            self::PHASE_COMPLETE,
        ), true) && 'secret_commit' !== $record['last_mutation']['kind']) {
            return false;
        }

        if (
            ! in_array($phase, array(
                self::PHASE_ACTIVATION_PLANNED,
                self::PHASE_ACTIVE_PENDING_CLOSE,
                self::PHASE_COMPLETE,
            ), true)
            && $record['secret_record_sha256'] !== $record['last_mutation']['after_sha256']
        ) {
            return false;
        }

        if (self::PHASE_SECRET_STORED === $phase) {
            return $no_identity && $no_destination && $no_error;
        }

        if (self::PHASE_VERIFICATION_IN_FLIGHT === $phase) {
            return $no_identity && $no_error && ($no_destination || $has_destination);
        }

        if (self::PHASE_VERIFICATION_FAILED === $phase) {
            return $no_identity
                && ! $no_error
                && ($no_destination || $has_destination)
                && self::valid_verification_record_error($record['last_error']);
        }

        if (self::PHASE_AWAITING_DESTINATION === $phase) {
            return $has_identity && $no_destination && $no_error;
        }

        if (self::PHASE_ACTIVATION_READY === $phase) {
            return $has_identity
                && $has_destination
                && $record['verified_at'] >= $record['activation_requested_at']
                && $no_error;
        }

        if (in_array($phase, array(
            self::PHASE_ACTIVATION_PLANNED,
            self::PHASE_ACTIVE_PENDING_CLOSE,
            self::PHASE_COMPLETE,
        ), true)) {
            return $has_identity
                && $has_destination
                && $record['verified_at'] >= $record['activation_requested_at']
                && $no_error
                && 'registry_activate' === $record['last_mutation']['kind'];
        }

        return false;
    }

    /**
     * @param mixed $mutation
     */
    private static function valid_mutation(mixed $mutation, ?string $expected_kind, bool $allow_empty = false): bool
    {
        if (! is_array($mutation) || ! self::has_exact_keys(
            $mutation,
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

        if ($allow_empty && self::empty_mutation() === $mutation) {
            return true;
        }

        $kinds = array('secret_reserve', 'registry_link', 'secret_commit', 'registry_activate');
        if (
            ! is_string($mutation['kind'])
            || ! in_array($mutation['kind'], $kinds, true)
            || (null !== $expected_kind && $expected_kind !== $mutation['kind'])
            || '' === self::mutation_id($mutation['mutation_id'])
            || ! is_bool($mutation['before_exists'])
            || ! is_string($mutation['before_sha256'])
            || ! is_int($mutation['before_bytes'])
            || ! is_bool($mutation['after_exists'])
            || ! is_string($mutation['after_sha256'])
            || ! is_int($mutation['after_bytes'])
            || $mutation['before_bytes'] < 0
            || $mutation['before_bytes'] > self::MAX_OPTION_VALUE_BYTES
            || $mutation['after_bytes'] < 0
            || $mutation['after_bytes'] > self::MAX_OPTION_VALUE_BYTES
            || ! self::valid_snapshot_fields(
                $mutation['before_exists'],
                $mutation['before_sha256'],
                $mutation['before_bytes']
            )
            || ! self::valid_snapshot_fields(
                $mutation['after_exists'],
                $mutation['after_sha256'],
                $mutation['after_bytes']
            )
            || ! $mutation['after_exists']
        ) {
            return false;
        }

        if (
            ('secret_reserve' === $mutation['kind'] && $mutation['before_exists'])
            || (in_array($mutation['kind'], array('secret_commit', 'registry_activate'), true)
                && ! $mutation['before_exists'])
            || ($mutation['before_exists']
                && $mutation['before_sha256'] === $mutation['after_sha256']
                && $mutation['before_bytes'] === $mutation['after_bytes'])
        ) {
            return false;
        }

        return true;
    }

    private static function valid_snapshot_fields(bool $exists, string $sha256, int $bytes): bool
    {
        return $exists
            ? self::is_sha256($sha256) && $bytes > 0
            : '' === $sha256 && 0 === $bytes;
    }

    /** @param mixed $identity */
    private static function valid_identity(mixed $identity, bool $allow_empty): bool
    {
        if (! is_array($identity) || ! self::has_exact_keys(
            $identity,
            array('user_id', 'username', 'account_id', 'account_name')
        )) {
            return false;
        }

        if ($allow_empty && self::empty_identity() === $identity) {
            return true;
        }

        return '' !== self::decimal_id($identity['user_id'])
            && self::machine_name($identity['username'])
            && '' !== self::decimal_id($identity['account_id'])
            && self::machine_name($identity['account_name']);
    }

    /** @param mixed $error */
    private static function valid_error(mixed $error): bool
    {
        if (! is_array($error) || ! self::has_exact_keys($error, array('code', 'http_status', 'retry_after'))) {
            return false;
        }

        if (self::empty_error() === $error) {
            return true;
        }

        $codes = array_merge(
            array('peertube.auth.otp_required', 'peertube.auth.grant_not_sent'),
            array_values(self::credential_error_codes()),
            array_values(self::indeterminate_error_codes()),
            array_values(self::verification_error_codes())
        );

        return is_string($error['code'])
            && in_array($error['code'], $codes, true)
            && self::valid_http_status($error['http_status'])
            && self::valid_retry_after($error['retry_after']);
    }

    /** @return array<string, string> */
    private static function credential_error_codes(): array
    {
        return array(
            'invalid_credentials' => 'peertube.auth.invalid',
            'invalid_otp'         => 'peertube.auth.invalid',
            'invalid_client'      => 'peertube.auth.invalid',
            'permission_denied'   => 'peertube.auth.permission_denied',
            'rate_limited'        => 'peertube.auth.rate_limited',
        );
    }

    /** @return array<string, string> */
    private static function indeterminate_error_codes(): array
    {
        return array(
            'transport_error'          => 'peertube.connection.failed',
            'remote_error'             => 'peertube.connection.failed',
            'invalid_response'         => 'peertube.response.invalid',
            'process_interrupted'      => 'peertube.auth.grant_indeterminate',
            'local_persistence_unknown' => 'peertube.auth.grant_indeterminate',
        );
    }

    /** @return array<string, string> */
    private static function verification_error_codes(): array
    {
        return array(
            'authentication_required' => 'peertube.auth.reauthentication_required',
            'permission_denied'       => 'peertube.auth.permission_denied',
            'rate_limited'            => 'peertube.auth.rate_limited',
            'transport_error'         => 'peertube.connection.failed',
            'remote_error'            => 'peertube.connection.failed',
            'invalid_response'        => 'peertube.response.invalid',
            'channels_none'           => 'peertube.channels.none',
            'channel_unauthorized'    => 'peertube.channels.unauthorized',
        );
    }

    private static function valid_credential_error(string $reason, mixed $http_status, mixed $retry_after): bool
    {
        if (! is_int($http_status) || ! is_int($retry_after)) {
            return false;
        }

        return match ($reason) {
            'invalid_credentials', 'invalid_otp' => 400 === $http_status && 0 === $retry_after,
            'invalid_client' => in_array($http_status, array(400, 401), true) && 0 === $retry_after,
            'permission_denied' => in_array($http_status, array(400, 403), true) && 0 === $retry_after,
            'rate_limited' => 429 === $http_status && self::valid_retry_after($retry_after),
            default => false,
        };
    }

    private static function valid_indeterminate_error(string $reason, mixed $http_status): bool
    {
        if (! is_int($http_status)) {
            return false;
        }

        return match ($reason) {
            'transport_error', 'process_interrupted', 'local_persistence_unknown' => 0 === $http_status,
            'remote_error' => $http_status >= 500 && $http_status <= 599,
            'invalid_response' => $http_status >= 200
                && $http_status <= 499
                && 429 !== $http_status,
            default => false,
        };
    }

    private static function valid_verification_error(
        string $reason,
        mixed $http_status,
        mixed $retry_after
    ): bool {
        if (! is_int($http_status) || ! is_int($retry_after)) {
            return false;
        }

        return match ($reason) {
            'authentication_required' => 401 === $http_status && 0 === $retry_after,
            'permission_denied' => 403 === $http_status && 0 === $retry_after,
            'rate_limited' => 429 === $http_status && self::valid_retry_after($retry_after),
            'transport_error' => 0 === $http_status && 0 === $retry_after,
            'remote_error' => $http_status >= 500 && $http_status <= 599 && 0 === $retry_after,
            'invalid_response' => $http_status >= 200
                && $http_status <= 499
                && ! in_array($http_status, array(401, 403, 429), true)
                && 0 === $retry_after,
            'channels_none', 'channel_unauthorized' => 200 === $http_status && 0 === $retry_after,
            default => false,
        };
    }

    /** @param array<string, mixed> $error */
    private static function valid_otp_record_error(array $error): bool
    {
        return 'peertube.auth.otp_required' === $error['code']
            && 401 === $error['http_status']
            && 0 === $error['retry_after'];
    }

    /** @param array<string, mixed> $error */
    private static function valid_credential_record_error(array $error): bool
    {
        return match ($error['code']) {
            'peertube.auth.invalid' => in_array($error['http_status'], array(400, 401), true)
                && 0 === $error['retry_after'],
            'peertube.auth.permission_denied' => in_array($error['http_status'], array(400, 403), true)
                && 0 === $error['retry_after'],
            'peertube.auth.rate_limited' => 429 === $error['http_status']
                && self::valid_retry_after($error['retry_after']),
            default => false,
        };
    }

    /** @param array<string, mixed> $error */
    private static function valid_grant_not_sent_record_error(array $error): bool
    {
        return 'peertube.auth.grant_not_sent' === $error['code']
            && 0 === $error['http_status']
            && 0 === $error['retry_after'];
    }

    /** @param array<string, mixed> $error */
    private static function valid_indeterminate_record_error(array $error): bool
    {
        return match ($error['code']) {
            'peertube.connection.failed' => 0 === $error['http_status']
                || ($error['http_status'] >= 500 && $error['http_status'] <= 599),
            'peertube.response.invalid' => $error['http_status'] >= 200
                && $error['http_status'] <= 499
                && 429 !== $error['http_status'],
            'peertube.auth.grant_indeterminate' => 0 === $error['http_status'],
            default => false,
        } && 0 === $error['retry_after'];
    }

    /** @param array<string, mixed> $error */
    private static function valid_verification_record_error(array $error): bool
    {
        return match ($error['code']) {
            'peertube.auth.reauthentication_required' => 401 === $error['http_status']
                && 0 === $error['retry_after'],
            'peertube.auth.permission_denied' => 403 === $error['http_status']
                && 0 === $error['retry_after'],
            'peertube.auth.rate_limited' => 429 === $error['http_status']
                && self::valid_retry_after($error['retry_after']),
            'peertube.connection.failed' => (0 === $error['http_status']
                || ($error['http_status'] >= 500 && $error['http_status'] <= 599))
                && 0 === $error['retry_after'],
            'peertube.response.invalid' => $error['http_status'] >= 200
                && $error['http_status'] <= 499
                && ! in_array($error['http_status'], array(401, 403, 429), true)
                && 0 === $error['retry_after'],
            'peertube.channels.none', 'peertube.channels.unauthorized' => 200 === $error['http_status']
                && 0 === $error['retry_after'],
            default => false,
        };
    }

    /** @return array<string, mixed> */
    private static function empty_mutation(): array
    {
        return array(
            'kind'          => '',
            'mutation_id'   => '',
            'before_exists' => false,
            'before_sha256' => '',
            'before_bytes'  => 0,
            'after_exists'  => false,
            'after_sha256'  => '',
            'after_bytes'   => 0,
        );
    }

    /** @return array<string, string> */
    private static function empty_identity(): array
    {
        return array(
            'user_id'      => '',
            'username'     => '',
            'account_id'   => '',
            'account_name' => '',
        );
    }

    /** @return array<string, mixed> */
    private static function empty_error(): array
    {
        return array(
            'code'        => '',
            'http_status' => 0,
            'retry_after' => 0,
        );
    }

    /** @return array<string, mixed> */
    private static function error(string $code, int $http_status, int $retry_after): array
    {
        return array(
            'code'        => $code,
            'http_status' => $http_status,
            'retry_after' => $retry_after,
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
        return is_string($value) && 1 === preg_match('/^connection_[a-f0-9]{32}$/D', $value)
            ? $value
            : '';
    }

    private static function provisioning_id(mixed $value): string
    {
        return is_string($value) && 1 === preg_match('/^provision_[a-f0-9]{32}$/D', $value)
            ? $value
            : '';
    }

    private static function secret_ref(mixed $value): string
    {
        return is_string($value) && 1 === preg_match('/^managed_[a-f0-9]{32}$/D', $value)
            ? $value
            : '';
    }

    private static function attempt_id(mixed $value): string
    {
        return is_string($value) && 1 === preg_match('/^attempt_[a-f0-9]{32}$/D', $value)
            ? $value
            : '';
    }

    private static function attempt_capability(mixed $value): string
    {
        return is_string($value) && 1 === preg_match('/^[a-f0-9]{64}$/D', $value)
            ? $value
            : '';
    }

    private static function attempt_commitment(mixed $capability): string
    {
        $capability = self::attempt_capability($capability);
        if ('' === $capability) {
            return '';
        }

        return 'attempt_' . substr(hash('sha256', self::GRANT_ATTEMPT_DOMAIN . $capability), 0, 32);
    }

    private static function attempt_capability_matches(mixed $capability, string $commitment): bool
    {
        $derived = self::attempt_commitment($capability);
        return '' !== $derived && hash_equals($commitment, $derived);
    }

    private static function mutation_id(mixed $value): string
    {
        return is_string($value) && 1 === preg_match('/^mutation_[a-f0-9]{32}$/D', $value)
            ? $value
            : '';
    }

    private static function label(mixed $value): string
    {
        if (
            ! is_string($value)
            || '' === $value
            || trim($value) !== $value
            || strlen($value) > 480
            || 1 !== preg_match('//u', $value)
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)
            || str_contains($value, '<')
            || str_contains($value, '>')
        ) {
            return '';
        }

        $length = function_exists('mb_strlen') ? mb_strlen($value) : strlen($value);
        return $length <= 120 ? $value : '';
    }

    private static function machine_name(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= 50
            && 1 === preg_match('/^[a-z0-9_]+(?:[a-z0-9_.-]+[a-z0-9_]+)?$/D', $value);
    }

    private static function decimal_id(mixed $value): string
    {
        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return '';
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        return false !== $parsed && (string) $parsed === $value ? $value : '';
    }

    private static function is_sha256(string $value): bool
    {
        return 1 === preg_match('/^[a-f0-9]{64}$/D', $value);
    }

    private static function valid_http_status(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= 599;
    }

    private static function valid_retry_after(mixed $value): bool
    {
        return is_int($value) && $value >= 0 && $value <= self::MAX_RETRY_AFTER;
    }

    private static function contains_forbidden_key(mixed $value, int $depth = 0): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($depth > 8) {
            return true;
        }

        $forbidden = array(
            'password',
            'passwd',
            'otp',
            'token',
            'access_token',
            'refresh_token',
            'authorization',
            'client_secret',
            'raw_response',
            'response_body',
            'request_body',
            'detail',
        );

        foreach ($value as $key => $item) {
            if (is_string($key) && in_array(strtolower($key), $forbidden, true)) {
                return true;
            }

            if (is_array($item) && self::contains_forbidden_key($item, $depth + 1)) {
                return true;
            }
        }

        return false;
    }

    private static function contains_reference(mixed $value, int $depth = 0): bool
    {
        if (! is_array($value)) {
            return false;
        }

        if ($depth > 8) {
            return true;
        }

        foreach (array_keys($value) as $key) {
            if (
                null !== ReflectionReference::fromArrayElement($value, $key)
                || self::contains_reference($value[$key], $depth + 1)
            ) {
                return true;
            }
        }

        return false;
    }
}

// EOF: includes/PeerTube_Connection_State_Machine.php
