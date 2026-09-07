<?php
/** File: includes/PeerTube_Publication_Mutation_Api.php */
declare(strict_types=1);
namespace ArgentVideo;
interface PeerTube_Publication_Mutation_Api
{
    /** @param array<string,mixed> $manifest @param array<string,mixed>|null $thumbnail */
    public function update_publication(string $access_token, string $video_uuid, array $manifest, string $privacy_id, ?array $thumbnail = null): array;
    public function update_privacy(string $access_token, string $video_uuid, string $privacy_id): array;
    public function video_status(string $access_token, string $video_uuid): array;
    public function origin(): string;
}
// EOF
