<?php
/** File: includes/PeerTube_Publication_Asset_Store.php */
declare(strict_types=1);
namespace ArgentVideo;
interface PeerTube_Publication_Asset_Store
{
    /** @return array<string,mixed>|null */
    public function find(int $remote_asset_id): ?array;
    public function record_publication_observation(int $remote_asset_id, int $video_post_id, string $backend_id, string $remote_uuid, string $channel_id, string $privacy_id, int $now): string;
}
// EOF
