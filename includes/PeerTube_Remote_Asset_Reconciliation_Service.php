<?php
/**
 * File: includes/PeerTube_Remote_Asset_Reconciliation_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use Throwable;

/**
 * Explicit post-upload reconciliation for a positively created PeerTube video.
 *
 * This service performs no media mutation. It first durably commits the
 * already-known PeerTube identity into argent_video_remote_assets and only on
 * a later bounded invocation performs a read-only GET of the exact private
 * video. R45 may schedule that later one-shot invocation, but only at the
 * durable rate/backoff boundary in the staged-upload operation journal.
 */
final class PeerTube_Remote_Asset_Reconciliation_Service
{
    public const STATUS_REMOTE_COMMITTED = 'remote_committed';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_READY_VERIFIED = 'ready_verified';
    public const STATUS_WAIT = 'wait';
    public const STATUS_MISSING = 'missing';
    public const STATUS_FAILED = 'failed';
    public const STATUS_REFRESH_REQUIRED = 'refresh_required';
    public const STATUS_INDETERMINATE = 'indeterminate';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_REFUSED = 'refused';

    private const TOKEN_SKEW_SECONDS = 60;
    private const PROCESSING_RECHECK_SECONDS = 30;
    private const TRANSIENT_RECHECK_SECONDS = 60;

    /** @var Closure(string):PeerTube_Remote_Reconciliation_Api */
    private Closure $api_factory;

    public function __construct(
        private readonly PeerTube_Staged_Upload_Operation_Store $operations,
        private readonly PeerTube_Remote_Asset_Store $assets,
        private readonly Backend_Registry $registry,
        private readonly Managed_Backend_Secret_Store $secrets,
        callable $api_factory
    ) {
        $this->api_factory = Closure::fromCallable($api_factory);
    }

    /** @return array<string,mixed> */
    public function advance(string $operation_id, int $now): array
    {
        $record = $this->operations->get($operation_id);
        if (! is_array($record) || $now < 1 || ! PeerTube_Staged_Upload_State_Machine::valid($record)) {
            return self::result(self::STATUS_REFUSED, $record);
        }

        if (PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_CREATED === $record['phase']) {
            return $this->commit_remote_asset($record, $now);
        }

        if (PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED === $record['phase']) {
            return self::result(self::STATUS_READY_VERIFIED, $record);
        }
        if (PeerTube_Staged_Upload_State_Machine::PHASE_FAILED === $record['phase']) {
            return self::result(
                'peertube.remote.missing' === ($record['last_error']['code'] ?? null)
                    ? self::STATUS_MISSING
                    : self::STATUS_FAILED,
                $record
            );
        }
        if (! in_array(
            $record['phase'],
            array(
                PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED,
                PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING,
            ),
            true
        )) {
            return self::result(self::STATUS_REFUSED, $record);
        }

        $wait = $this->wait_status($record, $now);
        if (null !== $wait) {
            return $wait;
        }

        $asset = $this->assets->find((int) $record['remote_asset_id']);
        if (! self::asset_matches($asset, $record)) {
            return self::result(self::STATUS_CONFLICT, $record);
        }

        // The relational observation is written before the option journal. If
        // a process dies in that window, finish the exact local journal step
        // from independently persisted row evidence before performing another
        // remote GET. This also prevents a later remote regression from hiding
        // an already-positive ready/missing/failure observation.
        $recovered = $this->recover_journal_from_asset($record, $asset, $now);
        if (null !== $recovered) {
            return $recovered;
        }

        $preflight = $this->preflight($record, $now);
        if ('ready' !== $preflight['status']) {
            return self::result($preflight['status'], $record);
        }

        $secret = $preflight['secret'];
        try {
            $api = ($this->api_factory)($record['origin']);
            if (! $api instanceof PeerTube_Remote_Reconciliation_Api || $api->origin() !== $record['origin']) {
                return self::result(self::STATUS_INDETERMINATE, $record);
            }
            $response = $api->video_status($secret['access_token'], $record['remote_identity']['uuid']);
        } catch (Throwable) {
            $response = array(
                'ok' => false,
                'data' => null,
                'error' => array('status' => 'transport_error', 'http_status' => 0, 'retry_after' => 0),
            );
        } finally {
            unset($secret);
        }

        if (! is_array($response) || true !== ($response['ok'] ?? null)) {
            return $this->handle_read_failure($record, is_array($response['error'] ?? null) ? $response['error'] : array(), $now);
        }

        $observation = is_array($response['data'] ?? null) ? $response['data'] : array();
        if (! self::observation_matches($observation, $record)) {
            return self::result(self::STATUS_CONFLICT, $record);
        }

        return $this->record_remote_state($record, $observation, $now);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function commit_remote_asset(array $record, int $now): array
    {
        $commit = $this->assets->commit_created($record, $now);
        $status = is_string($commit['status'] ?? null) ? $commit['status'] : '';
        $remote_asset_id = is_int($commit['remote_asset_id'] ?? null) ? $commit['remote_asset_id'] : 0;

        if (PeerTube_Remote_Asset_Store::CONFLICT === $status) {
            return self::result(self::STATUS_CONFLICT, $record);
        }
        if (! in_array($status, array(PeerTube_Remote_Asset_Store::APPLIED, PeerTube_Remote_Asset_Store::PRESENT), true)
            || $remote_asset_id < 1) {
            return self::result(self::STATUS_INDETERMINATE, $record);
        }

        $applied = $this->operations->apply_event(
            $record['operation_id'],
            $record['record_revision'],
            PeerTube_Staged_Upload_State_Machine::EVENT_COMMIT_REMOTE_ASSET,
            array('remote_asset_id' => $remote_asset_id),
            $now
        );
        if (Atomic_Option_Result::APPLIED === $applied->status()) {
            return self::result(self::STATUS_REMOTE_COMMITTED, $this->operations->get($record['operation_id']));
        }

        // If a concurrent/restarted request already committed this exact row,
        // accept only the independently re-read exact operation state.
        $after = $this->operations->get($record['operation_id']);
        if (is_array($after)
            && PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED === ($after['phase'] ?? null)
            && $remote_asset_id === ($after['remote_asset_id'] ?? null)) {
            return self::result(self::STATUS_REMOTE_COMMITTED, $after);
        }
        return self::result(
            Atomic_Option_Result::CONFLICT === $applied->status() ? self::STATUS_CONFLICT : self::STATUS_INDETERMINATE,
            $record
        );
    }

    /** @param array<string,mixed> $record @return array<string,mixed>|null */
    private function wait_status(array $record, int $now): ?array
    {
        $retry_after = is_int($record['last_error']['retry_after'] ?? null)
            ? $record['last_error']['retry_after']
            : 0;
        if ($retry_after < 1) {
            return null;
        }
        if ($record['updated_at'] > PHP_INT_MAX - $retry_after) {
            return self::result(self::STATUS_REFUSED, $record);
        }
        return $now < $record['updated_at'] + $retry_after
            ? self::result(self::STATUS_WAIT, $record)
            : null;
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $error @return array<string,mixed> */
    private function handle_read_failure(array $record, array $error, int $now): array
    {
        $status = is_string($error['status'] ?? null) ? $error['status'] : '';
        $http_status = is_int($error['http_status'] ?? null) ? $error['http_status'] : 0;
        $retry_after = is_int($error['retry_after'] ?? null) ? $error['retry_after'] : 0;

        if ('not_found' === $status && 404 === $http_status) {
            $stored = $this->assets->record_observation(
                (int) $record['remote_asset_id'],
                $record,
                array(
                    'state' => 'missing',
                    'actual_privacy' => '',
                    'remote_processing_state' => 'missing',
                    'embed_url' => '',
                    'verified' => false,
                    'error_code' => 'peertube.remote.missing',
                ),
                $now
            );
            if (! in_array($stored, array(PeerTube_Remote_Asset_Store::APPLIED, PeerTube_Remote_Asset_Store::PRESENT), true)) {
                return self::result(
                    PeerTube_Remote_Asset_Store::CONFLICT === $stored ? self::STATUS_CONFLICT : self::STATUS_INDETERMINATE,
                    $record
                );
            }
            $applied = $this->operations->apply_event(
                $record['operation_id'],
                $record['record_revision'],
                PeerTube_Staged_Upload_State_Machine::EVENT_REMOTE_MISSING,
                array('http_status' => 404),
                $now
            );
            return Atomic_Option_Result::APPLIED === $applied->status()
                ? self::result(self::STATUS_MISSING, $this->operations->get($record['operation_id']))
                : self::result(self::STATUS_INDETERMINATE, $record);
        }

        if (in_array($status, array('authentication_required','permission_denied'), true)
            || in_array($http_status, array(401,403), true)) {
            return self::result(self::STATUS_REFRESH_REQUIRED, $record);
        }

        if ('rate_limited' === $status || 429 === $http_status) {
            return $this->persist_read_wait($record, 429, $retry_after > 0 ? min(86400, $retry_after) : self::TRANSIENT_RECHECK_SECONDS, $now);
        }
        if ('transport_error' === $status || 'tls_error' === $status || 'remote_error' === $status
            || 0 === $http_status || $http_status >= 500) {
            $status_for_record = in_array($http_status, array(500,502,503,504), true) ? $http_status : 0;
            return $this->persist_read_wait($record, $status_for_record, self::TRANSIENT_RECHECK_SECONDS, $now);
        }

        return self::result(self::STATUS_INDETERMINATE, $record);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function persist_read_wait(array $record, int $http_status, int $retry_after, int $now): array
    {
        $applied = $this->operations->apply_event(
            $record['operation_id'],
            $record['record_revision'],
            PeerTube_Staged_Upload_State_Machine::EVENT_RECONCILE_WAIT,
            array(
                'code' => 'peertube.remote.reconcile_wait',
                'http_status' => $http_status,
                'retry_after' => $retry_after,
            ),
            $now
        );
        return Atomic_Option_Result::APPLIED === $applied->status()
            ? self::result(self::STATUS_WAIT, $this->operations->get($record['operation_id']))
            : self::result(self::STATUS_INDETERMINATE, $record);
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $remote @return array<string,mixed> */
    private function record_remote_state(array $record, array $remote, int $now): array
    {
        $state_id = $remote['state_id'];
        $embed_url = $record['origin'] . $remote['embed_path'];
        $remote_state = self::remote_state_name($state_id);
        if ('' === $remote_state) {
            return self::result(self::STATUS_CONFLICT, $record);
        }

        if (1 === $state_id) {
            $stored = $this->assets->record_observation(
                (int) $record['remote_asset_id'],
                $record,
                self::observation('ready', $remote_state, $embed_url, true, ''),
                $now
            );
            if (! in_array($stored, array(PeerTube_Remote_Asset_Store::APPLIED, PeerTube_Remote_Asset_Store::PRESENT), true)) {
                return self::result(PeerTube_Remote_Asset_Store::CONFLICT === $stored ? self::STATUS_CONFLICT : self::STATUS_INDETERMINATE, $record);
            }
            $applied = $this->operations->apply_event(
                $record['operation_id'],
                $record['record_revision'],
                PeerTube_Staged_Upload_State_Machine::EVENT_READY_VERIFIED,
                array(),
                $now
            );
            return Atomic_Option_Result::APPLIED === $applied->status()
                ? self::result(self::STATUS_READY_VERIFIED, $this->operations->get($record['operation_id']))
                : self::result(self::STATUS_INDETERMINATE, $record);
        }

        if (in_array($state_id, array(2,6,9), true)) {
            $stored = $this->assets->record_observation(
                (int) $record['remote_asset_id'],
                $record,
                self::observation('processing', $remote_state, $embed_url, false, ''),
                $now
            );
            if (! in_array($stored, array(PeerTube_Remote_Asset_Store::APPLIED, PeerTube_Remote_Asset_Store::PRESENT), true)) {
                return self::result(PeerTube_Remote_Asset_Store::CONFLICT === $stored ? self::STATUS_CONFLICT : self::STATUS_INDETERMINATE, $record);
            }
            $applied = $this->operations->apply_event(
                $record['operation_id'],
                $record['record_revision'],
                PeerTube_Staged_Upload_State_Machine::EVENT_PROCESSING_OBSERVED,
                array('retry_after' => self::PROCESSING_RECHECK_SECONDS),
                $now
            );
            return Atomic_Option_Result::APPLIED === $applied->status()
                ? self::result(self::STATUS_PROCESSING, $this->operations->get($record['operation_id']))
                : self::result(self::STATUS_INDETERMINATE, $record);
        }

        if (in_array($state_id, array(7,8), true)) {
            $stored = $this->assets->record_observation(
                (int) $record['remote_asset_id'],
                $record,
                self::observation('failed', $remote_state, $embed_url, false, 'peertube.remote.processing_failed'),
                $now
            );
            if (! in_array($stored, array(PeerTube_Remote_Asset_Store::APPLIED, PeerTube_Remote_Asset_Store::PRESENT), true)) {
                return self::result(PeerTube_Remote_Asset_Store::CONFLICT === $stored ? self::STATUS_CONFLICT : self::STATUS_INDETERMINATE, $record);
            }
            $applied = $this->operations->apply_event(
                $record['operation_id'],
                $record['record_revision'],
                PeerTube_Staged_Upload_State_Machine::EVENT_REMOTE_FAILED,
                array('code' => 'peertube.remote.processing_failed', 'http_status' => 0),
                $now
            );
            return Atomic_Option_Result::APPLIED === $applied->status()
                ? self::result(self::STATUS_FAILED, $this->operations->get($record['operation_id']))
                : self::result(self::STATUS_INDETERMINATE, $record);
        }

        // Import/live states are incompatible with this checkpoint's exact
        // private, non-live local resumable-upload contract. Do not reinterpret
        // them as ready or failed.
        return self::result(self::STATUS_CONFLICT, $record);
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $asset @return array<string,mixed>|null */
    private function recover_journal_from_asset(array $record, array $asset, int $now): ?array
    {
        $state = is_string($asset['state'] ?? null) ? $asset['state'] : '';
        $phase = $record['phase'];
        $event = '';
        $payload = array();
        $status = '';

        if (PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED === $phase
            && 'processing' === $state
            && self::valid_persisted_processing($asset, $record)) {
            $event = PeerTube_Staged_Upload_State_Machine::EVENT_PROCESSING_OBSERVED;
            $payload = array('retry_after' => self::PROCESSING_RECHECK_SECONDS);
            $status = self::STATUS_PROCESSING;
        } elseif (in_array($phase, array(PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED, PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING), true)
            && 'ready' === $state
            && self::valid_persisted_ready($asset, $record)) {
            $event = PeerTube_Staged_Upload_State_Machine::EVENT_READY_VERIFIED;
            $status = self::STATUS_READY_VERIFIED;
        } elseif (in_array($phase, array(PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED, PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING), true)
            && 'missing' === $state
            && self::valid_persisted_missing($asset)) {
            $event = PeerTube_Staged_Upload_State_Machine::EVENT_REMOTE_MISSING;
            $payload = array('http_status' => 404);
            $status = self::STATUS_MISSING;
        } elseif (in_array($phase, array(PeerTube_Staged_Upload_State_Machine::PHASE_REMOTE_COMMITTED, PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING), true)
            && 'failed' === $state
            && self::valid_persisted_failure($asset, $record)) {
            $event = PeerTube_Staged_Upload_State_Machine::EVENT_REMOTE_FAILED;
            $payload = array('code' => 'peertube.remote.processing_failed', 'http_status' => 0);
            $status = self::STATUS_FAILED;
        } else {
            return null;
        }

        $applied = $this->operations->apply_event(
            $record['operation_id'],
            $record['record_revision'],
            $event,
            $payload,
            $now
        );
        if (Atomic_Option_Result::APPLIED === $applied->status()) {
            return self::result($status, $this->operations->get($record['operation_id']));
        }

        $after = $this->operations->get($record['operation_id']);
        if (is_array($after) && self::journal_matches_recovery($after, $record['remote_asset_id'], $status)) {
            return self::result($status, $after);
        }
        return self::result(
            Atomic_Option_Result::CONFLICT === $applied->status() ? self::STATUS_CONFLICT : self::STATUS_INDETERMINATE,
            $record
        );
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $record */
    private static function valid_persisted_processing(array $asset, array $record): bool
    {
        return in_array(self::nullable_string($asset['remote_processing_state'] ?? null), array('2:to_transcode','6:moving_to_external_storage','9:studio_editing'), true)
            && 'private' === self::nullable_string($asset['actual_privacy'] ?? null)
            && self::valid_persisted_embed($asset, $record)
            && '' === self::nullable_string($asset['last_verified_at'] ?? null)
            && '' === self::nullable_string($asset['error_code'] ?? null);
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $record */
    private static function valid_persisted_ready(array $asset, array $record): bool
    {
        return '1:published' === self::nullable_string($asset['remote_processing_state'] ?? null)
            && 'private' === self::nullable_string($asset['actual_privacy'] ?? null)
            && self::valid_persisted_embed($asset, $record)
            && '' !== self::nullable_string($asset['last_verified_at'] ?? null)
            && '' === self::nullable_string($asset['error_code'] ?? null);
    }

    /** @param array<string,mixed> $asset */
    private static function valid_persisted_missing(array $asset): bool
    {
        return 'missing' === self::nullable_string($asset['remote_processing_state'] ?? null)
            && '' === self::nullable_string($asset['actual_privacy'] ?? null)
            && '' === self::nullable_string($asset['embed_url'] ?? null)
            && '' === self::nullable_string($asset['last_verified_at'] ?? null)
            && 'peertube.remote.missing' === self::nullable_string($asset['error_code'] ?? null);
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $record */
    private static function valid_persisted_failure(array $asset, array $record): bool
    {
        return in_array(self::nullable_string($asset['remote_processing_state'] ?? null), array('7:transcoding_failed','8:external_storage_move_failed'), true)
            && 'private' === self::nullable_string($asset['actual_privacy'] ?? null)
            && self::valid_persisted_embed($asset, $record)
            && '' === self::nullable_string($asset['last_verified_at'] ?? null)
            && 'peertube.remote.processing_failed' === self::nullable_string($asset['error_code'] ?? null);
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $record */
    private static function valid_persisted_embed(array $asset, array $record): bool
    {
        $embed = self::nullable_string($asset['embed_url'] ?? null);
        $prefix = $record['origin'] . '/videos/embed/';
        if (! str_starts_with($embed, $prefix)) {
            return false;
        }
        $suffix = substr($embed, strlen($prefix));
        return is_string($suffix) && 1 === preg_match('/^[A-Za-z0-9_-]{1,191}$/D', $suffix);
    }

    /** @param array<string,mixed> $after */
    private static function journal_matches_recovery(array $after, int $remote_asset_id, string $status): bool
    {
        if (! PeerTube_Staged_Upload_State_Machine::valid($after) || $remote_asset_id !== ($after['remote_asset_id'] ?? null)) {
            return false;
        }
        $expected = match ($status) {
            self::STATUS_PROCESSING => PeerTube_Staged_Upload_State_Machine::PHASE_PROCESSING,
            self::STATUS_READY_VERIFIED => PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED,
            self::STATUS_MISSING, self::STATUS_FAILED => PeerTube_Staged_Upload_State_Machine::PHASE_FAILED,
            default => '',
        };
        if ($expected !== ($after['phase'] ?? null)) {
            return false;
        }
        if (self::STATUS_MISSING === $status) {
            return 'peertube.remote.missing' === ($after['last_error']['code'] ?? null);
        }
        if (self::STATUS_FAILED === $status) {
            return 'peertube.remote.processing_failed' === ($after['last_error']['code'] ?? null);
        }
        return true;
    }

    private static function nullable_string(mixed $value): string
    {
        return null === $value ? '' : (is_string($value) ? $value : '');
    }

    /** @param array<string,mixed> $record @return array{status:string,secret:array<string,mixed>} */
    private function preflight(array $record, int $now): array
    {
        $descriptor = $this->registry->get($record['backend_id']);
        $guard = PeerTube_Staged_Upload_Guard::evaluate($record, is_array($descriptor) ? $descriptor : null);
        if (PeerTube_Staged_Upload_Guard::READY !== $guard) {
            return array(
                'status' => PeerTube_Staged_Upload_Guard::SOURCE_CHANGED === $guard
                    ? self::STATUS_CONFLICT
                    : self::STATUS_REFUSED,
                'secret' => array(),
            );
        }
        try {
            $secret = $this->secrets->read($descriptor['secret_ref'], $record['backend_id']);
        } catch (Throwable) {
            $secret = null;
        }
        if (! self::valid_secret($secret)) {
            return array('status' => self::STATUS_REFUSED, 'secret' => array());
        }
        if ($now > PHP_INT_MAX - self::TOKEN_SKEW_SECONDS
            || $secret['access_expires_at'] <= $now + self::TOKEN_SKEW_SECONDS
            || $secret['refresh_expires_at'] <= $now + self::TOKEN_SKEW_SECONDS) {
            unset($secret);
            return array('status' => self::STATUS_REFRESH_REQUIRED, 'secret' => array());
        }
        return array('status' => 'ready', 'secret' => $secret);
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

    /** @param array<string,mixed>|null $asset @param array<string,mixed> $record */
    private static function asset_matches(?array $asset, array $record): bool
    {
        return is_array($asset)
            && (int) ($asset['id'] ?? 0) === (int) $record['remote_asset_id']
            && (int) ($asset['video_post_id'] ?? 0) === (int) $record['video_post_id']
            && (string) ($asset['backend_id'] ?? '') === (string) $record['backend_id']
            && (string) ($asset['channel_id'] ?? '') === (string) $record['destination_id']
            && (string) ($asset['remote_id'] ?? '') === (string) $record['remote_identity']['uuid']
            && in_array((string) ($asset['role'] ?? ''), array('secondary','primary'), true)
            && 'private' === (string) ($asset['desired_privacy'] ?? '');
    }

    /** @param array<string,mixed> $remote @param array<string,mixed> $record */
    private static function observation_matches(array $remote, array $record): bool
    {
        return array('id','uuid','state_id','privacy_id','channel_id','embed_path','is_live') === array_keys($remote)
            && is_string($remote['id']) && hash_equals($record['remote_identity']['id'], $remote['id'])
            && is_string($remote['uuid']) && hash_equals($record['remote_identity']['uuid'], $remote['uuid'])
            && is_int($remote['state_id']) && $remote['state_id'] >= 1 && $remote['state_id'] <= 9
            && PeerTube_Staged_Upload_State_Machine::PRIVATE_PRIVACY === ($remote['privacy_id'] ?? null)
            && is_string($remote['channel_id']) && hash_equals($record['destination_id'], $remote['channel_id'])
            && is_string($remote['embed_path'])
            && 1 === preg_match('#^/videos/embed/[A-Za-z0-9_-]{1,191}$#D', $remote['embed_path'])
            && false === ($remote['is_live'] ?? null);
    }

    private static function remote_state_name(int $state_id): string
    {
        return match ($state_id) {
            1 => '1:published',
            2 => '2:to_transcode',
            3 => '3:to_import',
            4 => '4:waiting_for_live_stream',
            5 => '5:live_ended',
            6 => '6:moving_to_external_storage',
            7 => '7:transcoding_failed',
            8 => '8:external_storage_move_failed',
            9 => '9:studio_editing',
            default => '',
        };
    }

    /** @return array<string,mixed> */
    private static function observation(
        string $state,
        string $remote_processing_state,
        string $embed_url,
        bool $verified,
        string $error_code
    ): array {
        return array(
            'state' => $state,
            'actual_privacy' => 'private',
            'remote_processing_state' => $remote_processing_state,
            'embed_url' => $embed_url,
            'verified' => $verified,
            'error_code' => $error_code,
        );
    }

    /** @param array<string,mixed>|null $record @return array<string,mixed> */
    private static function result(string $status, ?array $record = null): array
    {
        return array(
            'status' => $status,
            'operation_id' => is_array($record) && is_string($record['operation_id'] ?? null) ? $record['operation_id'] : '',
            'phase' => is_array($record) && is_string($record['phase'] ?? null) ? $record['phase'] : '',
            'record_revision' => is_array($record) && is_int($record['record_revision'] ?? null) ? $record['record_revision'] : 0,
            'remote_asset_id' => is_array($record) && is_int($record['remote_asset_id'] ?? null) ? $record['remote_asset_id'] : 0,
            'retry_after' => is_array($record) && is_int($record['last_error']['retry_after'] ?? null) ? $record['last_error']['retry_after'] : 0,
        );
    }
}

// EOF: includes/PeerTube_Remote_Asset_Reconciliation_Service.php
