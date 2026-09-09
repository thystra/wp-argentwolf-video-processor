<?php
/**
 * File: includes/PeerTube_Derivative_Cleanup_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/** Remove AWVP-generated/staged derivatives only after verified PeerTube serving. */
final class PeerTube_Derivative_Cleanup_Service
{
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';

    public function __construct(private readonly Video_Serving_Resolver $serving)
    {
    }

    public function cleanup(int $video_id): string
    {
        if ($video_id < 1 || '' === $this->serving->peertube_embed_url($video_id)) {
            return self::REFUSED;
        }
        $attachment_id = Video_Meta::sanitize_positive_id(
            get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true)
        );
        if ($attachment_id < 1) {
            return self::REFUSED;
        }
        $directory = Storage::attachment_directory($attachment_id);
        $had_directory = is_dir($directory);
        $had_outputs = metadata_exists('post', $attachment_id, '_argent_video_outputs');
        if (! $had_directory && ! $had_outputs) {
            return self::PRESENT;
        }
        try {
            if ($had_directory) {
                Storage::remove_tree($directory);
            }
            if ($had_outputs) {
                delete_post_meta($attachment_id, '_argent_video_outputs');
            }
        } catch (Throwable) {
            return self::INDETERMINATE;
        }
        if (is_dir($directory) || metadata_exists('post', $attachment_id, '_argent_video_outputs')) {
            return self::INDETERMINATE;
        }
        return self::APPLIED;
    }
}

// EOF: includes/PeerTube_Derivative_Cleanup_Service.php
