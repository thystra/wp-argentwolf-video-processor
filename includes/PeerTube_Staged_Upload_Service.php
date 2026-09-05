<?php
/**
 * File: includes/PeerTube_Staged_Upload_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use Throwable;

/**
 * Explicit, restart-safe executor for the first PeerTube resumable-upload
 * mutation boundary. R45 may call advance() through its bounded one-shot task
 * worker; indeterminate byte-bearing work remains forbidden from auto-replay.
 */
final class PeerTube_Staged_Upload_Service
{
    public const STATUS_ADVANCED = 'advanced';
    public const STATUS_SESSION_CREATED = 'session_created';
    public const STATUS_CHUNK_ACCEPTED = 'chunk_accepted';
    public const STATUS_REMOTE_CREATED = 'remote_created';
    public const STATUS_WAIT = 'wait';
    public const STATUS_REFRESH_REQUIRED = 'refresh_required';
    public const STATUS_INDETERMINATE = 'indeterminate';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_REFUSED = 'refused';

    private const TOKEN_SKEW_SECONDS = 60;

    /** @var Closure(string):PeerTube_Staged_Upload_Api */
    private Closure $api_factory;
    /** @var Closure(string):int */
    private Closure $chunk_mib_resolver;

    public function __construct(
        private readonly PeerTube_Staged_Upload_Operation_Store $operations,
        private readonly Backend_Registry $registry,
        private readonly Managed_Backend_Secret_Store $secrets,
        callable $api_factory,
        ?callable $chunk_mib_resolver = null
    ) {
        $this->api_factory = Closure::fromCallable($api_factory);
        $this->chunk_mib_resolver = Closure::fromCallable(
            $chunk_mib_resolver ?? static fn (string $backend_id): int => PeerTube_Upload_Policy::DEFAULT_CHUNK_MIB
        );
    }

    /** @return array<string,mixed> */
    public function begin(
        int $video_post_id,
        string $backend_id,
        string $source_path,
        string $name,
        int $actor_id,
        int $now
    ): array {
        if ($video_post_id < 1 || $actor_id < 1 || $now < 1) {
            return self::result(self::STATUS_REFUSED);
        }
        $backend_id = Backend_Identity::sanitize($backend_id);
        $descriptor = '' === $backend_id ? null : $this->registry->get($backend_id);
        if (! self::active_descriptor($descriptor, $backend_id)) {
            return self::result(self::STATUS_REFUSED);
        }

        try {
            $source = PeerTube_Staged_Source_Identity::capture($source_path);
        } catch (Throwable) {
            $source = null;
        }
        if (! is_array($source)) {
            return self::result(self::STATUS_REFUSED);
        }

        $intent = array(
            'video_post_id'  => $video_post_id,
            'backend_id'     => $backend_id,
            'origin'         => $descriptor['config']['origin'],
            'destination_id' => $descriptor['default_destination'],
            'source'         => $source,
            'upload'         => array(
                'filename'     => basename($source['relative_path']),
                'content_type' => 'video/mp4',
                'name'         => $name,
                'privacy'      => PeerTube_Staged_Upload_State_Machine::PRIVATE_PRIVACY,
            ),
        );
        $begun = $this->operations->begin($intent, $actor_id, $now);
        $record = is_array($begun['record'] ?? null) ? $begun['record'] : null;
        $result = $begun['result'] ?? null;
        if (! $result instanceof Atomic_Option_Result || null === $record) {
            return self::result(self::STATUS_REFUSED);
        }
        if (Atomic_Option_Result::APPLIED === $result->status()) {
            return self::result(self::STATUS_ADVANCED, $record);
        }
        return self::result(
            Atomic_Option_Result::CONFLICT === $result->status() ? self::STATUS_CONFLICT : self::STATUS_REFUSED,
            $record
        );
    }

    /** @return array<string,mixed> */
    public function advance(string $operation_id, int $now): array
    {
        $record = $this->operations->get($operation_id);
        if (! is_array($record) || $now < 1) {
            return self::result(self::STATUS_REFUSED);
        }

        if (PeerTube_Staged_Upload_State_Machine::PHASE_RETRY_WAIT === $record['phase']) {
            $retry_after = is_int($record['last_error']['retry_after'] ?? null)
                ? $record['last_error']['retry_after']
                : 0;
            if ($retry_after < 1
                || $record['updated_at'] > PHP_INT_MAX - $retry_after) {
                return self::result(self::STATUS_REFUSED, $record);
            }
            if ($now < $record['updated_at'] + $retry_after) {
                return self::result(self::STATUS_WAIT, $record);
            }
            $applied = $this->operations->apply_event(
                $operation_id,
                $record['record_revision'],
                PeerTube_Staged_Upload_State_Machine::EVENT_RESUME_AFTER_WAIT,
                array(),
                $now
            );
            if (Atomic_Option_Result::APPLIED === $applied->status()) {
                return self::result(self::STATUS_ADVANCED, $this->operations->get($operation_id));
            }
            return self::atomic_failure($applied, $record);
        }

        if (PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE === $record['phase']) {
            return self::result(self::STATUS_INDETERMINATE, $record);
        }
        if (PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_CREATED === $record['phase']) {
            return self::result(self::STATUS_REMOTE_CREATED, $record);
        }
        if (PeerTube_Staged_Upload_State_Machine::PHASE_READY !== $record['phase']) {
            return self::result(self::STATUS_REFUSED, $record);
        }

        // Before the local claim, prove record/backend/credential authority without
        // performing a full source hash. No remote mutation is possible yet.
        $preflight = $this->preflight($record, $now, false);
        if ('ready' !== $preflight['status']) {
            return self::result($preflight['status'], $record);
        }
        $descriptor = $preflight['descriptor'];
        $secret = $preflight['secret'];

        $kind = '' === $record['upload_session_id']
            ? PeerTube_Staged_Upload_State_Machine::REQUEST_INIT
            : PeerTube_Staged_Upload_State_Machine::REQUEST_CHUNK;
        $start = PeerTube_Staged_Upload_State_Machine::REQUEST_INIT === $kind ? 0 : $record['confirmed_bytes'];
        $bytes = 0;
        if (PeerTube_Staged_Upload_State_Machine::REQUEST_CHUNK === $kind) {
            try {
                $chunk_mib = ($this->chunk_mib_resolver)($record['backend_id']);
            } catch (Throwable) {
                return self::result(self::STATUS_REFUSED, $record);
            }
            if (null === PeerTube_Upload_Policy::chunk_mib($chunk_mib)) {
                return self::result(self::STATUS_REFUSED, $record);
            }
            $bytes = PeerTube_Upload_Policy::bytes_for_remaining(
                $chunk_mib,
                $record['source']['bytes'] - $record['confirmed_bytes']
            );
            if ($bytes < 1) {
                return self::result(self::STATUS_REFUSED, $record);
            }
        }
        try {
            $capability = bin2hex(random_bytes(32));
        } catch (Throwable) {
            return self::result(self::STATUS_REFUSED, $record);
        }

        $claim = $this->operations->apply_event(
            $operation_id,
            $record['record_revision'],
            PeerTube_Staged_Upload_State_Machine::EVENT_CLAIM_UPLOAD,
            array(
                'attempt_capability' => $capability,
                'request_kind'       => $kind,
                'request_start'      => $start,
                'request_bytes'      => $bytes,
            ),
            $now
        );
        if (Atomic_Option_Result::APPLIED !== $claim->status()) {
            return self::atomic_failure($claim, $record);
        }
        $claimed = $this->operations->get($operation_id);
        if (! is_array($claimed)) {
            return self::result(self::STATUS_INDETERMINATE, $record);
        }

        // Re-prove all local fences after the durable in-flight claim and
        // before transmitting any consequential bytes.
        // Initialization has no upload slice to bind, so re-hash the complete
        // source immediately before that remote mutation. Chunk requests bind
        // and hash the exact open descriptor in PeerTube_Upload_Slice::open().
        $second = $this->preflight(
            $claimed,
            $now,
            PeerTube_Staged_Upload_State_Machine::REQUEST_INIT === $kind
        );
        if ('ready' !== $second['status']
            || $second['secret']['generation'] !== $secret['generation']) {
            $code = 'refresh_required' === $second['status']
                ? 'peertube.upload.refresh_required'
                : ('ready' === $second['status'] ? 'peertube.upload.backend_unavailable' : self::preflight_error_code($second['status']));
            return $this->retry_safe_local($claimed, $capability, $code, $now);
        }
        $descriptor = $second['descriptor'];
        $secret = $second['secret'];

        try {
            $api = ($this->api_factory)($record['origin']);
            if (! $api instanceof PeerTube_Staged_Upload_Api || $api->origin() !== $record['origin']) {
                return $this->retry_safe_local($claimed, $capability, 'peertube.upload.backend_unavailable', $now);
            }

            if (PeerTube_Staged_Upload_State_Machine::REQUEST_INIT === $kind) {
                $response = $api->begin_resumable_upload(
                    $secret['access_token'],
                    $record['destination_id'],
                    $record['upload']['name'],
                    $record['upload']['filename'],
                    $record['upload']['content_type'],
                    $record['source']['bytes']
                );
            } else {
                $slice = PeerTube_Upload_Slice::open($record['source'], $start, $bytes);
                if (! $slice instanceof PeerTube_Upload_Slice) {
                    return $this->retry_safe_local($claimed, $capability, 'peertube.upload.source_changed', $now);
                }
                $response = $api->upload_resumable_slice(
                    $secret['access_token'],
                    $record['upload_session_id'],
                    $start,
                    $record['source']['bytes'],
                    $record['upload']['content_type'],
                    $slice
                );
                $final_remote_created = is_array($response)
                    && true === ($response['ok'] ?? null)
                    && 'created' === ($response['data']['state'] ?? null);
                if (! $slice->verify_unchanged($final_remote_created)) {
                    return $this->mark_indeterminate($claimed, $capability, 0, $now);
                }
            }
        } catch (Throwable) {
            return $this->mark_indeterminate($claimed, $capability, 0, $now);
        } finally {
            if (isset($slice) && $slice instanceof PeerTube_Upload_Slice) {
                $slice->close();
            }
            unset($secret, $slice);
        }

        if (! is_array($response) || true !== ($response['ok'] ?? null)) {
            $error = is_array($response['error'] ?? null) ? $response['error'] : array();
            $machine_status = is_string($error['status'] ?? null) ? $error['status'] : '';
            $http_status = is_int($error['http_status'] ?? null) ? $error['http_status'] : 0;
            if ('authentication_required' === $machine_status && in_array($http_status, array(401, 403), true)) {
                return $this->retry_safe_local($claimed, $capability, 'peertube.upload.refresh_required', $now, $http_status);
            }
            if (PeerTube_Staged_Upload_State_Machine::REQUEST_INIT === $kind
                && 'rate_limited' === $machine_status && 429 === $http_status
                && is_int($error['retry_after'] ?? null) && $error['retry_after'] > 0) {
                $applied = $this->operations->apply_event(
                    $operation_id,
                    $claimed['record_revision'],
                    PeerTube_Staged_Upload_State_Machine::EVENT_UPLOAD_RETRY_SAFE,
                    array(
                        'attempt_capability' => $capability,
                        'code'               => 'peertube.upload.rate_limited',
                        'http_status'        => 429,
                        'retry_after'        => min(86400, $error['retry_after']),
                    ),
                    $now
                );
                return Atomic_Option_Result::APPLIED === $applied->status()
                    ? self::result(self::STATUS_WAIT, $this->operations->get($operation_id))
                    : $this->atomic_failure($applied, $claimed);
            }
            return $this->mark_indeterminate($claimed, $capability, $http_status, $now);
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : array();
        if (PeerTube_Staged_Upload_State_Machine::REQUEST_INIT === $kind) {
            $session_id = is_string($data['session_id'] ?? null) ? $data['session_id'] : '';
            $applied = $this->operations->apply_event(
                $operation_id,
                $claimed['record_revision'],
                PeerTube_Staged_Upload_State_Machine::EVENT_UPLOAD_SESSION_CREATED,
                array('attempt_capability' => $capability, 'session_id' => $session_id),
                $now
            );
            return Atomic_Option_Result::APPLIED === $applied->status()
                ? self::result(self::STATUS_SESSION_CREATED, $this->operations->get($operation_id))
                : $this->mark_indeterminate($claimed, $capability, 0, $now);
        }

        if ('incomplete' === ($data['state'] ?? null) && is_int($data['confirmed_bytes'] ?? null)
            && $data['confirmed_bytes'] === $start + $bytes && $data['confirmed_bytes'] < $record['source']['bytes']) {
            $applied = $this->operations->apply_event(
                $operation_id,
                $claimed['record_revision'],
                PeerTube_Staged_Upload_State_Machine::EVENT_UPLOAD_CHUNK_ACCEPTED,
                array('attempt_capability' => $capability, 'confirmed_bytes' => $data['confirmed_bytes']),
                $now
            );
            return Atomic_Option_Result::APPLIED === $applied->status()
                ? self::result(self::STATUS_CHUNK_ACCEPTED, $this->operations->get($operation_id))
                : $this->mark_indeterminate($claimed, $capability, 308, $now);
        }

        if ('created' === ($data['state'] ?? null) && is_array($data['remote_identity'] ?? null)) {
            $applied = $this->operations->apply_event(
                $operation_id,
                $claimed['record_revision'],
                PeerTube_Staged_Upload_State_Machine::EVENT_REMOTE_CREATED,
                array('attempt_capability' => $capability, 'remote_identity' => $data['remote_identity']),
                $now
            );
            return Atomic_Option_Result::APPLIED === $applied->status()
                ? self::result(self::STATUS_REMOTE_CREATED, $this->operations->get($operation_id))
                : $this->mark_indeterminate($claimed, $capability, 200, $now);
        }

        return $this->mark_indeterminate($claimed, $capability, 0, $now);
    }

    /**
     * Reconcile an uncertain byte-bearing PUT using PeerTube's zero-byte
     * resumable offset probe. This method never transmits source bytes.
     *
     * @return array<string,mixed>
     */
    public function reconcile(string $operation_id, int $now): array
    {
        $record = $this->operations->get($operation_id);
        if (! is_array($record) || $now < 1
            || PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE !== ($record['phase'] ?? null)) {
            return self::result(self::STATUS_REFUSED, $record);
        }
        if (PeerTube_Staged_Upload_State_Machine::REQUEST_CHUNK !== $record['request_kind']
            || '' === $record['upload_session_id']) {
            return self::result(self::STATUS_INDETERMINATE, $record);
        }

        $preflight = $this->preflight($record, $now);
        if ('ready' !== $preflight['status']) {
            return self::result($preflight['status'], $record);
        }
        $secret = $preflight['secret'];
        try {
            $api = ($this->api_factory)($record['origin']);
            if (! $api instanceof PeerTube_Staged_Upload_Api || $api->origin() !== $record['origin']) {
                return self::result(self::STATUS_INDETERMINATE, $record);
            }
            $response = $api->probe_resumable_upload(
                $secret['access_token'],
                $record['upload_session_id'],
                $record['source']['bytes']
            );
        } catch (Throwable) {
            return self::result(self::STATUS_INDETERMINATE, $record);
        } finally {
            unset($secret);
        }
        if (! is_array($response) || true !== ($response['ok'] ?? null)) {
            return self::result(self::STATUS_INDETERMINATE, $record);
        }

        $data = is_array($response['data'] ?? null) ? $response['data'] : array();
        if ('incomplete' === ($data['state'] ?? null) && is_int($data['confirmed_bytes'] ?? null)) {
            $applied = $this->operations->apply_event(
                $operation_id,
                $record['record_revision'],
                PeerTube_Staged_Upload_State_Machine::EVENT_RECONCILE_OFFSET,
                array('confirmed_bytes' => $data['confirmed_bytes']),
                $now
            );
            return Atomic_Option_Result::APPLIED === $applied->status()
                ? self::result(self::STATUS_ADVANCED, $this->operations->get($operation_id))
                : self::result(self::STATUS_INDETERMINATE, $record);
        }
        if ('created' === ($data['state'] ?? null) && is_array($data['remote_identity'] ?? null)) {
            $applied = $this->operations->apply_event(
                $operation_id,
                $record['record_revision'],
                PeerTube_Staged_Upload_State_Machine::EVENT_RECONCILE_REMOTE_FOUND,
                array('remote_identity' => $data['remote_identity']),
                $now
            );
            return Atomic_Option_Result::APPLIED === $applied->status()
                ? self::result(self::STATUS_REMOTE_CREATED, $this->operations->get($operation_id))
                : self::result(self::STATUS_INDETERMINATE, $record);
        }
        return self::result(self::STATUS_INDETERMINATE, $record);
    }

    /** @param array<string,mixed> $record @return array{status:string,descriptor:array<string,mixed>,secret:array<string,mixed>} */
    private function preflight(array $record, int $now, bool $verify_source = true): array
    {
        if (! PeerTube_Staged_Upload_State_Machine::valid($record)) {
            return array('status' => 'backend_unavailable', 'descriptor' => array(), 'secret' => array());
        }
        $descriptor = $this->registry->get($record['backend_id']);
        if (! PeerTube_Staged_Upload_Guard::descriptor_matches(
            $record,
            is_array($descriptor) ? $descriptor : null
        )) {
            return array('status' => 'backend_unavailable', 'descriptor' => array(), 'secret' => array());
        }
        if ($verify_source && ! PeerTube_Staged_Source_Identity::matches($record['source'])) {
            return array('status' => 'source_changed', 'descriptor' => array(), 'secret' => array());
        }
        try {
            $secret = $this->secrets->read($descriptor['secret_ref'], $record['backend_id']);
        } catch (Throwable) {
            $secret = null;
        }
        if (! self::valid_secret($secret)) {
            return array('status' => 'backend_unavailable', 'descriptor' => array(), 'secret' => array());
        }
        if ($now > PHP_INT_MAX - self::TOKEN_SKEW_SECONDS
            || $secret['access_expires_at'] <= $now + self::TOKEN_SKEW_SECONDS
            || $secret['refresh_expires_at'] <= $now + self::TOKEN_SKEW_SECONDS) {
            unset($secret);
            return array('status' => 'refresh_required', 'descriptor' => array(), 'secret' => array());
        }
        return array('status' => 'ready', 'descriptor' => $descriptor, 'secret' => $secret);
    }

    /** @param array<string,mixed>|null $secret */
    private static function valid_secret(?array $secret): bool
    {
        return is_array($secret)
            && array('access_token','refresh_token','access_expires_at','refresh_expires_at','generation') === array_keys($secret)
            && is_string($secret['access_token']) && '' !== $secret['access_token']
            && is_string($secret['refresh_token']) && '' !== $secret['refresh_token']
            && is_int($secret['access_expires_at']) && is_int($secret['refresh_expires_at'])
            && is_int($secret['generation']) && $secret['generation'] > 0;
    }

    /** @param array<string,mixed>|null $descriptor */
    private static function active_descriptor(?array $descriptor, string $backend_id): bool
    {
        return is_array($descriptor) && $backend_id === ($descriptor['id'] ?? null)
            && Backend_Registry::PEERTUBE_TYPE === ($descriptor['type'] ?? null)
            && 'active' === ($descriptor['state'] ?? null)
            && is_string($descriptor['default_destination'] ?? null)
            && $descriptor['default_destination'] === PeerTube_Connection_Input::destination_id($descriptor['default_destination'])
            && is_array($descriptor['config'] ?? null)
            && is_string($descriptor['config']['origin'] ?? null)
            && $descriptor['config']['origin'] === PeerTube_Origin::sanitize($descriptor['config']['origin']);
    }


    /** @param array<string,mixed> $claimed @return array<string,mixed> */
    private function retry_safe_local(array $claimed, string $capability, string $code, int $now, int $http_status = 0): array
    {
        $applied = $this->operations->apply_event(
            $claimed['operation_id'],
            $claimed['record_revision'],
            PeerTube_Staged_Upload_State_Machine::EVENT_UPLOAD_RETRY_SAFE,
            array('attempt_capability'=>$capability,'code'=>$code,'http_status'=>$http_status,'retry_after'=>0),
            $now
        );
        if (Atomic_Option_Result::APPLIED !== $applied->status()) {
            return $this->atomic_failure($applied, $claimed);
        }
        return self::result(
            'peertube.upload.refresh_required' === $code ? self::STATUS_REFRESH_REQUIRED : self::STATUS_ADVANCED,
            $this->operations->get($claimed['operation_id'])
        );
    }

    /** @param array<string,mixed> $claimed @return array<string,mixed> */
    private function mark_indeterminate(array $claimed, string $capability, int $http_status, int $now): array
    {
        $current = $this->operations->get($claimed['operation_id']);
        if (is_array($current) && PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE === $current['phase']) {
            return self::result(self::STATUS_INDETERMINATE, $current);
        }
        $revision = is_array($current) ? $current['record_revision'] : $claimed['record_revision'];
        $applied = $this->operations->apply_event(
            $claimed['operation_id'],
            $revision,
            PeerTube_Staged_Upload_State_Machine::EVENT_UPLOAD_INDETERMINATE,
            array('attempt_capability'=>$capability,'code'=>'peertube.upload.indeterminate','http_status'=>$http_status),
            $now
        );
        return Atomic_Option_Result::APPLIED === $applied->status()
            ? self::result(self::STATUS_INDETERMINATE, $this->operations->get($claimed['operation_id']))
            : self::result(self::STATUS_INDETERMINATE, $current ?? $claimed);
    }

    /** @return array<string,mixed> */
    private function atomic_failure(Atomic_Option_Result $result, ?array $record): array
    {
        return self::result(
            Atomic_Option_Result::CONFLICT === $result->status() ? self::STATUS_CONFLICT : self::STATUS_REFUSED,
            $record
        );
    }

    private static function preflight_error_code(string $status): string
    {
        return match ($status) {
            'source_changed' => 'peertube.upload.source_changed',
            'refresh_required' => 'peertube.upload.refresh_required',
            default => 'peertube.upload.backend_unavailable',
        };
    }

    /** @param array<string,mixed>|null $record @return array<string,mixed> */
    private static function result(string $status, ?array $record = null): array
    {
        return array(
            'status'          => $status,
            'operation_id'    => is_array($record) && is_string($record['operation_id'] ?? null) ? $record['operation_id'] : '',
            'phase'           => is_array($record) && is_string($record['phase'] ?? null) ? $record['phase'] : '',
            'record_revision' => is_array($record) && is_int($record['record_revision'] ?? null) ? $record['record_revision'] : 0,
            'confirmed_bytes' => is_array($record) && is_int($record['confirmed_bytes'] ?? null) ? $record['confirmed_bytes'] : 0,
            'remote_identity' => is_array($record) && is_array($record['remote_identity'] ?? null) ? $record['remote_identity'] : array('id'=>'','uuid'=>''),
        );
    }
}

// EOF: includes/PeerTube_Staged_Upload_Service.php
