<?php
/**
 * File: includes/PeerTube_Remote_Asset_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Narrow durable store contract for the R44 remote-asset checkpoint.
 */
interface PeerTube_Remote_Asset_Store
{
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const CONFLICT = 'conflict';
    public const INDETERMINATE = 'indeterminate';

    /** @param array<string,mixed> $operation @return array{status:string,remote_asset_id:int} */
    public function commit_created(array $operation, int $now): array;

    /**
     * @param array<string,mixed> $operation
     * @param array<string,mixed> $observation
     */
    public function record_observation(
        int $remote_asset_id,
        array $operation,
        array $observation,
        int $now
    ): string;

    /** @return array<string,mixed>|null */
    public function find(int $remote_asset_id): ?array;
}

// EOF: includes/PeerTube_Remote_Asset_Store.php
