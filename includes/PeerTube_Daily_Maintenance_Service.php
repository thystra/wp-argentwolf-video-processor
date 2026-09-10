<?php
/**
 * File: includes/PeerTube_Daily_Maintenance_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Daily credential/catalog maintenance for active PeerTube backends. */
final class PeerTube_Daily_Maintenance_Service
{
    public function __construct(
        private readonly Backend_Registry $registry,
        private readonly PeerTube_Publication_Authority_Repair $authority,
        private readonly PeerTube_Publication_Catalog_Service $catalogs,
        private readonly Backend_Maintenance_Status_Store $status
    ) {
    }

    public function run(?int $now = null): int
    {
        $now = $now ?? time();
        if ($now < 1) {
            return 0;
        }
        $count = 0;
        foreach ($this->registry->all() as $backend_id => $descriptor) {
            if (! is_string($backend_id) || ! is_array($descriptor)
                || Backend_Registry::PEERTUBE_TYPE !== ($descriptor['type'] ?? null)
                || 'active' !== ($descriptor['state'] ?? null)) {
                continue;
            }
            ++$count;
            $repair = $this->authority->repair($backend_id, $now);
            $repair_status = is_string($repair['status'] ?? null) ? $repair['status'] : '';
            if (PeerTube_Token_Lifecycle_Service::STATUS_COMPLETE !== $repair_status) {
                $severity = in_array($repair_status, array(
                    PeerTube_Token_Lifecycle_Service::STATUS_REAUTHENTICATION_REQUIRED,
                    PeerTube_Token_Lifecycle_Service::STATUS_CONFLICT,
                ), true) ? Backend_Maintenance_Status_Store::ERROR : Backend_Maintenance_Status_Store::WARNING;
                $this->status->record(
                    $backend_id,
                    $severity,
                    'peertube.maintenance.' . self::safe_code($repair_status, 'authority_unavailable'),
                    self::repair_message($repair_status),
                    $now
                );
                continue;
            }

            // Refresh daily even when the generation-bound cache is already
            // usable. This catches server-side channel/category/language/etc.
            // changes rather than waiting for credentials to change.
            $refresh = $this->catalogs->refresh($backend_id, $now);
            $catalog_status = is_string($refresh['status'] ?? null) ? $refresh['status'] : '';
            if (PeerTube_Publication_Catalog_Service::COMPLETE !== $catalog_status) {
                $this->status->record(
                    $backend_id,
                    Backend_Maintenance_Status_Store::WARNING,
                    'peertube.maintenance.' . self::safe_code($catalog_status, 'catalog_refresh_failed'),
                    'AWVP could not refresh the PeerTube publishing options. The last valid cached choices are preserved and should be treated as stale until a refresh succeeds.',
                    $now
                );
                continue;
            }

            $this->status->record(
                $backend_id,
                Backend_Maintenance_Status_Store::HEALTHY,
                'peertube.maintenance.current',
                'PeerTube credentials and publishing options are current.',
                $now
            );
        }
        return $count;
    }

    private static function repair_message(string $status): string
    {
        return match ($status) {
            PeerTube_Token_Lifecycle_Service::STATUS_REAUTHENTICATION_REQUIRED => 'The PeerTube server must be reconnected because the saved authorization can no longer be refreshed.',
            PeerTube_Token_Lifecycle_Service::STATUS_WAIT => 'AWVP could not complete the daily PeerTube credential/catalog refresh. It will retry through normal maintenance/recovery.',
            PeerTube_Token_Lifecycle_Service::STATUS_INDETERMINATE => 'AWVP could not verify the result of the daily PeerTube credential/catalog refresh.',
            PeerTube_Token_Lifecycle_Service::STATUS_CONFLICT => 'The saved PeerTube credential/catalog authority is inconsistent and needs administrator review.',
            default => 'AWVP could not complete the daily PeerTube credential/catalog maintenance check.',
        };
    }

    private static function safe_code(string $value, string $fallback): string
    {
        return 1 === preg_match('/^[a-z0-9_.:-]{1,48}$/D', $value) ? $value : $fallback;
    }
}

// EOF
