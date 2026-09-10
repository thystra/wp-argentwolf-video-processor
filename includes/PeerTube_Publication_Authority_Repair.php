<?php
/**
 * File: includes/PeerTube_Publication_Authority_Repair.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Bounded credential + publication-catalog repair for one active PeerTube server.
 *
 * A refresh-token mutation remains owned by PeerTube_Token_Lifecycle_Service.
 * This coordinator only advances that restart-safe journal to a settled state,
 * then refreshes the read-only catalog for the resulting secret generation.
 */
final class PeerTube_Publication_Authority_Repair
{
    private const TOKEN_SKEW_SECONDS = 60;
    private const MAX_REFRESH_STEPS = 4;

    public function __construct(
        private readonly PeerTube_Token_Lifecycle_Service $token_lifecycle,
        private readonly PeerTube_Publication_Catalog_Service $catalog_service,
        private readonly PeerTube_Publication_Catalog_Store $catalogs,
        private readonly Managed_Backend_Secret_Store $secrets,
        private readonly Backend_Registry $registry
    ) {
    }

    /** @return array{status:string,backend_id:string,retry_after:int} */
    public function repair(string $backend_id, int $now): array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id || $now < 1) {
            return self::result(PeerTube_Token_Lifecycle_Service::STATUS_REFUSED, $backend_id);
        }

        $descriptor = $this->descriptor($backend_id);
        if (null === $descriptor) {
            return self::result(PeerTube_Token_Lifecycle_Service::STATUS_REFUSED, $backend_id);
        }

        $secret = $this->secret($descriptor, $backend_id);
        if (! self::valid_secret($secret)) {
            return self::result(PeerTube_Token_Lifecycle_Service::STATUS_REAUTHENTICATION_REQUIRED, $backend_id);
        }

        if ($now > PHP_INT_MAX - self::TOKEN_SKEW_SECONDS) {
            return self::result(PeerTube_Token_Lifecycle_Service::STATUS_REFUSED, $backend_id);
        }

        if ($secret['access_expires_at'] <= $now + self::TOKEN_SKEW_SECONDS) {
            if ($secret['refresh_expires_at'] <= $now + self::TOKEN_SKEW_SECONDS) {
                return self::result(PeerTube_Token_Lifecycle_Service::STATUS_REAUTHENTICATION_REQUIRED, $backend_id);
            }

            $settled = false;
            for ($step = 0; $step < self::MAX_REFRESH_STEPS; ++$step) {
                $refresh = $this->token_lifecycle->refresh($backend_id, $now);
                $status = is_string($refresh['status'] ?? null) ? $refresh['status'] : '';
                if (PeerTube_Token_Lifecycle_Service::STATUS_COMPLETE === $status) {
                    $settled = true;
                    break;
                }
                if (PeerTube_Token_Lifecycle_Service::STATUS_ADVANCED === $status) {
                    continue;
                }
                if (PeerTube_Token_Lifecycle_Service::STATUS_WAIT === $status) {
                    return self::result(
                        $status,
                        $backend_id,
                        is_int($refresh['retry_after'] ?? null) ? (int) $refresh['retry_after'] : 0
                    );
                }
                if (in_array(
                    $status,
                    array(
                        PeerTube_Token_Lifecycle_Service::STATUS_REAUTHENTICATION_REQUIRED,
                        PeerTube_Token_Lifecycle_Service::STATUS_INDETERMINATE,
                        PeerTube_Token_Lifecycle_Service::STATUS_CONFLICT,
                    ),
                    true
                )) {
                    return self::result($status, $backend_id);
                }
                return self::result(PeerTube_Token_Lifecycle_Service::STATUS_REFUSED, $backend_id);
            }

            if (! $settled) {
                return self::result(PeerTube_Token_Lifecycle_Service::STATUS_WAIT, $backend_id);
            }

            // The token lifecycle writes through an authoritative atomic store.
            // Re-read after it settles so catalog authority binds to the new
            // credential generation, never the pre-refresh generation.
            $descriptor = $this->descriptor($backend_id);
            $secret = null === $descriptor ? null : $this->secret($descriptor, $backend_id);
            if (null === $descriptor || ! self::usable_access_secret($secret, $now)) {
                return self::result(PeerTube_Token_Lifecycle_Service::STATUS_INDETERMINATE, $backend_id);
            }
        }

        if (! self::usable_access_secret($secret, $now)) {
            return self::result(PeerTube_Token_Lifecycle_Service::STATUS_REAUTHENTICATION_REQUIRED, $backend_id);
        }

        $catalog = $this->catalogs->get_for_context_fresh(
            $backend_id,
            (string) $descriptor['config']['origin'],
            (int) $secret['generation']
        );
        if (is_array($catalog) && false === ($catalog['stale'] ?? true)) {
            return self::result(PeerTube_Token_Lifecycle_Service::STATUS_COMPLETE, $backend_id);
        }

        $refreshed = $this->catalog_service->refresh($backend_id, $now);
        if (PeerTube_Publication_Catalog_Service::COMPLETE !== ($refreshed['status'] ?? null)) {
            return self::result(
                PeerTube_Publication_Catalog_Service::CACHE_FAILED === ($refreshed['status'] ?? null)
                    ? PeerTube_Token_Lifecycle_Service::STATUS_INDETERMINATE
                    : PeerTube_Token_Lifecycle_Service::STATUS_WAIT,
                $backend_id
            );
        }

        $catalog = $this->catalogs->get_for_context_fresh(
            $backend_id,
            (string) $descriptor['config']['origin'],
            (int) $secret['generation']
        );
        return is_array($catalog) && false === ($catalog['stale'] ?? true)
            ? self::result(PeerTube_Token_Lifecycle_Service::STATUS_COMPLETE, $backend_id)
            : self::result(PeerTube_Token_Lifecycle_Service::STATUS_INDETERMINATE, $backend_id);
    }

    /** @return array<string,mixed>|null */
    private function descriptor(string $backend_id): ?array
    {
        $descriptor = $this->registry->get_fresh($backend_id);
        return is_array($descriptor)
            && Backend_Registry::PEERTUBE_TYPE === ($descriptor['type'] ?? null)
            && 'active' === ($descriptor['state'] ?? null)
            && is_string($descriptor['secret_ref'] ?? null)
            && '' !== $descriptor['secret_ref']
            && is_array($descriptor['config'] ?? null)
            && is_string($descriptor['config']['origin'] ?? null)
            && $descriptor['config']['origin'] === PeerTube_Origin::sanitize($descriptor['config']['origin'])
                ? $descriptor
                : null;
    }

    /** @param array<string,mixed> $descriptor @return array<string,mixed>|null */
    private function secret(array $descriptor, string $backend_id): ?array
    {
        try {
            return $this->secrets->read((string) $descriptor['secret_ref'], $backend_id);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string,mixed>|null $secret */
    private static function valid_secret(?array $secret): bool
    {
        return is_array($secret)
            && array('access_token','refresh_token','access_expires_at','refresh_expires_at','generation') === array_keys($secret)
            && is_string($secret['access_token']) && '' !== $secret['access_token']
            && is_string($secret['refresh_token']) && '' !== $secret['refresh_token']
            && is_int($secret['access_expires_at'])
            && is_int($secret['refresh_expires_at'])
            && is_int($secret['generation']) && $secret['generation'] > 0;
    }

    /** @param array<string,mixed>|null $secret */
    private static function usable_access_secret(?array $secret, int $now): bool
    {
        return self::valid_secret($secret)
            && $now <= PHP_INT_MAX - self::TOKEN_SKEW_SECONDS
            && $secret['access_expires_at'] > $now + self::TOKEN_SKEW_SECONDS;
    }

    /** @return array{status:string,backend_id:string,retry_after:int} */
    private static function result(string $status, string $backend_id, int $retry_after = 0): array
    {
        return array(
            'status' => $status,
            'backend_id' => $backend_id,
            'retry_after' => min(max($retry_after, 0), 86400),
        );
    }
}

// EOF: includes/PeerTube_Publication_Authority_Repair.php
