<?php
/**
 * File: includes/PeerTube_Remote_Reconciliation_Api.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Read-only PeerTube video-state surface used after a staged upload has
 * positively returned a remote identity. Implementations must not mutate the
 * remote video and must return only the reviewed identity/state projection.
 */
interface PeerTube_Remote_Reconciliation_Api
{
    public function origin(): string;

    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function video_status(string $access_token, string $video_uuid): array;
}

// EOF: includes/PeerTube_Remote_Reconciliation_Api.php
