<?php
/**
 * File: includes/Local_Backend_Adapter.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;

final class Local_Backend_Adapter implements Backend_Adapter
{
    public function __construct(
        private readonly Queue $queue,
        private readonly Diagnostics $diagnostics
    ) {
    }

    public function type(): string
    {
        return Backend_Registry::LOCAL_ID;
    }

    /** @return array<string, bool> */
    public function capabilities(): array
    {
        return Backend_Capabilities::local();
    }

    public function health(): Backend_Health
    {
        return Backend_Health::from_local_diagnostics($this->diagnostics->checks());
    }

    /**
     * Queue the linked local attachment through the existing 1.x engine.
     *
     * The caller supplies the already-resolved snapshot job profile. Profile
     * snapshot interpretation remains outside the adapter until the profile
     * tranche freezes that contract.
     */
    public function queue_video(
        int $video_post_id,
        string $job_profile,
        bool $force = false
    ): int {
        if (Video_Post_Type::POST_TYPE !== get_post_type($video_post_id)) {
            throw new RuntimeException('The supplied ID is not an AWVP Video.');
        }

        if (! self::valid_job_profile($job_profile)) {
            throw new RuntimeException('The supplied local job profile is invalid.');
        }

        $attachment_id = Video_Meta::sanitize_positive_id(
            get_post_meta($video_post_id, Video_Meta::ATTACHMENT_ID, true)
        );
        if ($attachment_id < 1) {
            throw new RuntimeException('The AWVP Video does not have a linked local attachment.');
        }

        return $this->queue->enqueue($attachment_id, $force, $job_profile);
    }

    private static function valid_job_profile(string $profile): bool
    {
        if ('adaptive-only' === $profile) {
            return true;
        }

        foreach (array('compatibility', 'dual', 'open') as $base) {
            if ($base === $profile || $base . '+hls' === $profile) {
                return true;
            }
        }

        return false;
    }
}

// EOF: includes/Local_Backend_Adapter.php
