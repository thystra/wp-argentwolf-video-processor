<?php
/**
 * File: includes/PeerTube_Connection_Admin_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * Adapts the restart-safe coordinator and grant service to the admin boundary.
 */
final class PeerTube_Connection_Admin_Service implements PeerTube_Connection_Admin_Actions
{
    public function __construct(
        private readonly PeerTube_Connection_Operation_Store $operations,
        private readonly PeerTube_Connection_Coordinator $coordinator,
        private readonly PeerTube_Password_Grant_Service $grants,
        private readonly PeerTube_Identity_Destination_Service $identity_destinations
    ) {
    }

    public function start(array $intent, int $actor_id, int $now): array
    {
        return $this->coordinator->start($intent, $actor_id, $now);
    }

    public function resume(string $operation_id, int $now): array
    {
        return $this->coordinator->resume($operation_id, $now);
    }

    public function grant(
        string $operation_id,
        string $username,
        string $password,
        string $otp,
        int $now
    ): array {
        return $this->grants->submit($operation_id, $username, $password, $otp, $now);
    }

    public function reconcile(string $operation_id, int $now): array
    {
        return $this->grants->reconcile($operation_id, $now);
    }

    public function verify_identity(string $operation_id, int $now): array
    {
        return $this->identity_destinations->advance($operation_id, $now);
    }

    public function discover_destinations(string $operation_id, int $now): array
    {
        return $this->identity_destinations->discover($operation_id, $now);
    }

    public function select_destination(
        string $operation_id,
        string $destination_id,
        int $actor_id,
        int $now
    ): array {
        return $this->identity_destinations->select(
            $operation_id,
            $destination_id,
            $actor_id,
            $now
        );
    }

    public function open_operations(): ?array
    {
        try {
            $records = $this->operations->open_operations();
            if (null === $records) {
                return null;
            }

            $projections = array();
            foreach ($records as $record) {
                if (! PeerTube_Connection_State_Machine::valid($record)) {
                    return null;
                }

                $projections[] = array(
                    'operation_id'     => $record['operation_id'],
                    'backend_id'       => $record['backend_id'],
                    'origin'           => $record['origin'],
                    'label'            => $record['label'],
                    'phase'            => $record['phase'],
                    'record_revision'  => $record['record_revision'],
                    'grant_attempt_no' => $record['grant_attempt_no'],
                    'retry_after'      => min(
                        max((int) ($record['last_error']['retry_after'] ?? 0), 0),
                        86400
                    ),
                    'created_at'       => $record['created_at'],
                    'updated_at'       => $record['updated_at'],
                );
            }

            usort(
                $projections,
                static fn (array $left, array $right): int =>
                    $right['updated_at'] <=> $left['updated_at']
                    ?: strcmp($left['operation_id'], $right['operation_id'])
            );

            return $projections;
        } catch (Throwable) {
            return null;
        }
    }
}

// EOF: includes/PeerTube_Connection_Admin_Service.php
