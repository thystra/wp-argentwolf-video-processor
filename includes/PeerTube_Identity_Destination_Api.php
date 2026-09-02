<?php
/**
 * File: includes/PeerTube_Identity_Destination_Api.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Narrow read-only API authority for connection identity and destinations.
 */
interface PeerTube_Identity_Destination_Api
{
    public function origin(): string;

    /**
     * Verify the bearer identity and return only its bounded local owned
     * channel projections. Implementations persist nothing.
     *
     * @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null}
     */
    public function owned_channels(string $access_token): array;
}

// EOF: includes/PeerTube_Identity_Destination_Api.php
