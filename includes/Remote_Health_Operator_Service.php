<?php
/** File: includes/Remote_Health_Operator_Service.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Explicit, auditable operator recheck and immediate serving-recovery boundary. */
final class Remote_Health_Operator_Service
{
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';
    public const RESTORE_WINDOW = 600;

    public function __construct(
        private readonly Remote_Asset_Repository $assets,
        private readonly Remote_Publication_Health_Repository $health,
        private readonly Remote_Publication_Health_Service $probes,
        private readonly ?PeerTube_Event_Repository $events = null,
        private readonly ?Remote_Health_Notification_Service $notifications = null,
        private readonly ?PeerTube_Serving_Cutover_Service $cutover = null,
        private readonly ?Verified_Remote_Asset_Refresher $remote_refresher = null
    ) {}

    /** @return array{status:string,viability_status:string,restore_available:bool,message:string} */
    public function check_now(int $video_id, int $remote_asset_id, int $actor_id, int $now): array
    {
        if ($video_id < 1 || $remote_asset_id < 1 || $actor_id < 1 || $now < 1) {
            return self::result(self::REFUSED, '', false, 'Invalid remote serving check request.');
        }
        $asset = $this->asset($video_id, $remote_asset_id);
        if (! is_array($asset)) {
            return self::result(self::REFUSED, '', false, 'The selected remote publication is no longer available for checking.');
        }
        $before = $this->health->find($remote_asset_id);
        if (! self::incident_checkable($before) && ! $this->authority_missing_for_asset($video_id, $remote_asset_id)) {
            return self::result(self::REFUSED, '', false, 'This remote publication does not currently have a checkable serving incident.');
        }

        $probe = $this->probes->probe_and_record($asset, $now);
        $status = is_string($probe['viability_status'] ?? null) ? $probe['viability_status'] : Serving_Viability::PROBE_INDETERMINATE;
        $recorded = true === ($probe['recorded'] ?? false);
        $record = Remote_Health_Operator_Check::sanitize(array(
            'version' => Remote_Health_Operator_Check::VERSION,
            'remote_asset_id' => $remote_asset_id,
            'backend_id' => (string) $asset['backend_id'],
            'checked_by' => $actor_id,
            'checked_at' => $now,
            'viability_status' => $status,
            'reason_code' => is_string($probe['reason_code'] ?? null) ? $probe['reason_code'] : '',
            'http_status' => is_int($probe['http_status'] ?? null) ? $probe['http_status'] : 0,
            'restored_by' => 0,
            'restored_at' => 0,
        ));
        if (array() === $record) {
            return self::result(self::INDETERMINATE, $status, false, 'The remote serving check completed, but its operator audit record could not be normalized.');
        }
        update_post_meta($video_id, Video_Meta::REMOTE_HEALTH_OPERATOR_CHECK, $record);
        $stored = Remote_Health_Operator_Check::sanitize(get_post_meta($video_id, Video_Meta::REMOTE_HEALTH_OPERATOR_CHECK, true));
        $durable = $record === $stored;
        $restore = $recorded && $durable && $this->restore_available($video_id, $remote_asset_id, $now);
        $this->record_check_event($video_id, $asset, $actor_id, $now, $probe, $recorded && $durable);
        if (! $recorded || ! $durable) {
            return self::result(self::INDETERMINATE, $status, false, 'The remote serving check did not reach a durable result. No serving change was made.');
        }
        return self::result(
            self::APPLIED,
            $status,
            $restore,
            Serving_Viability::HEALTHY === $status
                ? ($restore ? 'The remote publication passed a fresh visitor-facing check. You may use this verified remote now.' : 'The remote publication passed a fresh visitor-facing check.')
                : (string) ($probe['message'] ?? 'The remote publication check completed.')
        );
    }

    public function restore_available(int $video_id, int $remote_asset_id, int $now): bool
    {
        $check = Remote_Health_Operator_Check::sanitize(get_post_meta($video_id, Video_Meta::REMOTE_HEALTH_OPERATOR_CHECK, true));
        if (array() === $check
            || $remote_asset_id !== (int) $check['remote_asset_id']
            || Serving_Viability::HEALTHY !== $check['viability_status']
            || (int) $check['restored_at'] > 0
            || $now < (int) $check['checked_at']
            || $now - (int) $check['checked_at'] > self::RESTORE_WINDOW) {
            return false;
        }
        $row = $this->health->find($remote_asset_id);
        if (! is_array($row)
            || $video_id !== (int) ($row['video_post_id'] ?? 0)
            || $check['backend_id'] !== (string) ($row['backend_id'] ?? '')
            || Serving_Viability::HEALTHY !== (string) ($row['status'] ?? '')
            || (int) ($row['success_streak'] ?? 0) < 1
            || gmdate('Y-m-d H:i:s', (int) $check['checked_at']) !== (string) ($row['last_checked_at'] ?? '')) {
            return false;
        }
        return 0 === (int) ($row['eligible'] ?? 0)
            || $this->authority_missing_for_asset($video_id, $remote_asset_id);
    }

    /** @return array{status:string,viability_status:string,restore_available:bool,message:string} */
    public function restore_now(int $video_id, int $remote_asset_id, int $actor_id, int $now): array
    {
        if ($video_id < 1 || $remote_asset_id < 1 || $actor_id < 1 || $now < 1) {
            return self::result(self::REFUSED, '', false, 'Invalid remote serving restore request.');
        }
        $asset = $this->asset($video_id, $remote_asset_id);
        if (! is_array($asset)) {
            return self::result(self::REFUSED, '', false, 'The selected remote publication is unavailable.');
        }
        $check = Remote_Health_Operator_Check::sanitize(get_post_meta($video_id, Video_Meta::REMOTE_HEALTH_OPERATOR_CHECK, true));
        if (array() !== $check && (int) ($check['restored_at'] ?? 0) > 0
            && $remote_asset_id === (int) ($check['remote_asset_id'] ?? 0)) {
            return self::result(self::PRESENT, Serving_Viability::HEALTHY, false, 'This verified remote publication was already selected from this operator check.');
        }
        if (! $this->restore_available($video_id, $remote_asset_id, $now)) {
            $message = 'A recent successful operator check is required before this verified remote publication can be used.';
            $this->record_restore_refusal($video_id, $asset, $actor_id, $now, 'operator.restore.check_required', $message, self::REFUSED);
            return self::result(self::REFUSED, '', false, $message);
        }

        if (null !== $this->remote_refresher) {
            $refreshed = $this->remote_refresher->refresh($video_id, $remote_asset_id, $now);
            $refresh_status = is_string($refreshed['status'] ?? null) ? (string) $refreshed['status'] : Verified_Remote_Asset_Refresher::INDETERMINATE;
            if (! in_array($refresh_status, array(Verified_Remote_Asset_Refresher::APPLIED, Verified_Remote_Asset_Refresher::PRESENT), true)) {
                $reason = is_string($refreshed['reason_code'] ?? null) && '' !== (string) $refreshed['reason_code']
                    ? (string) $refreshed['reason_code']
                    : 'operator.remote_refresh.indeterminate';
                $message = is_string($refreshed['message'] ?? null) && '' !== trim((string) $refreshed['message'])
                    ? trim((string) $refreshed['message'])
                    : 'The remote publication passed the visitor-facing check, but its current PeerTube state could not be verified for serving adoption.';
                $result_status = Verified_Remote_Asset_Refresher::REFUSED === $refresh_status ? self::REFUSED : self::INDETERMINATE;
                $this->record_restore_refusal($video_id, $asset, $actor_id, $now, $reason, $message, $result_status);
                return self::result($result_status, Serving_Viability::HEALTHY, false, $message);
            }
            $asset = $this->asset($video_id, $remote_asset_id);
            if (! is_array($asset)) {
                $message = 'PeerTube was verified, but the refreshed remote-publication record is no longer usable for serving adoption.';
                $this->record_restore_refusal($video_id, array('id'=>$remote_asset_id,'backend_id'=>(string) $check['backend_id']), $actor_id, $now, 'operator.remote_refresh.asset_unusable', $message, self::INDETERMINATE);
                return self::result(self::INDETERMINATE, Serving_Viability::HEALTHY, false, $message);
            }
        }

        $expected = gmdate('Y-m-d H:i:s', (int) $check['checked_at']);
        $before_health = $this->health->find($remote_asset_id);
        if (! is_array($before_health)) {
            $message = 'The remote serving health record disappeared before the verified result could be applied.';
            $this->record_restore_refusal($video_id, $asset, $actor_id, $now, 'operator.restore.health_record_missing', $message, self::INDETERMINATE);
            return self::result(self::INDETERMINATE, '', false, $message);
        }

        $authority_missing = $this->authority_missing_for_asset($video_id, $remote_asset_id);
        $adoption = PeerTube_Serving_Cutover_Service::PRESENT;
        if ($authority_missing) {
            if (null === $this->cutover) {
                $message = 'Verified remote serving authority could not be established. No serving eligibility was changed.';
                $this->record_restore_refusal($video_id, $asset, $actor_id, $now, 'operator.restore.cutover_unavailable', $message, self::INDETERMINATE);
                return self::result(self::INDETERMINATE, Serving_Viability::HEALTHY, false, $message);
            }
            // Establish authority before restoring eligibility. When this asset is
            // currently ineligible, the normal serving resolver will continue to
            // ignore it until the guarded health-row transition below succeeds.
            $adoption = $this->cutover->adopt_verified_remote(
                $video_id,
                $remote_asset_id,
                $expected,
                $now,
                0 === (int) ($before_health['eligible'] ?? 0)
            );
            if (! in_array($adoption, array(PeerTube_Serving_Cutover_Service::APPLIED, PeerTube_Serving_Cutover_Service::PRESENT), true)) {
                $message = 'The remote publication passed verification, but its serving authority could not be established safely. No serving eligibility was changed.';
                $this->record_restore_refusal($video_id, $asset, $actor_id, $now, 'operator.restore.authority_adoption_' . $adoption, $message, self::INDETERMINATE);
                return self::result(self::INDETERMINATE, Serving_Viability::HEALTHY, false, $message);
            }
        }

        if (0 === (int) ($before_health['eligible'] ?? 0)) {
            $written = $this->health->operator_restore_eligible(
                $remote_asset_id,
                $video_id,
                (string) $check['backend_id'],
                $expected,
                $now
            );
            if (! in_array($written, array(
                Remote_Publication_Health_Repository::APPLIED,
                Remote_Publication_Health_Repository::PRESENT,
            ), true)) {
                if ($authority_missing && null !== $this->cutover) {
                    // The asset is still ineligible, so normal reconciliation must
                    // remove any provisional operator authority created above.
                    $this->cutover->reconcile($video_id, $now);
                }
                $message = 'Serving eligibility changed while the verified result was being applied. No blind override was performed.';
                $this->record_restore_refusal($video_id, $asset, $actor_id, $now, 'operator.restore.health_compare_and_swap_failed', $message, self::INDETERMINATE);
                return self::result(self::INDETERMINATE, '', false, $message);
            }
        }

        $check['restored_by'] = $actor_id;
        $check['restored_at'] = $now;
        $check = Remote_Health_Operator_Check::sanitize($check);
        update_post_meta($video_id, Video_Meta::REMOTE_HEALTH_OPERATOR_CHECK, $check);
        if ($check !== Remote_Health_Operator_Check::sanitize(get_post_meta($video_id, Video_Meta::REMOTE_HEALTH_OPERATOR_CHECK, true))) {
            $message = 'The verified remote is usable, but the operator audit record could not be finalized.';
            $this->record_restore_refusal($video_id, $asset, $actor_id, $now, 'operator.restore.audit_write_failed', $message, self::INDETERMINATE);
            return self::result(self::INDETERMINATE, Serving_Viability::HEALTHY, false, $message);
        }
        $after = $this->health->find($remote_asset_id);
        if (null !== $this->notifications && is_array($after)) {
            $this->notifications->publication_observed($asset, $after, $now, false);
        }
        if (null !== $this->events) {
            $this->events->record(
                $video_id,
                7,
                'serving_health_operator_restored',
                'info',
                'A fresh visitor-facing check passed and an administrator selected this verified remote publication for serving.',
                $now,
                0,
                '',
                $remote_asset_id,
                (string) $asset['backend_id'],
                0,
                '',
                'Use verified remote now',
                array(
                    'serving_health' => 'operator_restored',
                    'operator_user_id' => $actor_id,
                    'serving_authority' => $adoption,
                )
            );
        }
        return self::result(self::APPLIED, Serving_Viability::HEALTHY, false, 'The fresh successful check was applied and the verified remote publication is available for serving.');
    }

    /** @param array<string,mixed>|null $row */
    private static function incident_checkable(?array $row): bool
    {
        if (! is_array($row)) {
            return false;
        }
        $status = (string) ($row['status'] ?? '');
        if (Serving_Viability::HEALTHY === $status) {
            return 0 === (int) ($row['eligible'] ?? 0);
        }
        return in_array($status, array(
            Serving_Viability::MISSING,
            Serving_Viability::PRIVATE_OR_RESTRICTED,
            Serving_Viability::EMBED_DISALLOWED,
            Serving_Viability::TEMPORARILY_UNAVAILABLE,
            Serving_Viability::PROBE_INDETERMINATE,
        ), true);
    }

    private function authority_missing_for_asset(int $video_id, int $remote_asset_id): bool
    {
        $authority = Video_Serving_Authority::sanitize(
            get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true)
        );
        return array() === $authority
            || $remote_asset_id !== (int) ($authority['remote_asset_id'] ?? 0);
    }

    /** @return array<string,mixed>|null */
    private function asset(int $video_id, int $remote_asset_id): ?array
    {
        $asset = $this->assets->find($remote_asset_id);
        $backend_id = is_array($asset) ? Backend_Identity::sanitize((string) ($asset['backend_id'] ?? '')) : '';
        return is_array($asset)
            && $video_id === (int) ($asset['video_post_id'] ?? 0)
            && '' !== $backend_id
            && Backend_Registry::LOCAL_ID !== $backend_id
            && 'ready' === (string) ($asset['state'] ?? '')
            && is_string($asset['embed_url'] ?? null)
            && '' !== (string) $asset['embed_url']
            ? $asset : null;
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $probe */
    private function record_check_event(int $video_id, array $asset, int $actor_id, int $now, array $probe, bool $durable): void
    {
        if (null === $this->events) {
            return;
        }
        $status = is_string($probe['viability_status'] ?? null) ? $probe['viability_status'] : Serving_Viability::PROBE_INDETERMINATE;
        $passed = $durable && Serving_Viability::HEALTHY === $status;
        $probe_message = is_string($probe['message'] ?? null) ? trim((string) $probe['message']) : '';
        $message = ! $durable
            ? 'An administrator requested a remote serving check, but the result could not be durably recorded.'
            : ($passed
                ? 'An administrator ran a fresh visitor-facing remote serving check and it passed.'
                : 'An administrator ran a fresh visitor-facing remote serving check and it failed.' . ('' !== $probe_message ? ' ' . $probe_message : ''));
        $this->events->record(
            $video_id,
            7,
            'serving_health_operator_check',
            $passed ? 'info' : 'warning',
            $message,
            $now,
            0,
            '',
            (int) $asset['id'],
            (string) $asset['backend_id'],
            is_int($probe['http_status'] ?? null) ? $probe['http_status'] : 0,
            '',
            'Check now',
            array(
                'serving_health' => $status,
                'check_result' => $passed ? 'passed' : 'failed',
                'reason_code' => is_string($probe['reason_code'] ?? null) ? (string) $probe['reason_code'] : '',
                'operator_user_id' => $actor_id,
            )
        );
    }

    /** @param array<string,mixed> $asset */
    private function record_restore_refusal(
        int $video_id,
        array $asset,
        int $actor_id,
        int $now,
        string $reason_code,
        string $message,
        string $result_status
    ): void {
        if (null === $this->events || $video_id < 1 || $actor_id < 1 || $now < 1) {
            return;
        }
        $this->events->record(
            $video_id,
            7,
            'serving_health_operator_restore_refused',
            'warning',
            'An administrator tried to use the freshly verified remote publication, but AWVP refused the change. ' . $message,
            $now,
            0,
            '',
            (int) ($asset['id'] ?? 0),
            (string) ($asset['backend_id'] ?? ''),
            0,
            '',
            'Use verified remote now',
            array(
                'serving_health' => 'restore_refused',
                'reason_code' => $reason_code,
                'result_status' => $result_status,
                'operator_user_id' => $actor_id,
            )
        );
    }

    /** @return array{status:string,viability_status:string,restore_available:bool,message:string} */
    private static function result(string $status, string $viability, bool $restore, string $message): array
    {
        return array('status' => $status, 'viability_status' => $viability, 'restore_available' => $restore, 'message' => $message);
    }
}
// EOF
