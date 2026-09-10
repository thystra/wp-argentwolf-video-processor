<?php
/**
 * File: includes/PeerTube_Publication_Catalog_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Explicit read-only refresh of one active PeerTube backend's publication choices. */
final class PeerTube_Publication_Catalog_Service
{
    public const COMPLETE = 'complete';
    public const REFUSED = 'refused';
    public const REMOTE_FAILED = 'remote_failed';
    public const CACHE_FAILED = 'cache_failed';

    private const TOKEN_SKEW_SECONDS = 60;

    /** @param callable(string):PeerTube_Publication_Catalog_Api $api_factory */
    public function __construct(
        private readonly PeerTube_Publication_Catalog_Store $store,
        private readonly Backend_Secret_Store $secrets,
        private readonly Backend_Registry $registry,
        private readonly mixed $api_factory
    ) {
    }

    /** @return array{status:string,catalog:array<string,mixed>|null} */
    public function refresh(string $backend_id, int $now): array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        $descriptor = '' !== $backend_id ? $this->registry->get($backend_id) : null;
        if (! self::active_descriptor($descriptor, $backend_id) || $now < 1) {
            return $this->failed(self::REFUSED, $backend_id, $now, 'refresh_refused');
        }

        $origin = $descriptor['config']['origin'];
        $secret_ref = $descriptor['secret_ref'];
        try {
            $secret = $this->secrets->read($secret_ref, $backend_id);
        } catch (\Throwable) {
            $secret = null;
        }
        if (! self::valid_secret($secret)
            || $now > PHP_INT_MAX - self::TOKEN_SKEW_SECONDS
            || $secret['access_expires_at'] <= $now + self::TOKEN_SKEW_SECONDS) {
            unset($secret);
            return $this->failed(self::REFUSED, $backend_id, $now, 'authentication_required');
        }
        $access_token = $secret['access_token'];
        $secret_generation = $secret['generation'];

        $cached_for_context = $this->store->get_for_context_fresh($backend_id, $origin, $secret_generation);
        if (null === $cached_for_context && null !== $this->store->get_fresh($backend_id)) {
            $this->store->mark_stale($backend_id, $now, 'backend_context_changed');
        }

        try {
            $api = ($this->api_factory)($origin);
        } catch (\Throwable) {
            unset($access_token, $secret);
            return $this->failed(self::REFUSED, $backend_id, $now, 'refresh_refused');
        }
        if (! $api instanceof PeerTube_Publication_Catalog_Api) {
            unset($access_token, $secret);
            return $this->failed(self::REFUSED, $backend_id, $now, 'refresh_refused');
        }

        try {
            $remote = $api->publication_catalog($access_token);
        } catch (\Throwable) {
            unset($access_token, $secret);
            return $this->failed(
                self::REMOTE_FAILED,
                $backend_id,
                $now,
                null === $cached_for_context ? 'backend_context_changed' : 'remote_failed'
            );
        }
        unset($access_token, $secret);
        if (! ($remote['ok'] ?? false) || ! is_array($remote['data'] ?? null)) {
            return $this->failed(
                self::REMOTE_FAILED,
                $backend_id,
                $now,
                null === $cached_for_context ? 'backend_context_changed' : 'remote_failed'
            );
        }

        $candidate = array(
            'version'           => PeerTube_Publication_Catalog::VERSION,
            'backend_id'        => $backend_id,
            'origin'            => $origin,
            'secret_generation' => $secret_generation,
            'server_version'    => $remote['data']['server_version'] ?? null,
            'refreshed_at'      => $now,
            'stale'             => false,
            'stale_since'       => null,
            'stale_reason'      => '',
            'channels'          => $remote['data']['channels'] ?? null,
            'privacies'         => $remote['data']['privacies'] ?? null,
            'licences'          => $remote['data']['licences'] ?? null,
            'categories'        => $remote['data']['categories'] ?? null,
            'languages'         => $remote['data']['languages'] ?? null,
            'capabilities'      => $remote['data']['capabilities'] ?? null,
        );
        $candidate = PeerTube_Publication_Catalog::sanitize($candidate);
        if (array() === $candidate) {
            return $this->failed(
                self::REMOTE_FAILED,
                $backend_id,
                $now,
                null === $cached_for_context ? 'backend_context_changed' : 'remote_failed'
            );
        }
        if (! $this->store->save_last_known_good($backend_id, $candidate)) {
            return $this->failed(self::CACHE_FAILED, $backend_id, $now, 'cache_failed');
        }
        return array('status'=>self::COMPLETE,'catalog'=>$candidate);
    }

    /** @param array<string,mixed>|null $descriptor */
    private static function active_descriptor(?array $descriptor, string $backend_id): bool
    {
        return is_array($descriptor)
            && $backend_id === ($descriptor['id'] ?? null)
            && Backend_Registry::PEERTUBE_TYPE === ($descriptor['type'] ?? null)
            && 'active' === ($descriptor['state'] ?? null)
            && is_string($descriptor['secret_ref'] ?? null)
            && '' !== $descriptor['secret_ref']
            && is_array($descriptor['config'] ?? null)
            && is_string($descriptor['config']['origin'] ?? null)
            && $descriptor['config']['origin'] === PeerTube_Origin::sanitize($descriptor['config']['origin']);
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

    /** @return array{status:string,catalog:array<string,mixed>|null} */
    private function failed(string $status, string $backend_id, int $now, string $reason): array
    {
        $catalog = $now >= 1 ? $this->store->mark_stale($backend_id, $now, $reason) : $this->store->get($backend_id);
        return array('status'=>$status,'catalog'=>$catalog);
    }
}

// EOF: includes/PeerTube_Publication_Catalog_Service.php
