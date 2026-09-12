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
        private readonly ?Remote_Health_Notification_Service $notifications = null
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
        if (! self::incident_checkable($before)) {
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
                ? ($restore ? 'The remote publication passed a fresh visitor-facing check. You may restore remote serving now.' : 'The remote publication passed a fresh visitor-facing check.')
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
        return is_array($row)
            && $video_id === (int) ($row['video_post_id'] ?? 0)
            && $check['backend_id'] === (string) ($row['backend_id'] ?? '')
            && Serving_Viability::HEALTHY === (string) ($row['status'] ?? '')
            && 0 === (int) ($row['eligible'] ?? 0)
            && (int) ($row['success_streak'] ?? 0) >= 1
            && gmdate('Y-m-d H:i:s', (int) $check['checked_at']) === (string) ($row['last_checked_at'] ?? '');
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
            return self::result(self::PRESENT, Serving_Viability::HEALTHY, false, 'Remote serving was already restored from this operator check.');
        }
        if (! $this->restore_available($video_id, $remote_asset_id, $now)) {
            return self::result(self::REFUSED, '', false, 'A recent successful operator check is required before remote serving can be restored immediately.');
        }
        $expected = gmdate('Y-m-d H:i:s', (int) $check['checked_at']);
        $written = $this->health->operator_restore_eligible(
            $remote_asset_id,
            $video_id,
            (string) $check['backend_id'],
            $expected,
            $now
        );
        if (Remote_Publication_Health_Repository::PRESENT === $written) {
            return self::result(self::PRESENT, Serving_Viability::HEALTHY, false, 'Remote serving became eligible before this restore request needed to change it.');
        }
        if (Remote_Publication_Health_Repository::APPLIED !== $written) {
            return self::result(self::INDETERMINATE, '', false, 'Serving eligibility changed while the restore was being applied. No blind override was performed.');
        }
        $check['restored_by'] = $actor_id;
        $check['restored_at'] = $now;
        $check = Remote_Health_Operator_Check::sanitize($check);
        update_post_meta($video_id, Video_Meta::REMOTE_HEALTH_OPERATOR_CHECK, $check);
        if ($check !== Remote_Health_Operator_Check::sanitize(get_post_meta($video_id, Video_Meta::REMOTE_HEALTH_OPERATOR_CHECK, true))) {
            return self::result(self::INDETERMINATE, Serving_Viability::HEALTHY, false, 'Remote serving is eligible again, but the operator audit record could not be finalized.');
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
                'A fresh visitor-facing check passed and an administrator restored this remote publication to serving eligibility.',
                $now,
                0,
                '',
                $remote_asset_id,
                (string) $asset['backend_id'],
                0,
                '',
                'Restore remote serving now',
                array('serving_health' => 'operator_restored', 'operator_user_id' => $actor_id)
            );
        }
        return self::result(self::APPLIED, Serving_Viability::HEALTHY, false, 'Remote serving eligibility was restored from the fresh successful check.');
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
        $message = $durable
            ? 'An administrator ran a fresh visitor-facing remote serving check.'
            : 'An administrator requested a remote serving check, but the result could not be durably recorded.';
        $this->events->record(
            $video_id,
            7,
            'serving_health_operator_check',
            $durable ? 'info' : 'warning',
            $message,
            $now,
            0,
            '',
            (int) $asset['id'],
            (string) $asset['backend_id'],
            is_int($probe['http_status'] ?? null) ? $probe['http_status'] : 0,
            '',
            'Check now',
            array('serving_health' => $status, 'operator_user_id' => $actor_id)
        );
    }

    /** @return array{status:string,viability_status:string,restore_available:bool,message:string} */
    private static function result(string $status, string $viability, bool $restore, string $message): array
    {
        return array('status' => $status, 'viability_status' => $viability, 'restore_available' => $restore, 'message' => $message);
    }
}
// EOF
