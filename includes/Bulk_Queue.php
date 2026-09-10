<?php
/**
 * File: includes/Bulk_Queue.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;
use Throwable;

final class Bulk_Queue
{
    public function __construct(
        private readonly Job_Repository $jobs,
        private readonly Queue $queue
    ) {
    }

    /** @return array{total:int,complete:int,missing_hls:int,active:int,smart_candidates:int} */
    public function summary(): array
    {
        $summary = array('total' => 0, 'complete' => 0, 'missing_hls' => 0, 'active' => 0, 'smart_candidates' => 0);
        foreach ($this->attachment_ids('', '', 0) as $attachment_id) {
            $summary['total']++;
            $status = (string) get_post_meta($attachment_id, '_argent_video_status', true);
            $outputs = get_post_meta($attachment_id, '_argent_video_outputs', true);
            $outputs = is_array($outputs) ? $outputs : array();
            if ('complete' === $status && [] !== $outputs) {
                $summary['complete']++;
            }
            if ([] !== $outputs && empty($outputs['hls'])) {
                $summary['missing_hls']++;
            }
            $job = $this->jobs->find_by_attachment($attachment_id);
            if (is_array($job) && in_array((string) $job['status'], array('queued', 'processing'), true)) {
                $summary['active']++;
            } elseif ($this->queue->local_processing_allowed($attachment_id) && $this->smart_profile($outputs) !== null) {
                $summary['smart_candidates']++;
            }
        }
        return $summary;
    }

    /**
     * @return array{queued:int,skipped:int,failed:int,errors:list<string>}
     */
    public function queue(string $mode, string $after = '', string $through = '', int $limit = 0): array
    {
        if (! in_array($mode, array('smart', 'adaptive', 'all'), true)) {
            throw new RuntimeException('Unsupported bulk queue mode.');
        }

        $result = array('queued' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => array());
        foreach ($this->attachment_ids($after, $through, $limit) as $attachment_id) {
            $job = $this->jobs->find_by_attachment($attachment_id);
            if (is_array($job) && in_array((string) $job['status'], array('queued', 'processing'), true)) {
                $result['skipped']++;
                continue;
            }
            if (! $this->queue->local_processing_allowed($attachment_id)) {
                $result['skipped']++;
                continue;
            }

            $outputs = get_post_meta($attachment_id, '_argent_video_outputs', true);
            $outputs = is_array($outputs) ? $outputs : array();
            $profile = Settings::current_job_profile();
            $force = 'all' === $mode;

            if ('adaptive' === $mode) {
                if ([] === $outputs || ! empty($outputs['hls'])) {
                    $result['skipped']++;
                    continue;
                }
                $profile = 'adaptive-only';
                $force = true;
            } elseif ('smart' === $mode) {
                $profile = $this->smart_profile($outputs);
                if (null === $profile) {
                    $result['skipped']++;
                    continue;
                }
                $force = 'adaptive-only' === $profile;
            }

            try {
                $this->queue->enqueue($attachment_id, $force, $profile);
                $result['queued']++;
            } catch (Throwable $error) {
                $result['failed']++;
                if (count($result['errors']) < 10) {
                    $result['errors'][] = 'Attachment ' . $attachment_id . ': ' . $error->getMessage();
                }
            }
        }

        return $result;
    }

    /** @param array<string, mixed> $outputs */
    private function smart_profile(array $outputs): ?string
    {
        if (Settings::get('adaptive_hls', true)) {
            if (! empty($outputs['hls'])) {
                return null;
            }
            if (! empty($outputs['mp4']) || ! empty($outputs['webm'])) {
                return 'adaptive-only';
            }
            return Settings::current_job_profile();
        }

        return (! empty($outputs['mp4']) || ! empty($outputs['webm'])) ? null : Settings::current_job_profile();
    }

    /** @return list<int> */
    private function attachment_ids(string $after, string $through, int $limit): array
    {
        $args = array(
            'post_type'              => 'attachment',
            'post_status'            => 'inherit',
            'post_mime_type'         => 'video',
            'posts_per_page'         => $limit > 0 ? min(5000, $limit) : -1,
            'fields'                 => 'ids',
            'orderby'                => 'ID',
            'order'                  => 'ASC',
            'no_found_rows'          => true,
            'update_post_meta_cache' => false,
            'update_post_term_cache' => false,
        );
        $date_query = array();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $after)) {
            $date_query[] = array('after' => $after, 'inclusive' => true);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $through)) {
            $date_query[] = array('before' => $through . ' 23:59:59', 'inclusive' => true);
        }
        if ([] !== $date_query) {
            $args['date_query'] = $date_query;
        }

        $query = new \WP_Query($args);
        return array_map('intval', is_array($query->posts) ? $query->posts : array());
    }
}

// EOF: includes/Bulk_Queue.php
