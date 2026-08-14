<?php
/**
 * File: includes/Worker.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;
use Throwable;

final class Worker
{
    private const LOCK_OPTION = 'argent_video_processor_worker_lock';

    public function __construct(
        private readonly Job_Repository $jobs,
        private readonly Transcoder $transcoder
    ) {
    }

    /** @return array{processed:int,failed:int,recovered:int,errors:list<array{job_id:int,attachment_id:int,message:string}>} */
    public function run(int $limit = 1): array
    {
        $limit = max(1, min(25, $limit));
        $security = FFmpeg_Security::assess((string) Settings::get('ffmpeg_path', ''));
        if (empty($security['processing_allowed'])) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- RuntimeException text is not rendered output; the security gate message is preserved for diagnostics.
            throw new RuntimeException(FFmpeg_Security::blocking_message($security));
        }
        $token = wp_generate_uuid4();
        if (! $this->acquire_lock($token)) {
            throw new RuntimeException('Another Argent Video worker is already active.');
        }

        $processed = 0;
        $failed = 0;
        $recovered = 0;
        $errors = array();

        try {
            $recovered = $this->jobs->recover_stale((int) Settings::get('stale_job_minutes', 240));

            for ($index = 0; $index < $limit; $index++) {
                $job = $this->jobs->claim_next();
                if (null === $job) {
                    break;
                }

                $attachment_id = (int) $job['attachment_id'];
                update_post_meta($attachment_id, '_argent_video_status', 'processing');

                try {
                    $outputs = $this->transcoder->process($job);
                    $this->jobs->complete((int) $job['id'], $outputs);
                    delete_post_meta($attachment_id, '_argent_video_last_error');
                    $processed++;
                } catch (Throwable $error) {
                    $message = $error->getMessage();
                    $this->jobs->fail((int) $job['id'], $message);
                    update_post_meta($attachment_id, '_argent_video_status', 'failed');
                    update_post_meta($attachment_id, '_argent_video_last_error', $message);
                    $errors[] = array(
                        'job_id'        => (int) $job['id'],
                        'attachment_id' => $attachment_id,
                        'message'       => $message,
                    );
                    $failed++;
                }
            }

            update_option(
                'argent_video_processor_last_worker_run',
                array(
                    'time'      => current_time('mysql', true),
                    'processed' => $processed,
                    'failed'    => $failed,
                    'recovered' => $recovered,
                ),
                false
            );
        } finally {
            $this->release_lock($token);
        }

        return compact('processed', 'failed', 'recovered', 'errors');
    }

    public static function lock_is_active(): bool
    {
        $lock = get_option(self::LOCK_OPTION, array());
        if (! is_array($lock) || empty($lock['time'])) {
            return false;
        }

        $stale_seconds = (int) Settings::get('stale_job_minutes', 240) * MINUTE_IN_SECONDS;
        return (time() - (int) $lock['time']) < $stale_seconds;
    }

    private function acquire_lock(string $token): bool
    {
        $existing = get_option(self::LOCK_OPTION, array());
        if (is_array($existing) && ! empty($existing['time'])) {
            $stale_seconds = (int) Settings::get('stale_job_minutes', 240) * MINUTE_IN_SECONDS;
            if ((time() - (int) $existing['time']) >= $stale_seconds) {
                delete_option(self::LOCK_OPTION);
            }
        }

        return add_option(
            self::LOCK_OPTION,
            array('token' => $token, 'time' => time()),
            '',
            false
        );
    }

    private function release_lock(string $token): void
    {
        $lock = get_option(self::LOCK_OPTION, array());
        if (is_array($lock) && hash_equals((string) ($lock['token'] ?? ''), $token)) {
            delete_option(self::LOCK_OPTION);
        }
    }
}

// EOF: includes/Worker.php
