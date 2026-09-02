<?php
/**
 * File: includes/PeerTube_Connection_Admin_Actions.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Narrow server-side action boundary consumed by the administrator UI.
 */
interface PeerTube_Connection_Admin_Actions
{
    /** @param array<string, mixed> $intent
     *  @return array<string, mixed>
     */
    public function start(array $intent, int $actor_id, int $now): array;

    /** @return array<string, mixed> */
    public function resume(string $operation_id, int $now): array;

    /** @return array<string, mixed> */
    public function grant(
        string $operation_id,
        string $username,
        string $password,
        string $otp,
        int $now
    ): array;

    /** @return array<string, mixed> */
    public function reconcile(string $operation_id, int $now): array;

    /** @return array<string, mixed> */
    public function verify_identity(string $operation_id, int $now): array;

    /** @return array<string, mixed> */
    public function discover_destinations(string $operation_id, int $now): array;

    /** @return array<string, mixed> */
    public function select_destination(
        string $operation_id,
        string $destination_id,
        int $actor_id,
        int $now
    ): array;

    /** @return array<string, mixed> */
    public function activate(string $operation_id, int $now): array;

    /**
     * Return only the reviewed non-secret projection used by the page.
     *
     * @return list<array<string, mixed>>|null
     */
    public function open_operations(): ?array;
}

// EOF: includes/PeerTube_Connection_Admin_Actions.php
