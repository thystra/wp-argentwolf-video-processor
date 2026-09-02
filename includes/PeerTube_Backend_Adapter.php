<?php
/**
 * File: includes/PeerTube_Backend_Adapter.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use Throwable;

/**
 * Narrow R40 PeerTube adapter registration and health boundary.
 *
 * This checkpoint intentionally exposes no upload/create/update/delete API.
 * Its capability map permits only non-mutating managed-video embed routing;
 * media-transfer and remote-management capabilities remain false until their
 * dedicated implementation tranches land.
 */
final class PeerTube_Backend_Adapter implements Backend_Adapter
{
    private const TOKEN_SKEW_SECONDS = 60;

    /** @var Closure():int */
    private Closure $clock;

    public function __construct(
        private readonly Managed_Backend_Secret_Store $secrets,
        ?callable $clock = null
    ) {
        $this->clock = null === $clock
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
    }

    public function type(): string
    {
        return Backend_Registry::PEERTUBE_TYPE;
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        return Backend_Capabilities::peertube_activation();
    }

    /** @param array<string, mixed> $descriptor */
    public function health(array $descriptor): Backend_Health
    {
        if (! self::valid_active_descriptor($descriptor)) {
            return Backend_Health::peertube_blocking('descriptor_invalid');
        }

        try {
            $secret = $this->secrets->read(
                $descriptor['secret_ref'],
                $descriptor['id']
            );
        } catch (Throwable) {
            return Backend_Health::peertube_blocking('secret_unavailable');
        }

        if (! self::valid_secret_projection($secret)) {
            return Backend_Health::peertube_blocking('secret_unavailable');
        }

        $now = $this->now();
        if (
            $now < 1
            || $now > PHP_INT_MAX - self::TOKEN_SKEW_SECONDS
            || $secret['access_expires_at'] <= $now + self::TOKEN_SKEW_SECONDS
        ) {
            unset($secret);
            return Backend_Health::peertube_blocking('access_token_unusable');
        }

        $generation = $secret['generation'];
        unset($secret);

        return Backend_Health::peertube_activation_ready($generation);
    }

    /** @param array<string, mixed> $descriptor */
    private static function valid_active_descriptor(array $descriptor): bool
    {
        return Backend_Registry::PEERTUBE_TYPE === ($descriptor['type'] ?? null)
            && 'active' === ($descriptor['state'] ?? null)
            && is_string($descriptor['id'] ?? null)
            && '' !== $descriptor['id']
            && $descriptor['id'] === Backend_Identity::sanitize($descriptor['id'])
            && 'local' !== $descriptor['id']
            && is_string($descriptor['default_destination'] ?? null)
            && '' !== $descriptor['default_destination']
            && $descriptor['default_destination']
                === PeerTube_Connection_Input::destination_id($descriptor['default_destination'])
            && is_string($descriptor['secret_ref'] ?? null)
            && $descriptor['secret_ref']
                === Backend_Identity::sanitize_opaque($descriptor['secret_ref'], 191)
            && 1 === ($descriptor['config_version'] ?? null)
            && is_array($descriptor['config'] ?? null)
            && array('origin') === array_keys($descriptor['config'])
            && is_string($descriptor['config']['origin'])
            && $descriptor['config']['origin'] === PeerTube_Origin::sanitize($descriptor['config']['origin']);
    }

    /** @param array<string, mixed>|null $secret */
    private static function valid_secret_projection(?array $secret): bool
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
            && is_int($secret['access_expires_at'])
            && is_int($secret['generation'])
            && $secret['generation'] > 0;
    }

    private function now(): int
    {
        try {
            $now = ($this->clock)();
            return is_int($now) && $now > 0 ? $now : 0;
        } catch (Throwable) {
            return 0;
        }
    }
}

// EOF: includes/PeerTube_Backend_Adapter.php
