<?php
/**
 * File: includes/PeerTube_Staged_Upload_Api.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Narrow resumable-upload transport contract used by the staged uploader.
 * Implementations return only reviewed session/offset/video identity data.
 */
interface PeerTube_Staged_Upload_Api
{
    public function origin(): string;

    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function begin_resumable_upload(
        string $access_token,
        string $destination_id,
        string $name,
        string $filename,
        string $content_type,
        int $total_bytes
    ): array;

    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function upload_resumable_chunk(
        string $access_token,
        string $session_id,
        int $start,
        int $total_bytes,
        string $content_type,
        string $chunk
    ): array;

    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function upload_resumable_slice(
        string $access_token,
        string $session_id,
        int $start,
        int $total_bytes,
        string $content_type,
        PeerTube_Upload_Slice $slice
    ): array;

    /** @return array{ok:bool,data:array<string,mixed>|null,error:array<string,mixed>|null} */
    public function probe_resumable_upload(
        string $access_token,
        string $session_id,
        int $total_bytes
    ): array;
}

// EOF: includes/PeerTube_Staged_Upload_Api.php
