<?php
/**
 * File: includes/Backend_Secret_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

interface Backend_Secret_Store
{
    public function available(): bool;

    /** @return array<string, mixed>|null */
    public function read(string $secret_ref, string $backend_id): ?array;

    /**
     * Replace only when the caller's observed generation is still current.
     *
     * This generation precondition is a stale-write guard, not a cross-request
     * mutex. Token-refresh callers must serialize refresh work per secret
     * reference before relying on replace().
     *
     * @param array<string, mixed> $secret
     */
    public function replace(
        string $secret_ref,
        string $backend_id,
        array $secret,
        int $expected_generation
    ): bool;

    /**
     * Delete only when the caller's observed generation is still current.
     */
    public function delete(
        string $secret_ref,
        string $backend_id,
        int $expected_generation
    ): bool;
}

// EOF: includes/Backend_Secret_Store.php
