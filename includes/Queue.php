<?php
/**
 * File: includes/Queue.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;

final class Queue
{
    public function __construct(private readonly Job_Repository $jobs)
    {
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
