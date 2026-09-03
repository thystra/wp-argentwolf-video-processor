<?php
/**
 * File: includes/PeerTube_Staged_Upload_State_Machine.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use ReflectionReference;

/**
 * Pure R42 state contract for one future PeerTube staged-source transfer.
 *
 * This checkpoint deliberately performs no HTTP and owns no remote mutation.
 * It establishes the durable fences a later uploader must obey before it is
 * allowed to add the first media-creation POST.
 */
final class PeerTube_Staged_Upload_State_Machine
{
    public const VERSION = 1;
    public const MAX_UPLOAD_ATTEMPTS = 8;

    public const PHASE_READY = 'ready';
    public const PHASE_UPLOAD_IN_FLIGHT = 'upload_in_flight';
    public const PHASE_RETRY_WAIT = 'retry_wait';
    public const PHASE_UPLOAD_INDETERMINATE = 'upload_indeterminate';
    public const PHASE_REMOTE_CREATED = 'remote_created';
    public const PHASE_REMOTE_COMMITTED = 'remote_committed';
    public const PHASE_PROCESSING = 'processing';
    public const PHASE_READY_VERIFIED = 'ready_verified';
    public const PHASE_CLEANUP_PENDING = 'cleanup_pending';
    public const PHASE_COMPLETE = 'complete';
    public const PHASE_FAILED = 'failed';

    public const EVENT_CLAIM_UPLOAD = 'claim_upload';
    public const EVENT_UPLOAD_RETRY_SAFE = 'upload_retry_safe';
    public const EVENT_RESUME_AFTER_WAIT = 'resume_after_wait';
    public const EVENT_UPLOAD_INDETERMINATE = 'upload_indeterminate';
    public const EVENT_REMOTE_CREATED = 'remote_created';
    public const EVENT_RECONCILE_REMOTE_FOUND = 'reconcile_remote_found';
    public const EVENT_COMMIT_REMOTE_ASSET = 'commit_remote_asset';
    public const EVENT_PROCESSING_OBSERVED = 'processing_observed';
    public const EVENT_READY_VERIFIED = 'ready_verified';
    public const EVENT_REMOTE_FAILED = 'remote_failed';
    public const EVENT_PLAN_SOURCE_CLEANUP = 'plan_source_cleanup';
    public const EVENT_CONFIRM_SOURCE_CLEANUP = 'confirm_source_cleanup';

    private const MAX_RECORD_BYTES = 16384;
    private const ATTEMPT_DOMAIN = 'awvp-peertube-staged-upload-attempt-v1:';
    private const INTENT_DOMAIN = 'awvp-peertube-staged-upload-intent-v1:';

    /**
     * @param array<string,mixed> $intent
     * @return array<string,mixed>|null
     */
    public static function create(array $intent, int $actor_id, int $now): ?array
    {
        if (
            self::contains_reference($intent)
            || self::contains_forbidden_key($intent)
            || ! self::has_exact_keys(
                $intent,
                array(
                    'operation_id',
                    'video_post_id',
                    'backend_id',
                    'origin',
                    'destination_id',
                    'source',
                )
            )
        ) {
            return null;
        }

        $operation_id = self::operation_id($intent['operation_id']);
        $video_post_id = self::positive_int($intent['video_post_id']);
        $backend_id = Backend_Identity::sanitize($intent['backend_id']);
        $origin = PeerTube_Origin::sanitize($intent['origin']);
        $destination_id = PeerTube_Connection_Input::destination_id($intent['destination_id']);
        $source = $intent['source'];

        if (
            '' === $operation_id
            || $video_post_id < 1
            || '' === $backend_id
            || 'local' === $backend_id
            || '' === $origin
            || $origin !== $intent['origin']
            || '' === $destination_id
            || ! PeerTube_Staged_Source_Identity::valid($source)
            || $actor_id < 1
            || $now < 1
        ) {
            return null;
        }

        $intent_sha256 = self::intent_sha256(
            $video_post_id,
            $backend_id,
            $origin,
            $destination_id,
            $source
        );
        if ('' === $intent_sha256) {
            return null;
        }

        $record = array(
            'version'              => self::VERSION,
            'operation_id'         => $operation_id,
            'record_revision'      => 1,
            'video_post_id'        => $video_post_id,
            'backend_id'           => $backend_id,
            'origin'               => $origin,
            'destination_id'       => $destination_id,
            'source'               => $source,
            'intent_sha256'        => $intent_sha256,
            'phase'                => self::PHASE_READY,
            'upload_attempt_no'    => 0,
            'upload_attempt_id'    => '',
            'upload_started_at'    => 0,
            'remote_identity'      => self::empty_remote_identity(),
            'remote_asset_id'      => 0,
            'accepted_at'          => 0,
            'verified_at'          => 0,
            'cleanup_requested_at' => 0,
            'last_error'           => self::empty_error(),
            'created_by'           => $actor_id,
            'created_at'           => $now,
            'updated_at'           => $now,
        );

        return self::valid($record) ? $record : null;
    }

    /**
     * @param array<string,mixed> $record
     * @param array<string,mixed> $payload
     * @return array<string,mixed>|null
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

        if (self::EVENT_CLAIM_UPLOAD === $event) {
            if (
                self::PHASE_READY !== $phase
                || ! self::has_exact_keys($payload, array('attempt_capability'))
                || $record['upload_attempt_no'] >= self::MAX_UPLOAD_ATTEMPTS
            ) {
                return null;
            }

            $commitment = self::attempt_commitment($payload['attempt_capability']);
            if ('' === $commitment || hash_equals($record['upload_attempt_id'], $commitment)) {
                return null;
            }

            $next['phase'] = self::PHASE_UPLOAD_IN_FLIGHT;
            $next['upload_attempt_no']++;
            $next['upload_attempt_id'] = $commitment;
            $next['upload_started_at'] = $now;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_UPLOAD_RETRY_SAFE === $event) {
            if (
                self::PHASE_UPLOAD_IN_FLIGHT !== $phase
                || ! self::has_exact_keys(
                    $payload,
                    array('attempt_capability', 'code', 'http_status', 'retry_after')
                )
                || ! self::attempt_capability_matches(
                    $payload['attempt_capability'],
                    $record['upload_attempt_id']
                )
                || ! self::valid_safe_retry_error(
                    $payload['code'],
                    $payload['http_status'],
                    $payload['retry_after']
                )
            ) {
                return null;
            }

            $next['phase'] = $payload['retry_after'] > 0
                ? self::PHASE_RETRY_WAIT
                : self::PHASE_READY;
            $next['upload_attempt_id'] = '';
            $next['upload_started_at'] = 0;
            $next['last_error'] = self::error(
                $payload['code'],
                $payload['http_status'],
                $payload['retry_after']
            );
        } elseif (self::EVENT_RESUME_AFTER_WAIT === $event) {
            if (
                self::PHASE_RETRY_WAIT !== $phase
                || [] !== $payload
                || $record['last_error']['retry_after'] < 1
                || $record['updated_at'] > PHP_INT_MAX - $record['last_error']['retry_after']
                || $now < $record['updated_at'] + $record['last_error']['retry_after']
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_READY;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_UPLOAD_INDETERMINATE === $event) {
            if (
                self::PHASE_UPLOAD_IN_FLIGHT !== $phase
                || ! self::has_exact_keys(
                    $payload,
                    array('attempt_capability', 'code', 'http_status')
                )
                || ! self::attempt_capability_matches(
                    $payload['attempt_capability'],
                    $record['upload_attempt_id']
                )
                || ! self::valid_indeterminate_error($payload['code'], $payload['http_status'])
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_UPLOAD_INDETERMINATE;
            $next['last_error'] = self::error($payload['code'], $payload['http_status'], 0);
        } elseif (self::EVENT_REMOTE_CREATED === $event) {
            if (
                self::PHASE_UPLOAD_IN_FLIGHT !== $phase
                || ! self::has_exact_keys($payload, array('attempt_capability', 'remote_identity'))
                || ! self::attempt_capability_matches(
                    $payload['attempt_capability'],
                    $record['upload_attempt_id']
                )
                || ! self::valid_remote_identity($payload['remote_identity'], false)
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_REMOTE_CREATED;
            $next['remote_identity'] = $payload['remote_identity'];
            $next['accepted_at'] = $now;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_RECONCILE_REMOTE_FOUND === $event) {
            if (
                self::PHASE_UPLOAD_INDETERMINATE !== $phase
                || ! self::has_exact_keys($payload, array('remote_identity'))
                || ! self::valid_remote_identity($payload['remote_identity'], false)
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_REMOTE_CREATED;
            $next['remote_identity'] = $payload['remote_identity'];
            $next['accepted_at'] = $now;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_COMMIT_REMOTE_ASSET === $event) {
            $remote_asset_id = self::positive_int($payload['remote_asset_id'] ?? null);
            if (
                self::PHASE_REMOTE_CREATED !== $phase
                || ! self::has_exact_keys($payload, array('remote_asset_id'))
                || $remote_asset_id < 1
            ) {
                return null;
            }

            $next['phase'] = self::PHASE_REMOTE_COMMITTED;
            $next['remote_asset_id'] = $remote_asset_id;
        } elseif (self::EVENT_PROCESSING_OBSERVED === $event) {
            if (self::PHASE_REMOTE_COMMITTED !== $phase || [] !== $payload) {
                return null;
            }
            $next['phase'] = self::PHASE_PROCESSING;
        } elseif (self::EVENT_READY_VERIFIED === $event) {
            if (
                ! in_array($phase, array(self::PHASE_REMOTE_COMMITTED, self::PHASE_PROCESSING), true)
                || [] !== $payload
            ) {
                return null;
            }
            $next['phase'] = self::PHASE_READY_VERIFIED;
            $next['verified_at'] = $now;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_REMOTE_FAILED === $event) {
            if (
                ! in_array($phase, array(self::PHASE_REMOTE_COMMITTED, self::PHASE_PROCESSING), true)
                || ! self::has_exact_keys($payload, array('code', 'http_status'))
                || ! self::valid_remote_failure($payload['code'], $payload['http_status'])
            ) {
                return null;
            }
            $next['phase'] = self::PHASE_FAILED;
            $next['last_error'] = self::error($payload['code'], $payload['http_status'], 0);
        } elseif (self::EVENT_PLAN_SOURCE_CLEANUP === $event) {
            if (self::PHASE_READY_VERIFIED !== $phase || [] !== $payload) {
                return null;
            }
            $next['phase'] = self::PHASE_CLEANUP_PENDING;
            $next['cleanup_requested_at'] = $now;
        } elseif (self::EVENT_CONFIRM_SOURCE_CLEANUP === $event) {
            if (self::PHASE_CLEANUP_PENDING !== $phase || [] !== $payload) {
                return null;
            }
            $next['phase'] = self::PHASE_COMPLETE;
        } else {
            return null;
        }

        $next['record_revision']++;
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
            || self::positive_int($record['video_post_id'] ?? null) < 1
            || '' === Backend_Identity::sanitize($record['backend_id'] ?? null)
            || 'local' === $record['backend_id']
            || '' === PeerTube_Origin::sanitize($record['origin'] ?? null)
            || PeerTube_Origin::sanitize($record['origin']) !== $record['origin']
            || '' === PeerTube_Connection_Input::destination_id($record['destination_id'] ?? null)
            || ! PeerTube_Staged_Source_Identity::valid($record['source'] ?? null)
            || ! self::is_sha256($record['intent_sha256'] ?? null)
            || ! is_string($record['phase'] ?? null)
            || ! in_array($record['phase'], self::phases(), true)
            || ! is_int($record['upload_attempt_no'] ?? null)
            || $record['upload_attempt_no'] < 0
            || $record['upload_attempt_no'] > self::MAX_UPLOAD_ATTEMPTS
            || ! is_string($record['upload_attempt_id'] ?? null)
            || ('' !== $record['upload_attempt_id'] && ! self::is_sha256($record['upload_attempt_id']))
            || ! is_int($record['upload_started_at'] ?? null)
            || $record['upload_started_at'] < 0
            || ! self::valid_remote_identity($record['remote_identity'] ?? null, true)
            || ! is_int($record['remote_asset_id'] ?? null)
            || $record['remote_asset_id'] < 0
            || ! is_int($record['accepted_at'] ?? null)
            || $record['accepted_at'] < 0
            || ! is_int($record['verified_at'] ?? null)
            || $record['verified_at'] < 0
            || ! is_int($record['cleanup_requested_at'] ?? null)
            || $record['cleanup_requested_at'] < 0
            || ! self::valid_error($record['last_error'] ?? null)
            || ! is_int($record['created_by'] ?? null)
            || $record['created_by'] < 1
            || ! is_int($record['created_at'] ?? null)
            || $record['created_at'] < 1
            || ! is_int($record['updated_at'] ?? null)
            || $record['updated_at'] < $record['created_at']
            || self::intent_sha256(
                $record['video_post_id'],
                $record['backend_id'],
                $record['origin'],
                $record['destination_id'],
                $record['source']
            ) !== $record['intent_sha256']
            || ! self::valid_timestamp($record['upload_started_at'], $record)
            || ! self::valid_timestamp($record['accepted_at'], $record)
            || ! self::valid_timestamp($record['verified_at'], $record)
            || ! self::valid_timestamp($record['cleanup_requested_at'], $record)
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
            'video_post_id',
            'backend_id',
            'origin',
            'destination_id',
            'source',
            'intent_sha256',
            'phase',
            'upload_attempt_no',
            'upload_attempt_id',
            'upload_started_at',
            'remote_identity',
            'remote_asset_id',
            'accepted_at',
            'verified_at',
            'cleanup_requested_at',
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
            self::PHASE_READY,
            self::PHASE_UPLOAD_IN_FLIGHT,
            self::PHASE_RETRY_WAIT,
            self::PHASE_UPLOAD_INDETERMINATE,
            self::PHASE_REMOTE_CREATED,
            self::PHASE_REMOTE_COMMITTED,
            self::PHASE_PROCESSING,
            self::PHASE_READY_VERIFIED,
            self::PHASE_CLEANUP_PENDING,
            self::PHASE_COMPLETE,
            self::PHASE_FAILED,
        );
    }

    /** @param array<string,mixed> $record */
    private static function valid_phase_state(array $record): bool
    {
        $phase = $record['phase'];
        $no_current_attempt = '' === $record['upload_attempt_id'] && 0 === $record['upload_started_at'];
        $has_current_attempt = '' !== $record['upload_attempt_id'] && $record['upload_started_at'] > 0;
        $no_remote = self::empty_remote_identity() === $record['remote_identity']
            && 0 === $record['remote_asset_id']
            && 0 === $record['accepted_at']
            && 0 === $record['verified_at']
            && 0 === $record['cleanup_requested_at'];
        $has_remote = self::empty_remote_identity() !== $record['remote_identity']
            && $record['accepted_at'] > 0;
        $no_error = self::empty_error() === $record['last_error'];

        if (self::PHASE_READY === $phase) {
            return $no_current_attempt
                && $no_remote
                && ($no_error || (
                    $record['upload_attempt_no'] > 0
                    && self::valid_safe_retry_record_error($record['last_error'], true)
                ));
        }

        if (self::PHASE_RETRY_WAIT === $phase) {
            return $record['upload_attempt_no'] > 0
                && $no_current_attempt
                && $no_remote
                && self::valid_safe_retry_record_error($record['last_error'], false);
        }

        if (self::PHASE_UPLOAD_IN_FLIGHT === $phase) {
            return $record['upload_attempt_no'] > 0
                && $has_current_attempt
                && $no_remote
                && $no_error;
        }

        if (self::PHASE_UPLOAD_INDETERMINATE === $phase) {
            return $record['upload_attempt_no'] > 0
                && $has_current_attempt
                && $no_remote
                && 'peertube.upload.indeterminate' === $record['last_error']['code']
                && 0 === $record['last_error']['retry_after'];
        }

        if (
            $record['upload_attempt_no'] < 1
            || ! $has_current_attempt
            || ! $has_remote
            || $record['accepted_at'] < $record['upload_started_at']
        ) {
            return false;
        }

        if (self::PHASE_REMOTE_CREATED === $phase) {
            return 0 === $record['remote_asset_id']
                && 0 === $record['verified_at']
                && 0 === $record['cleanup_requested_at']
                && $no_error;
        }

        if ($record['remote_asset_id'] < 1) {
            return false;
        }

        if (in_array($phase, array(self::PHASE_REMOTE_COMMITTED, self::PHASE_PROCESSING), true)) {
            return 0 === $record['verified_at']
                && 0 === $record['cleanup_requested_at']
                && $no_error;
        }

        if (self::PHASE_FAILED === $phase) {
            return 0 === $record['verified_at']
                && 0 === $record['cleanup_requested_at']
                && 'peertube.upload.remote_failed' === $record['last_error']['code'];
        }

        if ($record['verified_at'] < $record['accepted_at']) {
            return false;
        }

        if (self::PHASE_READY_VERIFIED === $phase) {
            return 0 === $record['cleanup_requested_at'] && $no_error;
        }

        if (in_array($phase, array(self::PHASE_CLEANUP_PENDING, self::PHASE_COMPLETE), true)) {
            return $record['cleanup_requested_at'] >= $record['verified_at'] && $no_error;
        }

        return false;
    }

    /** @param mixed $identity */
    private static function valid_remote_identity(mixed $identity, bool $allow_empty): bool
    {
        if (
            ! is_array($identity)
            || ! self::has_exact_keys($identity, array('id', 'uuid'))
            || ! is_string($identity['id'] ?? null)
            || ! is_string($identity['uuid'] ?? null)
        ) {
            return false;
        }

        if ($allow_empty && self::empty_remote_identity() === $identity) {
            return true;
        }

        return '' !== PeerTube_Connection_Input::destination_id($identity['id'])
            && 1 === preg_match(
                '/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D',
                $identity['uuid']
            );
    }

    /** @param mixed $error */
    private static function valid_error(mixed $error): bool
    {
        if (
            ! is_array($error)
            || ! self::has_exact_keys($error, array('code', 'http_status', 'retry_after'))
            || ! is_string($error['code'] ?? null)
            || ! is_int($error['http_status'] ?? null)
            || ! is_int($error['retry_after'] ?? null)
            || $error['http_status'] < 0
            || $error['http_status'] > 599
            || $error['retry_after'] < 0
            || $error['retry_after'] > 86400
        ) {
            return false;
        }

        if (self::empty_error() === $error) {
            return true;
        }

        return in_array(
            $error['code'],
            array(
                'peertube.upload.request_not_sent',
                'peertube.upload.rate_limited',
                'peertube.upload.source_changed',
                'peertube.upload.backend_unavailable',
                'peertube.upload.indeterminate',
                'peertube.upload.remote_failed',
            ),
            true
        );
    }

    private static function valid_safe_retry_error(mixed $code, mixed $http_status, mixed $retry_after): bool
    {
        if (! is_string($code) || ! is_int($http_status) || ! is_int($retry_after)) {
            return false;
        }
        if ($http_status < 0 || $http_status > 599 || $retry_after < 0 || $retry_after > 86400) {
            return false;
        }

        if ('peertube.upload.rate_limited' === $code) {
            return 429 === $http_status && $retry_after > 0;
        }

        return in_array(
            $code,
            array(
                'peertube.upload.request_not_sent',
                'peertube.upload.source_changed',
                'peertube.upload.backend_unavailable',
            ),
            true
        ) && 0 === $http_status && 0 === $retry_after;
    }

    private static function valid_indeterminate_error(mixed $code, mixed $http_status): bool
    {
        return 'peertube.upload.indeterminate' === $code
            && is_int($http_status)
            && $http_status >= 0
            && $http_status <= 599;
    }

    private static function valid_remote_failure(mixed $code, mixed $http_status): bool
    {
        return 'peertube.upload.remote_failed' === $code
            && is_int($http_status)
            && $http_status >= 0
            && $http_status <= 599;
    }

    /** @param array<string,mixed> $error */
    private static function valid_safe_retry_record_error(array $error, bool $allow_zero_retry): bool
    {
        if (! self::valid_error($error) || self::empty_error() === $error) {
            return false;
        }
        if ('peertube.upload.rate_limited' === $error['code']) {
            return ! $allow_zero_retry && 429 === $error['http_status'] && $error['retry_after'] > 0;
        }
        return $allow_zero_retry
            && self::valid_safe_retry_error(
                $error['code'],
                $error['http_status'],
                $error['retry_after']
            );
    }

    /** @return array{id:string,uuid:string} */
    private static function empty_remote_identity(): array
    {
        return array('id' => '', 'uuid' => '');
    }

    /** @return array{code:string,http_status:int,retry_after:int} */
    private static function empty_error(): array
    {
        return array('code' => '', 'http_status' => 0, 'retry_after' => 0);
    }

    /** @return array{code:string,http_status:int,retry_after:int} */
    private static function error(string $code, int $http_status, int $retry_after): array
    {
        return array(
            'code'        => $code,
            'http_status' => $http_status,
            'retry_after' => $retry_after,
        );
    }

    /** @param array<string,mixed> $source */
    private static function intent_sha256(
        int $video_post_id,
        string $backend_id,
        string $origin,
        string $destination_id,
        array $source
    ): string {
        if (! PeerTube_Staged_Source_Identity::valid($source)) {
            return '';
        }

        return hash(
            'sha256',
            self::INTENT_DOMAIN
            . $video_post_id . "\n"
            . $backend_id . "\n"
            . $origin . "\n"
            . $destination_id . "\n"
            . $source['kind'] . "\n"
            . $source['relative_path'] . "\n"
            . $source['sha256'] . "\n"
            . $source['bytes']
        );
    }

    private static function attempt_commitment(mixed $capability): string
    {
        return is_string($capability)
            && 1 === preg_match('/^[a-f0-9]{64}$/D', $capability)
                ? hash('sha256', self::ATTEMPT_DOMAIN . $capability)
                : '';
    }

    private static function attempt_capability_matches(mixed $capability, string $commitment): bool
    {
        $candidate = self::attempt_commitment($capability);
        return '' !== $candidate && '' !== $commitment && hash_equals($commitment, $candidate);
    }

    private static function operation_id(mixed $value): string
    {
        return is_string($value)
            && 1 === preg_match('/^upload_[a-f0-9]{32}$/D', $value)
                ? $value
                : '';
    }

    private static function positive_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        return 0;
    }

    private static function is_sha256(mixed $value): bool
    {
        return is_string($value) && 1 === preg_match('/^[a-f0-9]{64}$/D', $value);
    }

    /** @param array<string,mixed> $record */
    private static function valid_timestamp(int $value, array $record): bool
    {
        return 0 === $value || ($value >= $record['created_at'] && $value <= $record['updated_at']);
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

    private static function contains_forbidden_key(mixed $value, int $depth = 0): bool
    {
        if (! is_array($value) || $depth > 8) {
            return false;
        }
        $forbidden = array(
            'access_token', 'refresh_token', 'password', 'client_secret', 'secret',
            'authorization', 'cookie', 'nonce', 'otp', 'credential',
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
        if (! is_array($value) || $depth > 8) {
            return false;
        }
        foreach (array_keys($value) as $key) {
            if (ReflectionReference::fromArrayElement($value, $key) instanceof ReflectionReference) {
                return true;
            }
            if (self::contains_reference($value[$key], $depth + 1)) {
                return true;
            }
        }
        return false;
    }
}

// EOF: includes/PeerTube_Staged_Upload_State_Machine.php
