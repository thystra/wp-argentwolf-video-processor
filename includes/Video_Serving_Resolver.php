<?php
/** File: includes/Video_Serving_Resolver.php */
declare(strict_types=1);
namespace ArgentVideo;
interface Video_Serving_Resolver
{
    public function peertube_embed_url(int $video_id): string;
}
// EOF
