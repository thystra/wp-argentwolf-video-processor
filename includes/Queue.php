<?php
/**
 * File: includes/Queue.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;

final class Queue
{
    public function __construct(
        private readonly Job_Repository $jobs,
        private readonly ?Video_Publishing_Defaults_Store $publishing_defaults = null
    ) {
    }

    public function maybe_enqueue_attachment(int $attachment_id): void
    {
        if (! Settings::get('auto_queue', true)) {
            return;
        }

        $mime = (string) get_post_mime_type($attachment_id);
        if (! str_starts_with($mime, 'video/')) {
            return;
        }
        if (! $this->local_processing_allowed($attachment_id)) {
            return;
        }

        try {
            $this->enqueue($attachment_id, false);
        } catch (RuntimeException $error) {
            update_post_meta($attachment_id, '_argent_video_status', 'failed');
            update_post_meta($attachment_id, '_argent_video_last_error', $error->getMessage());
        }
    }

    public function enqueue(int $attachment_id, bool $force = false, ?string $profile = null): int
    {
        if ('attachment' !== get_post_type($attachment_id)) {
            throw new RuntimeException('The supplied ID is not an attachment.');
        }

        $mime = (string) get_post_mime_type($attachment_id);
        if (! str_starts_with($mime, 'video/')) {
            throw new RuntimeException('The attachment is not a video.');
        }
        if (! $this->local_processing_allowed($attachment_id)) {
            throw new RuntimeException('Local video processing is blocked by the selected final destination.');
        }

        if (class_exists(Local_Retention_Service::class) && Local_Retention_Service::attachment_local_processing_blocked($attachment_id)) {
            throw new RuntimeException('Local video processing is blocked by the current retention/cleanup state.');
        }

        $source = (string) get_attached_file($attachment_id, true);
        if ('' === $source || ! is_file($source)) {
            throw new RuntimeException('The attachment source file does not exist.');
        }

        $signature = hash('sha256', wp_normalize_path($source) . '|' . filesize($source) . '|' . filemtime($source));
        $job_profile = $profile ?? Settings::current_job_profile();
        $existing = $this->jobs->find_by_attachment($attachment_id);

        if (
            ! $force
            && is_array($existing)
            && 'complete' === ($existing['status'] ?? '')
            && hash_equals((string) $existing['source_signature'], $signature)
            && hash_equals((string) $existing['profile'], $job_profile)
        ) {
            $job_id = (int) $existing['id'];
            update_post_meta($attachment_id, '_argent_video_job_id', $job_id);
            update_post_meta($attachment_id, '_argent_video_source_signature', $signature);
            return $job_id;
        }

        $job_id = $this->jobs->enqueue($attachment_id, $source, $signature, $job_profile, $force);

        update_post_meta($attachment_id, '_argent_video_job_id', $job_id);
        update_post_meta($attachment_id, '_argent_video_status', 'queued');
        update_post_meta($attachment_id, '_argent_video_source_signature', $signature);
        delete_post_meta($attachment_id, '_argent_video_last_error');

        return $job_id;
    }


    /**
     * Resolve local-processing authority before creating/claiming FFmpeg work.
     *
     * A bound AWVP Video's concrete destination is authoritative. Before a
     * binding exists, the site default is the only available routing decision.
     * Missing publishing-default integration preserves the legacy local-only
     * behavior for narrow callers/tests that do not construct the 2.0 router.
     */
    public function local_processing_allowed(int $attachment_id): bool
    {
        if ($attachment_id < 1) {
            return false;
        }

        if (metadata_exists('post', $attachment_id, Video_Block_Editor_Service::ATTACHMENT_ASSET_META)) {
            $video_id = Video_Meta::sanitize_positive_id(
                get_post_meta($attachment_id, Video_Block_Editor_Service::ATTACHMENT_ASSET_META, true)
            );
            if ($video_id < 1) {
                return false;
            }
            $destination = Video_Destination::resolve(
                get_post_meta($video_id, Video_Meta::DESTINATION, true),
                metadata_exists('post', $video_id, Video_Meta::DESTINATION)
            );
            return array() !== $destination && Video_Destination::is_local($destination);
        }

        if (null === $this->publishing_defaults) {
            return true;
        }
        $settings = $this->publishing_defaults->get();
        if (! is_array($settings)) {
            return false;
        }
        $destination = Video_Destination::sanitize($settings['default_destination'] ?? null);
        return array() !== $destination && Video_Destination::is_local($destination);
    }

    public function delete_attachment(int $attachment_id): void
    {
        $directory = Storage::attachment_directory($attachment_id);
        if (is_dir($directory)) {
            Storage::remove_tree($directory);
        }

        $this->jobs->delete_by_attachment($attachment_id);
    }




}

// EOF: includes/Queue.php
