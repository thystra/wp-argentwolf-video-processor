<?php
/**
 * File: includes/PeerTube_Identity_Destination_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use Throwable;

/**
 * Restart-safe authenticated identity and owned-destination orchestration.
 *
 * The service reads only the exact generation-one managed credential already
 * committed by the password-grant tranche. It performs read-only PeerTube
 * requests, persists only the state machine's bounded non-secret identity,
 * and leaves the backend descriptor disabled. Destination lists are returned
 * ephemerally and are re-read before a selection is journaled.
 */
final class PeerTube_Identity_Destination_Service
{
    public const STATUS_ADVANCED = 'advanced';
    public const STATUS_DESTINATIONS_READY = 'destinations_ready';
    public const STATUS_NO_DESTINATIONS = 'no_destinations';
    public const STATUS_DESTINATION_UNAVAILABLE = 'destination_unavailable';
    public const STATUS_VERIFICATION_FAILED = 'verification_failed';
    public const STATUS_AWAITING_DESTINATION = 'awaiting_destination';
    public const STATUS_ACTIVATION_READY = 'activation_ready';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_INDETERMINATE = 'indeterminate';
    public const STATUS_REFUSED = 'refused';
    public const STATUS_OUTSIDE_SCOPE = 'outside_scope';

    private const TOKEN_SKEW_SECONDS = 60;
    private const MAX_DESTINATIONS = 500;
    private const MAX_TEXT_BYTES = 1024;

    private PeerTube_Connection_Operation_Store $operations;
    private Managed_Backend_Secret_Store $secrets;
    private Backend_Registry $registry;

    /** @var Closure(string):PeerTube_Identity_Destination_Api */
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
            ? static fn (string $origin): PeerTube_Identity_Destination_Api =>
                new PeerTube_Api_Client(new PeerTube_Http_Client($origin))
            : Closure::fromCallable($api_factory);
        $this->clock = null === $clock
            ? static fn (int $minimum): int => max($minimum, time())
            : Closure::fromCallable($clock);
    }

    /**
     * Advance one explicit verification step.
     *
     * The first call journals `verification_in_flight` without HTTP. A later
     * call in that phase performs one bounded identity/channel read and then
     * journals either the reviewed identity or a bounded failure.
     *
     * @return array<string, mixed>
     */
    public function advance(string $operation_id, int $now): array
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

            if (in_array(
                $record['phase'],
                array(
                    PeerTube_Connection_State_Machine::PHASE_SECRET_STORED,
                    PeerTube_Connection_State_Machine::PHASE_VERIFICATION_FAILED,
                ),
                true
            )) {
                if (! self::retry_available($record, $now)) {
                    return self::projection(self::STATUS_VERIFICATION_FAILED, $record);
                }
                $prerequisite = $this->prerequisite_probe($record);
                if (Atomic_Option_Store::PROBE_AFTER !== $prerequisite) {
                    return self::from_probe($prerequisite, $record);
                }

                return $this->persist_event(
                    $record,
                    PeerTube_Connection_State_Machine::EVENT_BEGIN_VERIFICATION,
                    array(),
                    $now,
                    self::STATUS_ADVANCED
                )['projection'];
            }

            if (PeerTube_Connection_State_Machine::PHASE_VERIFICATION_IN_FLIGHT === $record['phase']) {
                return $this->verify_in_flight($record, $now);
            }

            return match ($record['phase']) {
                PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION =>
                    self::projection(self::STATUS_AWAITING_DESTINATION, $record),
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY =>
                    self::projection(self::STATUS_ACTIVATION_READY, $record),
                default => self::projection(self::STATUS_OUTSIDE_SCOPE, $record),
            };
        } catch (Throwable) {
            return self::projection(self::STATUS_INDETERMINATE, null, $operation_id);
        }
    }

    /**
     * Explicit read-only refresh for the destination chooser.
     *
     * @return array<string, mixed>
     */
    public function discover(string $operation_id, int $now): array
    {
        try {
            if ($now < 1) {
                return self::discovery_projection(self::STATUS_REFUSED, null, $operation_id);
            }

            $probed = $this->probe_operation($operation_id);
            if (self::STATUS_ADVANCED !== $probed['status']) {
                return self::discovery_projection($probed['status'], null, $operation_id);
            }
            $record = $probed['record'];
            if (
                $now < $record['updated_at']
                || PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION !== $record['phase']
            ) {
                return self::discovery_projection(self::STATUS_OUTSIDE_SCOPE, $record);
            }

            $remote = $this->remote_discovery($record, $now);
            if (self::STATUS_DESTINATIONS_READY !== $remote['status']) {
                return self::discovery_projection(
                    $remote['status'],
                    $remote['record'],
                    '',
                    $remote['retry_after']
                );
            }

            $status = array() === $remote['destinations']
                ? self::STATUS_NO_DESTINATIONS
                : self::STATUS_DESTINATIONS_READY;
            return self::discovery_projection(
                $status,
                $remote['record'],
                '',
                0,
                $remote['identity'],
                $remote['destinations']
            );
        } catch (Throwable) {
            return self::discovery_projection(self::STATUS_INDETERMINATE, null, $operation_id);
        }
    }

    /**
     * Re-read current remote authority before journaling one exact selection.
     * The state machine deliberately clears the earlier identity so a later
     * explicit verification must bind the selection to fresh evidence.
     *
     * @return array<string, mixed>
     */
    public function select(
        string $operation_id,
        string $destination_id,
        int $actor_id,
        int $now
    ): array {
        try {
            $destination_id = PeerTube_Connection_Input::destination_id($destination_id);
            if ('' === $destination_id || $actor_id < 1 || $now < 1) {
                return self::projection(self::STATUS_REFUSED, null, $operation_id);
            }

            $probed = $this->probe_operation($operation_id);
            if (self::STATUS_ADVANCED !== $probed['status']) {
                return $probed['projection'];
            }
            $record = $probed['record'];
            if (
                $now < $record['updated_at']
                || PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION !== $record['phase']
            ) {
                return self::projection(self::STATUS_OUTSIDE_SCOPE, $record);
            }

            $remote = $this->remote_discovery($record, $now);
            if (self::STATUS_DESTINATIONS_READY !== $remote['status']) {
                return self::projection(
                    $remote['status'],
                    $remote['record'],
                    '',
                    Atomic_Option_Result::MUTATION_NONE,
                    $remote['retry_after']
                );
            }

            $authorized = false;
            foreach ($remote['destinations'] as $destination) {
                if ($destination_id === $destination['id']) {
                    $authorized = true;
                    break;
                }
            }
            if (! $authorized) {
                return self::projection(self::STATUS_DESTINATION_UNAVAILABLE, $remote['record']);
            }

            return $this->persist_event(
                $remote['record'],
                PeerTube_Connection_State_Machine::EVENT_SELECT_DESTINATION,
                array('destination_id' => $destination_id, 'actor_id' => $actor_id),
                $remote['observed_at'],
                self::STATUS_ADVANCED
            )['projection'];
        } catch (Throwable) {
            return self::projection(self::STATUS_INDETERMINATE, null, $operation_id);
        }
    }

    /** @param array<string, mixed> $record */
    private function verify_in_flight(array $record, int $now): array
    {
        $remote = $this->remote_discovery($record, $now);
        if (self::STATUS_DESTINATIONS_READY !== $remote['status']) {
            if (is_array($remote['failure'])) {
                return $this->persist_verification_failure(
                    $remote['record'],
                    $remote['failure'],
                    $remote['observed_at']
                );
            }

            return self::projection(
                $remote['status'],
                $remote['record'],
                '',
                Atomic_Option_Result::MUTATION_NONE,
                $remote['retry_after']
            );
        }

        if (array() === $remote['destinations']) {
            return $this->persist_verification_failure(
                $remote['record'],
                array('reason' => 'channels_none', 'http_status' => 200, 'retry_after' => 0),
                $remote['observed_at']
            );
        }

        $selected = $remote['record']['selected_destination'];
        if ('' !== $selected) {
            $authorized = false;
            foreach ($remote['destinations'] as $destination) {
                if ($selected === $destination['id']) {
                    $authorized = true;
                    break;
                }
            }
            if (! $authorized) {
                return $this->persist_verification_failure(
                    $remote['record'],
                    array(
                        'reason'      => 'channel_unauthorized',
                        'http_status' => 200,
                        'retry_after' => 0,
                    ),
                    $remote['observed_at']
                );
            }
        }

        $transition = $this->persist_event(
            $remote['record'],
            PeerTube_Connection_State_Machine::EVENT_VERIFICATION_SUCCEEDED,
            array(
                'identity'          => $remote['identity'],
                'secret_generation' => $remote['record']['secret_generation'],
            ),
            $remote['observed_at'],
            '' === $selected ? self::STATUS_AWAITING_DESTINATION : self::STATUS_ACTIVATION_READY
        );
        return $transition['projection'];
    }

    /**
     * @param array<string, mixed> $record
     * @param array{reason:string,http_status:int,retry_after:int} $failure
     */
    private function persist_verification_failure(array $record, array $failure, int $now): array
    {
        return $this->persist_event(
            $record,
            PeerTube_Connection_State_Machine::EVENT_VERIFICATION_FAILED,
            $failure,
            $now,
            self::STATUS_VERIFICATION_FAILED
        )['projection'];
    }

    /**
     * Perform the one authenticated identity read and deterministic public
     * owned-channel sequence, then re-prove all local authority.
     *
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private function remote_discovery(array $record, int $now): array
    {
        $prerequisite = $this->prerequisite_probe($record);
        if (Atomic_Option_Store::PROBE_AFTER !== $prerequisite) {
            return self::remote_result(self::probe_status($prerequisite), $record, $now);
        }

        $secret = $this->secrets->read($record['secret_ref'], $record['backend_id']);
        if (! self::valid_secret($secret, $record['secret_generation'])) {
            return self::remote_result(self::STATUS_REFUSED, $record, $now);
        }

        if (! self::access_token_usable($secret['access_expires_at'], $now)) {
            unset($secret);
            return self::remote_result(
                self::STATUS_VERIFICATION_FAILED,
                $record,
                $now,
                array('reason' => 'authentication_required', 'http_status' => 401, 'retry_after' => 0),
                0
            );
        }

        try {
            $api = ($this->api_factory)($record['origin']);
        } catch (Throwable) {
            unset($secret);
            return self::remote_result(self::STATUS_REFUSED, $record, $now);
        }
        if (
            ! $api instanceof PeerTube_Identity_Destination_Api
            || $record['origin'] !== $api->origin()
        ) {
            unset($secret);
            return self::remote_result(self::STATUS_REFUSED, $record, $now);
        }

        try {
            $api_result = $api->owned_channels($secret['access_token']);
        } catch (Throwable) {
            $api_result = array(
                'ok'    => false,
                'data'  => null,
                'error' => array(
                    'status'      => 'transport_error',
                    'http_status' => 0,
                    'retry_after' => 0,
                ),
            );
        }
        unset($secret);
        $observed_at = $this->observed_time(max($now, $record['updated_at']));

        $fresh = $this->probe_operation($record['operation_id']);
        if (self::STATUS_ADVANCED !== $fresh['status']) {
            return self::remote_result($fresh['status'], $record, $observed_at);
        }
        if ($fresh['record'] !== $record) {
            return self::remote_result(self::STATUS_CONFLICT, $fresh['record'], $observed_at);
        }
        $record = $fresh['record'];
        $prerequisite = $this->prerequisite_probe($record);
        if (Atomic_Option_Store::PROBE_AFTER !== $prerequisite) {
            return self::remote_result(self::probe_status($prerequisite), $record, $observed_at);
        }

        $failure = self::api_failure($api_result);
        if (null !== $failure) {
            return self::remote_result(
                self::STATUS_VERIFICATION_FAILED,
                $record,
                $observed_at,
                $failure,
                $failure['retry_after']
            );
        }

        $discovery = self::normalized_discovery($api_result);
        if (null === $discovery) {
            return self::remote_result(
                self::STATUS_VERIFICATION_FAILED,
                $record,
                $observed_at,
                array('reason' => 'invalid_response', 'http_status' => 200, 'retry_after' => 0)
            );
        }

        return self::remote_result(
            self::STATUS_DESTINATIONS_READY,
            $record,
            $observed_at,
            null,
            0,
            $discovery['identity'],
            $discovery['destinations']
        );
    }

    /**
     * Persist and authoritatively classify one exact journal transition.
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
        string $confirmed_status
    ): array {
        $next = PeerTube_Connection_State_Machine::apply($record, $event, $payload, $now);
        if (null === $next) {
            return self::transition_result(
                false,
                $record,
                self::projection(self::STATUS_REFUSED, $record)
            );
        }

        try {
            $write = $this->operations->apply_event(
                $record['operation_id'],
                $record['record_revision'],
                $event,
                $payload,
                $now
            );
            $probe = $this->operations->probe($record['operation_id']);
        } catch (Throwable) {
            return self::transition_result(
                false,
                $record,
                self::projection(
                    self::STATUS_INDETERMINATE,
                    $record,
                    '',
                    Atomic_Option_Result::MUTATION_UNKNOWN
                )
            );
        }

        if (PeerTube_Connection_Operation_Store::PROBE_PRESENT === $probe['status']) {
            $current = is_array($probe['record']) ? $probe['record'] : $record;
            if ($next === $current && Atomic_Option_Result::APPLIED === $write->status()) {
                return $this->confirm_transition_prerequisites(self::transition_result(
                    true,
                    $current,
                    self::projection($confirmed_status, $current, '', $write->mutation())
                ));
            }

            if ($record === $current) {
                return self::transition_result(
                    false,
                    $current,
                    self::from_atomic($write, $current)
                );
            }

            return self::transition_result(
                false,
                $current,
                self::projection(
                    Atomic_Option_Result::INDETERMINATE === $write->status()
                    || Atomic_Option_Result::MUTATION_NONE !== $write->mutation()
                        ? self::STATUS_INDETERMINATE
                        : self::STATUS_CONFLICT,
                    $current,
                    '',
                    $write->mutation()
                )
            );
        }

        return self::transition_result(
            false,
            $record,
            self::projection(
                self::STATUS_INDETERMINATE,
                $record,
                '',
                $write->mutation()
            )
        );
    }

    /**
     * A journal write invokes WordPress option hooks. Once this service has
     * applied a transition, classify it as confirmed only if those hooks left
     * the exact managed-secret generation, disabled descriptor, and journal
     * record authoritative. The applied journal mutation cannot be described
     * as a clean conflict when a separate prerequisite changed.
     *
     * @param array{confirmed:bool,record:array<string,mixed>,projection:array<string,mixed>} $transition
     * @return array{confirmed:bool,record:array<string,mixed>,projection:array<string,mixed>}
     */
    private function confirm_transition_prerequisites(array $transition): array
    {
        $record = $transition['record'];
        try {
            $prerequisite = $this->prerequisite_probe($record);
            $fresh = $this->probe_operation($record['operation_id']);
        } catch (Throwable) {
            return self::transition_result(
                false,
                $record,
                self::projection(
                    self::STATUS_INDETERMINATE,
                    $record,
                    '',
                    $transition['projection']['mutation']
                )
            );
        }

        if (
            Atomic_Option_Store::PROBE_AFTER === $prerequisite
            && self::STATUS_ADVANCED === $fresh['status']
            && $record === $fresh['record']
        ) {
            return $transition;
        }

        $current = self::STATUS_ADVANCED === $fresh['status']
            && array() !== $fresh['record']
                ? $fresh['record']
                : $record;
        return self::transition_result(
            false,
            $current,
            self::projection(
                self::STATUS_INDETERMINATE,
                $current,
                '',
                $transition['projection']['mutation']
            )
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
    private function prerequisite_probe(array $record): string
    {
        $state = $this->secrets->provisioning_state(
            $record['secret_ref'],
            $record['backend_id'],
            $record['provisioning_id']
        );
        $secret_probe = match ($state['state']) {
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

    /** @param array<string, mixed>|null $secret */
    private static function valid_secret(?array $secret, int $generation): bool
    {
        return is_array($secret)
            && array(
                'access_token',
                'refresh_token',
                'access_expires_at',
                'refresh_expires_at',
                'generation',
            ) === array_keys($secret)
            && is_string($secret['access_token'])
            && '' !== $secret['access_token']
            && strlen($secret['access_token']) <= 16384
            && 1 === preg_match('//u', $secret['access_token'])
            && 0 === preg_match('/[\x00-\x1F\x7F]/', $secret['access_token'])
            && 0 === preg_match('/\s/u', $secret['access_token'])
            && is_int($secret['access_expires_at'])
            && is_int($secret['generation'])
            && $generation === $secret['generation'];
    }

    private static function access_token_usable(int $expires_at, int $now): bool
    {
        return $now <= PHP_INT_MAX - self::TOKEN_SKEW_SECONDS
            && $expires_at > $now + self::TOKEN_SKEW_SECONDS;
    }

    /** @param array<string, mixed> $record */
    private static function retry_available(array $record, int $now): bool
    {
        $retry_after = $record['last_error']['retry_after'];
        return $retry_after < 1
            || (
                $record['updated_at'] <= PHP_INT_MAX - $retry_after
                && $now >= $record['updated_at'] + $retry_after
            );
    }

    /**
     * @param array<string, mixed> $result
     * @return array{reason:string,http_status:int,retry_after:int}|null
     */
    private static function api_failure(array $result): ?array
    {
        if (true === ($result['ok'] ?? null)) {
            return null;
        }

        $error = is_array($result['error'] ?? null) ? $result['error'] : array();
        $status = is_string($error['status'] ?? null) ? $error['status'] : '';
        $http_status = is_int($error['http_status'] ?? null) ? $error['http_status'] : 0;
        $retry_after = is_int($error['retry_after'] ?? null)
            ? min(max($error['retry_after'], 0), 86400)
            : 0;

        if ('authentication_required' === $status && 401 === $http_status) {
            return array('reason' => $status, 'http_status' => 401, 'retry_after' => 0);
        }
        if ('permission_denied' === $status && 403 === $http_status) {
            return array('reason' => $status, 'http_status' => 403, 'retry_after' => 0);
        }
        if ('rate_limited' === $status && 429 === $http_status) {
            return array('reason' => $status, 'http_status' => 429, 'retry_after' => $retry_after);
        }
        if (in_array($status, array('transport_error', 'tls_error'), true) && 0 === $http_status) {
            return array('reason' => 'transport_error', 'http_status' => 0, 'retry_after' => 0);
        }
        if ('remote_error' === $status && $http_status >= 500 && $http_status <= 599) {
            return array('reason' => $status, 'http_status' => $http_status, 'retry_after' => 0);
        }

        return array(
            'reason'      => 'invalid_response',
            'http_status' => $http_status >= 200
                && $http_status <= 499
                && ! in_array($http_status, array(401, 403, 429), true)
                    ? $http_status
                    : 200,
            'retry_after' => 0,
        );
    }

    /**
     * @param array<string, mixed> $result
     * @return array{identity:array<string,string>,destinations:list<array<string,string>>}|null
     */
    private static function normalized_discovery(array $result): ?array
    {
        if (
            array('ok', 'data', 'error') !== array_keys($result)
            || true !== $result['ok']
            || null !== $result['error']
            || ! is_array($result['data'])
            || array('identity', 'channels') !== array_keys($result['data'])
        ) {
            return null;
        }

        $identity = $result['data']['identity'];
        if (! self::valid_identity($identity)) {
            return null;
        }

        $channels = $result['data']['channels'];
        if (! is_array($channels) || ! array_is_list($channels) || count($channels) > self::MAX_DESTINATIONS) {
            return null;
        }

        $destinations = array();
        $last_id = 0;
        foreach ($channels as $channel) {
            if (
                ! is_array($channel)
                || array('id', 'name', 'display_name', 'authority') !== array_keys($channel)
            ) {
                return null;
            }
            $id = PeerTube_Connection_Input::destination_id($channel['id']);
            $name = self::machine_name($channel['name']);
            $display_name = self::display_name($channel['display_name']);
            $numeric_id = '' !== $id ? (int) $id : 0;
            if (
                '' === $id
                || '' === $name
                || '' === $display_name
                || 'owned' !== $channel['authority']
                || $numeric_id <= $last_id
            ) {
                return null;
            }
            $last_id = $numeric_id;
            $destinations[] = array(
                'id'           => $id,
                'name'         => $name,
                'display_name' => $display_name,
                'authority'    => 'owned',
            );
        }

        return array('identity' => $identity, 'destinations' => $destinations);
    }

    private static function valid_identity(mixed $identity): bool
    {
        return is_array($identity)
            && array('user_id', 'username', 'account_id', 'account_name') === array_keys($identity)
            && '' !== PeerTube_Connection_Input::destination_id($identity['user_id'])
            && '' !== self::machine_name($identity['username'])
            && '' !== PeerTube_Connection_Input::destination_id($identity['account_id'])
            && '' !== self::machine_name($identity['account_name']);
    }

    private static function machine_name(mixed $value): string
    {
        return is_string($value)
            && strlen($value) <= 50
            && 1 === preg_match('/^[a-z0-9_]+(?:[a-z0-9_.-]+[a-z0-9_]+)?$/D', $value)
                ? $value
                : '';
    }

    private static function display_name(mixed $value): string
    {
        if (
            ! is_string($value)
            || '' === $value
            || trim($value) !== $value
            || strlen($value) > self::MAX_TEXT_BYTES
            || 1 !== preg_match('//u', $value)
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            return '';
        }

        $characters = preg_match_all('/./us', $value, $matches);
        return is_int($characters) && $characters <= 240 ? $value : '';
    }

    private function observed_time(int $minimum): int
    {
        try {
            $observed = ($this->clock)($minimum);
        } catch (Throwable) {
            return $minimum;
        }
        return is_int($observed) && $observed >= $minimum ? $observed : $minimum;
    }

    /** @param array<string, mixed>|null $record */
    private static function from_probe(string $probe, ?array $record): array
    {
        return self::projection(self::probe_status($probe), $record);
    }

    private static function probe_status(string $probe): string
    {
        return match ($probe) {
            Atomic_Option_Store::PROBE_INDETERMINATE => self::STATUS_INDETERMINATE,
            Atomic_Option_Store::PROBE_REFUSED => self::STATUS_REFUSED,
            default => self::STATUS_CONFLICT,
        };
    }

    /** @param array<string, mixed>|null $record */
    private static function from_atomic(Atomic_Option_Result $result, ?array $record): array
    {
        return self::projection(
            match ($result->status()) {
                Atomic_Option_Result::APPLIED => self::STATUS_ADVANCED,
                Atomic_Option_Result::CONFLICT => self::STATUS_CONFLICT,
                Atomic_Option_Result::INDETERMINATE => self::STATUS_INDETERMINATE,
                default => self::STATUS_REFUSED,
            },
            $record,
            '',
            $result->mutation()
        );
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $projection
     * @return array{confirmed:bool,record:array<string,mixed>,projection:array<string,mixed>}
     */
    private static function transition_result(bool $confirmed, array $record, array $projection): array
    {
        return array('confirmed' => $confirmed, 'record' => $record, 'projection' => $projection);
    }

    /**
     * @param array<string, mixed>|null $record
     * @return array<string, mixed>
     */
    private static function projection(
        string $status,
        ?array $record = null,
        string $operation_id = '',
        string $mutation = Atomic_Option_Result::MUTATION_NONE,
        ?int $retry_after = null
    ): array {
        $record_operation_id = is_array($record)
            ? PeerTube_Connection_Input::operation_id($record['operation_id'] ?? null)
            : '';
        $record_retry = is_array($record) && is_array($record['last_error'] ?? null)
            && is_int($record['last_error']['retry_after'] ?? null)
                ? min(max($record['last_error']['retry_after'], 0), 86400)
                : 0;

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
            'retry_after'     => null === $retry_after ? $record_retry : min(max($retry_after, 0), 86400),
        );
    }

    /**
     * @param array<string, mixed>|null $record
     * @param array<string, string> $identity
     * @param list<array<string, string>> $destinations
     * @return array<string, mixed>
     */
    private static function discovery_projection(
        string $status,
        ?array $record = null,
        string $operation_id = '',
        ?int $retry_after = null,
        array $identity = array(),
        array $destinations = array()
    ): array {
        return array_merge(
            self::projection(
                $status,
                $record,
                $operation_id,
                Atomic_Option_Result::MUTATION_NONE,
                $retry_after
            ),
            array('identity' => $identity, 'destinations' => $destinations)
        );
    }

    /**
     * @param array<string, mixed>|null $failure
     * @param array<string, string> $identity
     * @param list<array<string, string>> $destinations
     * @return array<string, mixed>
     */
    private static function remote_result(
        string $status,
        array $record,
        int $observed_at,
        ?array $failure = null,
        int $retry_after = 0,
        array $identity = array(),
        array $destinations = array()
    ): array {
        return array(
            'status'       => $status,
            'record'       => $record,
            'observed_at'  => $observed_at,
            'failure'      => $failure,
            'retry_after'  => min(max($retry_after, 0), 86400),
            'identity'     => $identity,
            'destinations' => $destinations,
        );
    }
}

// EOF: includes/PeerTube_Identity_Destination_Service.php
