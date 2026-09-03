<?php
/**
 * File: includes/PeerTube_Staged_Upload_State_Machine.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use ReflectionReference;

/**
 * Durable staged-upload contract for one PeerTube resumable transfer.
 *
 * Every byte-bearing request is claimed before remote I/O. An uncertain
 * chunk never becomes retryable merely because another request is made;
 * a later explicit reconciliation must prove the server's received offset
 * or exact remote video identity first.
 */
final class PeerTube_Staged_Upload_State_Machine
{
    public const VERSION = 2;
    public const MAX_UPLOAD_ATTEMPTS = 65535;
    public const MAX_CHUNK_BYTES = 1048576;
    public const PRIVATE_PRIVACY = 3;

    public const REQUEST_NONE = '';
    public const REQUEST_INIT = 'init';
    public const REQUEST_CHUNK = 'chunk';

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
    public const EVENT_UPLOAD_SESSION_CREATED = 'upload_session_created';
    public const EVENT_UPLOAD_CHUNK_ACCEPTED = 'upload_chunk_accepted';
    public const EVENT_UPLOAD_RETRY_SAFE = 'upload_retry_safe';
    public const EVENT_RESUME_AFTER_WAIT = 'resume_after_wait';
    public const EVENT_UPLOAD_INDETERMINATE = 'upload_indeterminate';
    public const EVENT_RECONCILE_OFFSET = 'reconcile_offset';
    public const EVENT_REMOTE_CREATED = 'remote_created';
    public const EVENT_RECONCILE_REMOTE_FOUND = 'reconcile_remote_found';
    public const EVENT_COMMIT_REMOTE_ASSET = 'commit_remote_asset';
    public const EVENT_PROCESSING_OBSERVED = 'processing_observed';
    public const EVENT_RECONCILE_WAIT = 'reconcile_wait';
    public const EVENT_READY_VERIFIED = 'ready_verified';
    public const EVENT_REMOTE_FAILED = 'remote_failed';
    public const EVENT_REMOTE_MISSING = 'remote_missing';
    public const EVENT_PLAN_SOURCE_CLEANUP = 'plan_source_cleanup';
    public const EVENT_CONFIRM_SOURCE_CLEANUP = 'confirm_source_cleanup';

    private const MAX_RECORD_BYTES = 24576;
    private const ATTEMPT_DOMAIN = 'awvp-peertube-staged-upload-attempt-v2:';
    private const INTENT_DOMAIN = 'awvp-peertube-staged-upload-intent-v2:';

    /** @param array<string,mixed> $intent @return array<string,mixed>|null */
    public static function create(array $intent, int $actor_id, int $now): ?array
    {
        if (
            self::contains_reference($intent)
            || self::contains_forbidden_key($intent)
            || ! self::has_exact_keys($intent, array(
                'operation_id', 'video_post_id', 'backend_id', 'origin',
                'destination_id', 'source', 'upload',
            ))
        ) {
            return null;
        }

        $operation_id = self::operation_id($intent['operation_id']);
        $video_post_id = self::positive_int($intent['video_post_id']);
        $backend_id = Backend_Identity::sanitize($intent['backend_id']);
        $origin = PeerTube_Origin::sanitize($intent['origin']);
        $destination_id = PeerTube_Connection_Input::destination_id($intent['destination_id']);
        $source = $intent['source'];
        $upload = $intent['upload'];

        if (
            '' === $operation_id || $video_post_id < 1 || '' === $backend_id || 'local' === $backend_id
            || '' === $origin || $origin !== $intent['origin'] || '' === $destination_id
            || ! PeerTube_Staged_Source_Identity::valid($source) || ! self::valid_upload($upload, $source)
            || $actor_id < 1 || $now < 1
        ) {
            return null;
        }

        $intent_sha256 = self::intent_sha256($video_post_id, $backend_id, $origin, $destination_id, $source, $upload);
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
            'upload'               => $upload,
            'intent_sha256'        => $intent_sha256,
            'phase'                => self::PHASE_READY,
            'upload_attempt_no'    => 0,
            'upload_attempt_id'    => '',
            'upload_started_at'    => 0,
            'request_kind'         => self::REQUEST_NONE,
            'request_start'        => 0,
            'request_bytes'        => 0,
            'upload_session_id'    => '',
            'confirmed_bytes'      => 0,
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

    /** @param array<string,mixed> $record @param array<string,mixed> $payload @return array<string,mixed>|null */
    public static function apply(array $record, string $event, array $payload, int $now): ?array
    {
        if (! self::valid($record) || $now < 1 || $now < $record['updated_at']
            || $record['record_revision'] >= PHP_INT_MAX || self::contains_reference($payload)
            || self::contains_forbidden_key($payload)) {
            return null;
        }

        $next = $record;
        $phase = $record['phase'];

        if (self::EVENT_CLAIM_UPLOAD === $event) {
            if (self::PHASE_READY !== $phase
                || ! self::has_exact_keys($payload, array('attempt_capability', 'request_kind', 'request_start', 'request_bytes'))
                || $record['upload_attempt_no'] >= self::MAX_UPLOAD_ATTEMPTS) {
                return null;
            }
            $commitment = self::attempt_commitment($payload['attempt_capability']);
            if ('' === $commitment || ! self::valid_request_claim($record, $payload)) {
                return null;
            }
            $next['phase'] = self::PHASE_UPLOAD_IN_FLIGHT;
            $next['upload_attempt_no']++;
            $next['upload_attempt_id'] = $commitment;
            $next['upload_started_at'] = $now;
            $next['request_kind'] = $payload['request_kind'];
            $next['request_start'] = $payload['request_start'];
            $next['request_bytes'] = $payload['request_bytes'];
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_UPLOAD_SESSION_CREATED === $event) {
            $session_id = self::session_id($payload['session_id'] ?? null);
            if (self::PHASE_UPLOAD_IN_FLIGHT !== $phase || self::REQUEST_INIT !== $record['request_kind']
                || ! self::has_exact_keys($payload, array('attempt_capability', 'session_id'))
                || ! self::attempt_capability_matches($payload['attempt_capability'], $record['upload_attempt_id'])
                || '' === $session_id) {
                return null;
            }
            $next['phase'] = self::PHASE_READY;
            $next['upload_session_id'] = $session_id;
            self::clear_attempt($next);
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_UPLOAD_CHUNK_ACCEPTED === $event) {
            $confirmed = self::nonnegative_int($payload['confirmed_bytes'] ?? null);
            if (self::PHASE_UPLOAD_IN_FLIGHT !== $phase || self::REQUEST_CHUNK !== $record['request_kind']
                || ! self::has_exact_keys($payload, array('attempt_capability', 'confirmed_bytes'))
                || ! self::attempt_capability_matches($payload['attempt_capability'], $record['upload_attempt_id'])
                || null === $confirmed || $confirmed !== $record['request_start'] + $record['request_bytes']
                || $confirmed >= $record['source']['bytes']) {
                return null;
            }
            $next['phase'] = self::PHASE_READY;
            $next['confirmed_bytes'] = $confirmed;
            self::clear_attempt($next);
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_UPLOAD_RETRY_SAFE === $event) {
            if (self::PHASE_UPLOAD_IN_FLIGHT !== $phase
                || ! self::has_exact_keys($payload, array('attempt_capability', 'code', 'http_status', 'retry_after'))
                || ! self::attempt_capability_matches($payload['attempt_capability'], $record['upload_attempt_id'])
                || ! self::valid_safe_retry_error($payload['code'], $payload['http_status'], $payload['retry_after'], $record['request_kind'])) {
                return null;
            }
            $next['phase'] = $payload['retry_after'] > 0 ? self::PHASE_RETRY_WAIT : self::PHASE_READY;
            self::clear_attempt($next);
            $next['last_error'] = self::error($payload['code'], $payload['http_status'], $payload['retry_after']);
        } elseif (self::EVENT_RESUME_AFTER_WAIT === $event) {
            if (self::PHASE_RETRY_WAIT !== $phase || [] !== $payload || $record['last_error']['retry_after'] < 1
                || $record['updated_at'] > PHP_INT_MAX - $record['last_error']['retry_after']
                || $now < $record['updated_at'] + $record['last_error']['retry_after']) {
                return null;
            }
            $next['phase'] = self::PHASE_READY;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_UPLOAD_INDETERMINATE === $event) {
            if (self::PHASE_UPLOAD_IN_FLIGHT !== $phase
                || ! self::has_exact_keys($payload, array('attempt_capability', 'code', 'http_status'))
                || ! self::attempt_capability_matches($payload['attempt_capability'], $record['upload_attempt_id'])
                || ! self::valid_indeterminate_error($payload['code'], $payload['http_status'])) {
                return null;
            }
            $next['phase'] = self::PHASE_UPLOAD_INDETERMINATE;
            $next['last_error'] = self::error($payload['code'], $payload['http_status'], 0);
        } elseif (self::EVENT_RECONCILE_OFFSET === $event) {
            $confirmed = self::nonnegative_int($payload['confirmed_bytes'] ?? null);
            $request_end = $record['request_start'] + $record['request_bytes'];
            if (self::PHASE_UPLOAD_INDETERMINATE !== $phase || self::REQUEST_CHUNK !== $record['request_kind']
                || ! self::has_exact_keys($payload, array('confirmed_bytes')) || null === $confirmed
                || '' === $record['upload_session_id'] || $confirmed < $record['confirmed_bytes']
                || $confirmed > $request_end || $confirmed >= $record['source']['bytes']) {
                return null;
            }
            $next['phase'] = self::PHASE_READY;
            $next['confirmed_bytes'] = $confirmed;
            self::clear_attempt($next);
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_REMOTE_CREATED === $event) {
            if (self::PHASE_UPLOAD_IN_FLIGHT !== $phase || self::REQUEST_CHUNK !== $record['request_kind']
                || ! self::has_exact_keys($payload, array('attempt_capability', 'remote_identity'))
                || ! self::attempt_capability_matches($payload['attempt_capability'], $record['upload_attempt_id'])
                || $record['request_start'] + $record['request_bytes'] !== $record['source']['bytes']
                || ! self::valid_remote_identity($payload['remote_identity'], false)) {
                return null;
            }
            $next['phase'] = self::PHASE_REMOTE_CREATED;
            $next['confirmed_bytes'] = $record['source']['bytes'];
            $next['remote_identity'] = $payload['remote_identity'];
            $next['accepted_at'] = $now;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_RECONCILE_REMOTE_FOUND === $event) {
            if (self::PHASE_UPLOAD_INDETERMINATE !== $phase || self::REQUEST_CHUNK !== $record['request_kind']
                || $record['request_start'] + $record['request_bytes'] !== $record['source']['bytes']
                || ! self::has_exact_keys($payload, array('remote_identity'))
                || ! self::valid_remote_identity($payload['remote_identity'], false)) {
                return null;
            }
            $next['phase'] = self::PHASE_REMOTE_CREATED;
            $next['confirmed_bytes'] = $record['source']['bytes'];
            $next['remote_identity'] = $payload['remote_identity'];
            $next['accepted_at'] = $now;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_COMMIT_REMOTE_ASSET === $event) {
            $remote_asset_id = self::positive_int($payload['remote_asset_id'] ?? null);
            if (self::PHASE_REMOTE_CREATED !== $phase || ! self::has_exact_keys($payload, array('remote_asset_id')) || $remote_asset_id < 1) {
                return null;
            }
            $next['phase'] = self::PHASE_REMOTE_COMMITTED;
            $next['remote_asset_id'] = $remote_asset_id;
        } elseif (self::EVENT_PROCESSING_OBSERVED === $event) {
            $retry_after = self::positive_int($payload['retry_after'] ?? null);
            if (! in_array($phase, array(self::PHASE_REMOTE_COMMITTED, self::PHASE_PROCESSING), true)
                || ! self::has_exact_keys($payload, array('retry_after'))
                || $retry_after < 1 || $retry_after > 86400) return null;
            $next['phase'] = self::PHASE_PROCESSING;
            $next['last_error'] = self::error('peertube.remote.processing_wait', 0, $retry_after);
        } elseif (self::EVENT_RECONCILE_WAIT === $event) {
            $retry_after = self::positive_int($payload['retry_after'] ?? null);
            if (! in_array($phase, array(self::PHASE_REMOTE_COMMITTED, self::PHASE_PROCESSING), true)
                || ! self::has_exact_keys($payload, array('code', 'http_status', 'retry_after'))
                || 'peertube.remote.reconcile_wait' !== ($payload['code'] ?? null)
                || ! is_int($payload['http_status'] ?? null)
                || ! in_array($payload['http_status'], array(0, 429, 500, 502, 503, 504), true)
                || $retry_after < 1 || $retry_after > 86400) return null;
            $next['last_error'] = self::error($payload['code'], $payload['http_status'], $retry_after);
        } elseif (self::EVENT_READY_VERIFIED === $event) {
            if (! in_array($phase, array(self::PHASE_REMOTE_COMMITTED, self::PHASE_PROCESSING), true) || [] !== $payload) return null;
            $next['phase'] = self::PHASE_READY_VERIFIED;
            $next['verified_at'] = $now;
            $next['last_error'] = self::empty_error();
        } elseif (self::EVENT_REMOTE_FAILED === $event) {
            if (! in_array($phase, array(self::PHASE_REMOTE_COMMITTED, self::PHASE_PROCESSING), true)
                || ! self::has_exact_keys($payload, array('code', 'http_status'))
                || ! self::valid_remote_failure($payload['code'], $payload['http_status'])) return null;
            $next['phase'] = self::PHASE_FAILED;
            $next['last_error'] = self::error($payload['code'], $payload['http_status'], 0);
        } elseif (self::EVENT_REMOTE_MISSING === $event) {
            if (! in_array($phase, array(self::PHASE_REMOTE_COMMITTED, self::PHASE_PROCESSING), true)
                || ! self::has_exact_keys($payload, array('http_status'))
                || 404 !== ($payload['http_status'] ?? null)) return null;
            $next['phase'] = self::PHASE_FAILED;
            $next['last_error'] = self::error('peertube.remote.missing', 404, 0);
        } elseif (self::EVENT_PLAN_SOURCE_CLEANUP === $event) {
            if (self::PHASE_READY_VERIFIED !== $phase || [] !== $payload) return null;
            $next['phase'] = self::PHASE_CLEANUP_PENDING;
            $next['cleanup_requested_at'] = $now;
        } elseif (self::EVENT_CONFIRM_SOURCE_CLEANUP === $event) {
            if (self::PHASE_CLEANUP_PENDING !== $phase || [] !== $payload) return null;
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
        if (! is_array($record) || ! self::has_exact_keys($record, self::record_keys())) return false;
        if (self::contains_reference($record) || self::contains_forbidden_key($record)
            || self::VERSION !== ($record['version'] ?? null) || '' === self::operation_id($record['operation_id'] ?? null)
            || ! is_int($record['record_revision']) || $record['record_revision'] < 1
            || self::positive_int($record['video_post_id'] ?? null) < 1
            || '' === Backend_Identity::sanitize($record['backend_id'] ?? null) || 'local' === $record['backend_id']
            || '' === PeerTube_Origin::sanitize($record['origin'] ?? null) || PeerTube_Origin::sanitize($record['origin']) !== $record['origin']
            || '' === PeerTube_Connection_Input::destination_id($record['destination_id'] ?? null)
            || ! PeerTube_Staged_Source_Identity::valid($record['source'] ?? null)
            || ! self::valid_upload($record['upload'] ?? null, $record['source'])
            || ! self::is_sha256($record['intent_sha256'] ?? null)
            || ! is_string($record['phase'] ?? null) || ! in_array($record['phase'], self::phases(), true)
            || ! is_int($record['upload_attempt_no'] ?? null) || $record['upload_attempt_no'] < 0 || $record['upload_attempt_no'] > self::MAX_UPLOAD_ATTEMPTS
            || ! is_string($record['upload_attempt_id'] ?? null) || ('' !== $record['upload_attempt_id'] && ! self::is_sha256($record['upload_attempt_id']))
            || ! is_int($record['upload_started_at'] ?? null) || $record['upload_started_at'] < 0
            || ! is_string($record['request_kind'] ?? null) || ! in_array($record['request_kind'], array(self::REQUEST_NONE, self::REQUEST_INIT, self::REQUEST_CHUNK), true)
            || ! is_int($record['request_start'] ?? null) || $record['request_start'] < 0
            || ! is_int($record['request_bytes'] ?? null) || $record['request_bytes'] < 0 || $record['request_bytes'] > self::MAX_CHUNK_BYTES
            || ! is_string($record['upload_session_id'] ?? null) || ('' !== $record['upload_session_id'] && '' === self::session_id($record['upload_session_id']))
            || ! is_int($record['confirmed_bytes'] ?? null) || $record['confirmed_bytes'] < 0 || $record['confirmed_bytes'] > $record['source']['bytes']
            || ! self::valid_remote_identity($record['remote_identity'] ?? null, true)
            || ! is_int($record['remote_asset_id'] ?? null) || $record['remote_asset_id'] < 0
            || ! is_int($record['accepted_at'] ?? null) || $record['accepted_at'] < 0
            || ! is_int($record['verified_at'] ?? null) || $record['verified_at'] < 0
            || ! is_int($record['cleanup_requested_at'] ?? null) || $record['cleanup_requested_at'] < 0
            || ! self::valid_error($record['last_error'] ?? null)
            || ! is_int($record['created_by'] ?? null) || $record['created_by'] < 1
            || ! is_int($record['created_at'] ?? null) || $record['created_at'] < 1
            || ! is_int($record['updated_at'] ?? null) || $record['updated_at'] < $record['created_at']
            || self::intent_sha256($record['video_post_id'], $record['backend_id'], $record['origin'], $record['destination_id'], $record['source'], $record['upload']) !== $record['intent_sha256']
            || ! self::valid_timestamp($record['upload_started_at'], $record)
            || ! self::valid_timestamp($record['accepted_at'], $record)
            || ! self::valid_timestamp($record['verified_at'], $record)
            || ! self::valid_timestamp($record['cleanup_requested_at'], $record)
            || ! self::valid_phase_state($record)) {
            return false;
        }
        return strlen(serialize($record)) <= self::MAX_RECORD_BYTES;
    }

    /** @return list<string> */
    private static function record_keys(): array
    {
        return array('version','operation_id','record_revision','video_post_id','backend_id','origin','destination_id','source','upload','intent_sha256','phase','upload_attempt_no','upload_attempt_id','upload_started_at','request_kind','request_start','request_bytes','upload_session_id','confirmed_bytes','remote_identity','remote_asset_id','accepted_at','verified_at','cleanup_requested_at','last_error','created_by','created_at','updated_at');
    }

    /** @return list<string> */
    private static function phases(): array
    {
        return array(self::PHASE_READY,self::PHASE_UPLOAD_IN_FLIGHT,self::PHASE_RETRY_WAIT,self::PHASE_UPLOAD_INDETERMINATE,self::PHASE_REMOTE_CREATED,self::PHASE_REMOTE_COMMITTED,self::PHASE_PROCESSING,self::PHASE_READY_VERIFIED,self::PHASE_CLEANUP_PENDING,self::PHASE_COMPLETE,self::PHASE_FAILED);
    }

    /** @param array<string,mixed> $record */
    private static function valid_phase_state(array $record): bool
    {
        $phase = $record['phase'];
        $source_bytes = $record['source']['bytes'];
        $no_attempt = '' === $record['upload_attempt_id'] && 0 === $record['upload_started_at']
            && self::REQUEST_NONE === $record['request_kind'] && 0 === $record['request_start'] && 0 === $record['request_bytes'];
        $has_attempt = '' !== $record['upload_attempt_id'] && $record['upload_started_at'] > 0
            && in_array($record['request_kind'], array(self::REQUEST_INIT, self::REQUEST_CHUNK), true);
        $no_remote = self::empty_remote_identity() === $record['remote_identity'] && 0 === $record['remote_asset_id']
            && 0 === $record['accepted_at'] && 0 === $record['verified_at'] && 0 === $record['cleanup_requested_at'];
        $has_remote = self::empty_remote_identity() !== $record['remote_identity'] && $record['accepted_at'] > 0;
        $no_error = self::empty_error() === $record['last_error'];
        $session_state_ok = ('' === $record['upload_session_id'] && 0 === $record['confirmed_bytes'])
            || ('' !== $record['upload_session_id'] && $record['confirmed_bytes'] < $source_bytes);

        if (self::PHASE_READY === $phase) {
            return $no_attempt && $no_remote && $session_state_ok
                && ($no_error || ($record['upload_attempt_no'] > 0 && self::valid_safe_retry_record_error($record['last_error'], true)));
        }
        if (self::PHASE_RETRY_WAIT === $phase) {
            return $record['upload_attempt_no'] > 0 && $no_attempt && $no_remote && $session_state_ok
                && self::valid_safe_retry_record_error($record['last_error'], false);
        }
        if (in_array($phase, array(self::PHASE_UPLOAD_IN_FLIGHT, self::PHASE_UPLOAD_INDETERMINATE), true)) {
            if ($record['upload_attempt_no'] < 1 || ! $has_attempt || ! $no_remote) return false;
            if (self::REQUEST_INIT === $record['request_kind']) {
                $request_ok = '' === $record['upload_session_id'] && 0 === $record['confirmed_bytes'] && 0 === $record['request_start'] && 0 === $record['request_bytes'];
            } else {
                $request_ok = '' !== $record['upload_session_id'] && $record['request_start'] === $record['confirmed_bytes']
                    && $record['request_bytes'] > 0 && $record['request_start'] + $record['request_bytes'] <= $source_bytes;
            }
            if (! $request_ok) return false;
            return self::PHASE_UPLOAD_IN_FLIGHT === $phase
                ? $no_error
                : ('peertube.upload.indeterminate' === $record['last_error']['code'] && 0 === $record['last_error']['retry_after']);
        }

        if ($record['upload_attempt_no'] < 1 || ! $has_attempt || ! $has_remote
            || '' === $record['upload_session_id'] || $record['confirmed_bytes'] !== $source_bytes
            || self::REQUEST_CHUNK !== $record['request_kind']
            || $record['request_start'] + $record['request_bytes'] !== $source_bytes
            || $record['accepted_at'] < $record['upload_started_at']) return false;

        if (self::PHASE_REMOTE_CREATED === $phase) return 0 === $record['remote_asset_id'] && 0 === $record['verified_at'] && 0 === $record['cleanup_requested_at'] && $no_error;
        if ($record['remote_asset_id'] < 1) return false;
        if (self::PHASE_REMOTE_COMMITTED === $phase) {
            return 0 === $record['verified_at'] && 0 === $record['cleanup_requested_at']
                && ($no_error || self::valid_remote_wait_record_error($record['last_error'], false));
        }
        if (self::PHASE_PROCESSING === $phase) {
            return 0 === $record['verified_at'] && 0 === $record['cleanup_requested_at']
                && ($no_error || self::valid_remote_wait_record_error($record['last_error'], true));
        }
        if (self::PHASE_FAILED === $phase) {
            return 0 === $record['verified_at'] && 0 === $record['cleanup_requested_at']
                && in_array($record['last_error']['code'], array('peertube.upload.remote_failed','peertube.remote.processing_failed','peertube.remote.missing'), true);
        }
        if ($record['verified_at'] < $record['accepted_at']) return false;
        if (self::PHASE_READY_VERIFIED === $phase) return 0 === $record['cleanup_requested_at'] && $no_error;
        if (in_array($phase, array(self::PHASE_CLEANUP_PENDING,self::PHASE_COMPLETE), true)) return $record['cleanup_requested_at'] >= $record['verified_at'] && $no_error;
        return false;
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $payload */
    private static function valid_request_claim(array $record, array $payload): bool
    {
        if (! is_string($payload['request_kind']) || ! is_int($payload['request_start']) || ! is_int($payload['request_bytes'])) return false;
        if (self::REQUEST_INIT === $payload['request_kind']) {
            return '' === $record['upload_session_id'] && 0 === $record['confirmed_bytes'] && 0 === $payload['request_start'] && 0 === $payload['request_bytes'];
        }
        if (self::REQUEST_CHUNK !== $payload['request_kind'] || '' === $record['upload_session_id']) return false;
        $remaining = $record['source']['bytes'] - $record['confirmed_bytes'];
        $expected = min(self::MAX_CHUNK_BYTES, $remaining);
        return $remaining > 0 && $payload['request_start'] === $record['confirmed_bytes'] && $payload['request_bytes'] === $expected;
    }

    /** @param array<string,mixed> $record */
    private static function clear_attempt(array &$record): void
    {
        $record['upload_attempt_id'] = '';
        $record['upload_started_at'] = 0;
        $record['request_kind'] = self::REQUEST_NONE;
        $record['request_start'] = 0;
        $record['request_bytes'] = 0;
    }

    /** @param mixed $upload @param array<string,mixed> $source */
    private static function valid_upload(mixed $upload, array $source): bool
    {
        if (! is_array($upload) || ! self::has_exact_keys($upload, array('filename','content_type','name','privacy'))
            || ! is_string($upload['filename']) || ! is_string($upload['content_type']) || ! is_string($upload['name'])
            || self::PRIVATE_PRIVACY !== $upload['privacy'] || 'video/mp4' !== $upload['content_type']) return false;
        $filename = $upload['filename'];
        if ('' === $filename || strlen($filename) > 255 || basename($filename) !== $filename
            || str_contains($filename, '/') || str_contains($filename, '\\') || 1 === preg_match('/[\x00-\x1F\x7F]/', $filename)
            || basename($source['relative_path']) !== $filename) return false;
        if ('' === $upload['name'] || strlen($upload['name']) > 1024 || trim($upload['name']) !== $upload['name']
            || 1 !== preg_match('//u', $upload['name']) || 1 === preg_match('/[\x00-\x1F\x7F]/', $upload['name'])) return false;
        $chars = array();
        $length = preg_match_all('/./us', $upload['name'], $chars);
        return is_int($length) && $length <= 120;
    }

    /** @param mixed $identity */
    private static function valid_remote_identity(mixed $identity, bool $allow_empty): bool
    {
        if (! is_array($identity) || ! self::has_exact_keys($identity, array('id','uuid')) || ! is_string($identity['id'] ?? null) || ! is_string($identity['uuid'] ?? null)) return false;
        if ($allow_empty && self::empty_remote_identity() === $identity) return true;
        return '' !== PeerTube_Connection_Input::destination_id($identity['id'])
            && 1 === preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $identity['uuid']);
    }

    /** @param mixed $error */
    private static function valid_error(mixed $error): bool
    {
        if (! is_array($error) || ! self::has_exact_keys($error, array('code','http_status','retry_after'))
            || ! is_string($error['code'] ?? null) || ! is_int($error['http_status'] ?? null) || ! is_int($error['retry_after'] ?? null)
            || $error['http_status'] < 0 || $error['http_status'] > 599 || $error['retry_after'] < 0 || $error['retry_after'] > 86400) return false;
        if (self::empty_error() === $error) return true;
        return in_array($error['code'], array('peertube.upload.request_not_sent','peertube.upload.rate_limited','peertube.upload.source_changed','peertube.upload.backend_unavailable','peertube.upload.refresh_required','peertube.upload.indeterminate','peertube.upload.remote_failed','peertube.remote.processing_wait','peertube.remote.reconcile_wait','peertube.remote.processing_failed','peertube.remote.missing'), true);
    }

    private static function valid_safe_retry_error(mixed $code, mixed $http_status, mixed $retry_after, string $request_kind): bool
    {
        if (! is_string($code) || ! is_int($http_status) || ! is_int($retry_after) || $http_status < 0 || $http_status > 599 || $retry_after < 0 || $retry_after > 86400) return false;
        if ('peertube.upload.rate_limited' === $code) return self::REQUEST_INIT === $request_kind && 429 === $http_status && $retry_after > 0;
        if ('peertube.upload.refresh_required' === $code) {
            return in_array($http_status, array(0, 401, 403), true) && 0 === $retry_after;
        }
        return in_array($code, array('peertube.upload.request_not_sent','peertube.upload.source_changed','peertube.upload.backend_unavailable'), true) && 0 === $http_status && 0 === $retry_after;
    }

    private static function valid_safe_retry_record_error(array $error, bool $allow_zero_retry): bool
    {
        if (! self::valid_error($error) || self::empty_error() === $error) return false;
        if ('peertube.upload.rate_limited' === $error['code']) return ! $allow_zero_retry && 429 === $error['http_status'] && $error['retry_after'] > 0;
        if ($allow_zero_retry && 'peertube.upload.refresh_required' === $error['code']) {
            return in_array($error['http_status'], array(0, 401, 403), true) && 0 === $error['retry_after'];
        }
        return $allow_zero_retry && 0 === $error['http_status'] && 0 === $error['retry_after']
            && in_array($error['code'], array('peertube.upload.request_not_sent','peertube.upload.source_changed','peertube.upload.backend_unavailable'), true);
    }

    /** @param array{code:string,http_status:int,retry_after:int} $error */
    private static function valid_remote_wait_record_error(array $error, bool $allow_processing): bool
    {
        if (! self::valid_error($error) || $error['retry_after'] < 1) return false;
        if ($allow_processing && 'peertube.remote.processing_wait' === $error['code']) {
            return 0 === $error['http_status'];
        }
        return 'peertube.remote.reconcile_wait' === $error['code']
            && in_array($error['http_status'], array(0,429,500,502,503,504), true);
    }

    private static function valid_indeterminate_error(mixed $code, mixed $http_status): bool { return 'peertube.upload.indeterminate' === $code && is_int($http_status) && $http_status >= 0 && $http_status <= 599; }
    private static function valid_remote_failure(mixed $code, mixed $http_status): bool { return in_array($code, array('peertube.upload.remote_failed','peertube.remote.processing_failed'), true) && is_int($http_status) && $http_status >= 0 && $http_status <= 599; }

    /** @return array{id:string,uuid:string} */
    private static function empty_remote_identity(): array { return array('id'=>'','uuid'=>''); }
    /** @return array{code:string,http_status:int,retry_after:int} */
    private static function empty_error(): array { return array('code'=>'','http_status'=>0,'retry_after'=>0); }
    /** @return array{code:string,http_status:int,retry_after:int} */
    private static function error(string $code, int $status, int $retry): array { return array('code'=>$code,'http_status'=>$status,'retry_after'=>$retry); }

    /** @param array<string,mixed> $source @param array<string,mixed> $upload */
    private static function intent_sha256(int $video_post_id, string $backend_id, string $origin, string $destination_id, array $source, array $upload): string
    {
        if (! PeerTube_Staged_Source_Identity::valid($source) || ! self::valid_upload($upload, $source)) return '';
        return hash('sha256', self::INTENT_DOMAIN.$video_post_id."\n".$backend_id."\n".$origin."\n".$destination_id."\n".$source['kind']."\n".$source['relative_path']."\n".$source['sha256']."\n".$source['bytes']."\n".$upload['filename']."\n".$upload['content_type']."\n".$upload['name']."\n".$upload['privacy']);
    }

    private static function attempt_commitment(mixed $capability): string { return is_string($capability) && 1 === preg_match('/^[a-f0-9]{64}$/D', $capability) ? hash('sha256', self::ATTEMPT_DOMAIN.$capability) : ''; }
    private static function attempt_capability_matches(mixed $capability, string $commitment): bool { $candidate=self::attempt_commitment($capability); return ''!==$candidate && ''!==$commitment && hash_equals($commitment,$candidate); }
    private static function operation_id(mixed $value): string { return is_string($value) && 1===preg_match('/^upload_[a-f0-9]{32}$/D',$value)?$value:''; }
    private static function session_id(mixed $value): string { return is_string($value) && 1===preg_match('/^[A-Za-z0-9._~-]{1,191}$/D',$value)?$value:''; }
    private static function positive_int(mixed $value): int { return is_int($value)&&$value>0?$value:0; }
    private static function nonnegative_int(mixed $value): ?int { return is_int($value)&&$value>=0?$value:null; }
    private static function is_sha256(mixed $value): bool { return is_string($value)&&1===preg_match('/^[a-f0-9]{64}$/D',$value); }
    /** @param array<string,mixed> $record */ private static function valid_timestamp(int $value,array $record):bool{return 0===$value||($value>=$record['created_at']&&$value<=$record['updated_at']);}

    /** @param list<string> $expected */
    private static function has_exact_keys(array $value,array $expected):bool{if(count($value)!==count($expected))return false;foreach($expected as $key){if(!array_key_exists($key,$value))return false;}foreach(array_keys($value) as $key){if(!is_string($key)||!in_array($key,$expected,true))return false;}return true;}
    private static function contains_forbidden_key(mixed $value,int $depth=0):bool{if(!is_array($value)||$depth>8)return false;$forbidden=array('access_token','refresh_token','password','client_secret','secret','authorization','cookie','nonce','otp','credential');foreach($value as $key=>$item){if(is_string($key)&&in_array(strtolower($key),$forbidden,true))return true;if(is_array($item)&&self::contains_forbidden_key($item,$depth+1))return true;}return false;}
    private static function contains_reference(mixed $value,int $depth=0):bool{if(!is_array($value)||$depth>8)return false;foreach(array_keys($value) as $key){if(ReflectionReference::fromArrayElement($value,$key) instanceof ReflectionReference)return true;if(self::contains_reference($value[$key],$depth+1))return true;}return false;}
}

// EOF: includes/PeerTube_Staged_Upload_State_Machine.php
