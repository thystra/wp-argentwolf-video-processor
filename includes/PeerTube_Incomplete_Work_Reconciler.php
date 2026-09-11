<?php
/**
 * File: includes/PeerTube_Incomplete_Work_Reconciler.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Recover publication lifecycle records whose durable task enqueue never
 * converged. Remote-mutation journals are deliberately outside this class.
 */
final class PeerTube_Incomplete_Work_Reconciler
{
    public const DEFAULT_WINDOW_SECONDS = 86400; // 24 hours.
    public const MAX_WINDOW_SECONDS = 604800; // 168 hours.
    public const MAX_SCAN = 250;

    public function __construct(
        private readonly PeerTube_Publication_Synchronizer $synchronizer,
        private readonly ?PeerTube_Serving_Cutover_Service $cutover = null,
        private readonly ?PeerTube_Publication_Finalizer_Recovery $finalizer_recovery = null
    ) {
    }

    public function recover(?int $now = null): int
    {
        $now = $now ?? time();
        if ($now < 1) {
            return 0;
        }

        // Keep the one-minute recovery pass bounded without a postmeta SQL filter.
        // Candidate lifecycle state is checked in PHP below, avoiding an expensive
        // meta_key query while still covering the site-sized RC9 recovery horizon.
        $ids = get_posts(array(
            'post_type'      => Video_Post_Type::POST_TYPE,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => self::MAX_SCAN,
            'orderby'        => 'modified',
            'order'          => 'DESC',
        ));
        if (! is_array($ids)) {
            return 0;
        }

        $recovered = 0;
        foreach ($ids as $raw_id) {
            $video_id = Video_Meta::sanitize_positive_id($raw_id);
            if ($video_id < 1) {
                continue;
            }
            $video_recovered = false;
            self::refresh_post_meta_cache($video_id);

            // RC10 local-only reconciliation is deliberately independent of the
            // lifecycle task_pending bit. RC9 could durably enqueue/fail its
            // finalizer after the remote publication was already verified,
            // leaving no pending lifecycle enqueue for the recovery hook to see.
            // The cutover service has no provider HTTP/mutation authority; it
            // can only derive/repair local serving authority from durable
            // applied-manifest and verified remote-asset evidence.
            if (null !== $this->cutover) {
                $cutover = $this->cutover->reconcile($video_id, $now);
                if (PeerTube_Serving_Cutover_Service::APPLIED === $cutover) {
                    delete_post_meta($video_id, Video_Meta::PEERTUBE_RECOVERY_WINDOW);
                    $video_recovered = true;
                }
            }

            if (null !== $this->finalizer_recovery) {
                $finalizer = $this->finalizer_recovery->status($video_id);
                if (PeerTube_Publication_Finalizer_Recovery::MISSING === ($finalizer['status'] ?? null)) {
                    $restored = $this->finalizer_recovery->restore_missing($video_id, $now);
                    if (in_array($restored, array(Task_Repository::APPLIED, Task_Repository::PRESENT), true)) {
                        delete_post_meta($video_id, Video_Meta::PEERTUBE_RECOVERY_WINDOW);
                        $video_recovered = true;
                    }
                }
            }

            $state = $this->status($video_id, $now, true);
            if (! $video_recovered && true === ($state['eligible'] ?? false)
                && 'finalizer_missing' !== ($state['status'] ?? null)) {
                $anchor_id = (int) ($state['anchor_post_id'] ?? 0);
                self::refresh_post_cache($anchor_id);
                $anchor = $anchor_id > 0 ? get_post($anchor_id) : null;
                if (is_object($anchor) && is_string($anchor->post_status ?? null)) {
                    $result = $this->synchronizer->sync_video($video_id, (string) $anchor->post_status, $now);
                    if (in_array($result['status'] ?? null, array(Task_Repository::APPLIED, Task_Repository::PRESENT), true)) {
                        delete_post_meta($video_id, Video_Meta::PEERTUBE_RECOVERY_WINDOW);
                        $video_recovered = true;
                    }
                }
            }

            if ($video_recovered) {
                ++$recovered;
            }
        }
        return $recovered;
    }

    /** @return array{status:string,pending:bool,eligible:bool,resumable:bool,origin_at:int,expires_at:int,anchor_post_id:int} */
    public function status(int $video_id, ?int $now = null, bool $initialize = false): array
    {
        $now = $now ?? time();
        $empty = self::status_result('none');
        if ($video_id < 1 || $now < 1) {
            return $empty;
        }
        self::refresh_post_meta_cache($video_id);
        $lifecycle = PeerTube_Publication_Lifecycle::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, true)
        );
        if (array() === $lifecycle || true !== ($lifecycle['task_pending'] ?? false)) {
            if (metadata_exists('post', $video_id, Video_Meta::PEERTUBE_RECOVERY_WINDOW)) {
                delete_post_meta($video_id, Video_Meta::PEERTUBE_RECOVERY_WINDOW);
            }
            if (null !== $this->finalizer_recovery) {
                $retry = $this->finalizer_recovery->status($video_id);
                if (PeerTube_Publication_Finalizer_Recovery::RESUMABLE === ($retry['status'] ?? null)) {
                    return self::status_result(
                        'finalizer_retry',
                        true,
                        false,
                        true,
                        0,
                        0,
                        (int) ($retry['anchor_post_id'] ?? 0)
                    );
                }
                if (PeerTube_Publication_Finalizer_Recovery::MISSING === ($retry['status'] ?? null)) {
                    return self::status_result(
                        'finalizer_missing',
                        true,
                        true,
                        false,
                        (int) ($lifecycle['updated_at'] ?? 0),
                        0,
                        (int) ($retry['anchor_post_id'] ?? 0)
                    );
                }
                if (PeerTube_Publication_Finalizer_Recovery::TERMINAL === ($retry['status'] ?? null)) {
                    return self::status_result(
                        'finalizer_terminal',
                        true,
                        false,
                        false,
                        (int) ($lifecycle['updated_at'] ?? 0),
                        0,
                        (int) ($retry['anchor_post_id'] ?? 0)
                    );
                }
            }
            return $empty;
        }

        $origin = (int) ($lifecycle['updated_at'] ?? 0);
        if ($origin < 1 || $origin > $now) {
            return self::status_result('invalid', true, false, false, 0, 0, (int) ($lifecycle['anchor_post_id'] ?? 0));
        }
        $window = metadata_exists('post', $video_id, Video_Meta::PEERTUBE_RECOVERY_WINDOW)
            ? Video_Meta::sanitize_peertube_recovery_window(
                get_post_meta($video_id, Video_Meta::PEERTUBE_RECOVERY_WINDOW, true)
            )
            : array();
        if (array() === $window) {
            $window = array('version' => 1, 'origin_at' => $origin, 'resumed_at' => 0);
            if ($initialize) {
                update_post_meta($video_id, Video_Meta::PEERTUBE_RECOVERY_WINDOW, $window);
                $stored = Video_Meta::sanitize_peertube_recovery_window(
                    get_post_meta($video_id, Video_Meta::PEERTUBE_RECOVERY_WINDOW, true)
                );
                if ($stored !== $window) {
                    return self::status_result('indeterminate', true, false, false, $origin, 0, (int) $lifecycle['anchor_post_id']);
                }
            }
        }
        $origin_at = (int) $window['origin_at'];
        if ($origin_at < 1 || $origin_at > $origin) {
            return self::status_result('invalid', true, false, false, $origin_at, 0, (int) $lifecycle['anchor_post_id']);
        }
        if ($now > $origin_at + self::MAX_WINDOW_SECONDS) {
            return self::status_result('expired', true, false, false, $origin_at, $origin_at + self::MAX_WINDOW_SECONDS, (int) $lifecycle['anchor_post_id']);
        }
        $resumed_at = (int) $window['resumed_at'];
        $window_start = $resumed_at > 0 ? $resumed_at : $origin_at;
        $expires_at = min($origin_at + self::MAX_WINDOW_SECONDS, $window_start + self::DEFAULT_WINDOW_SECONDS);
        $eligible = $now <= $expires_at;
        return self::status_result(
            $eligible ? 'recovering' : 'needs_attention',
            true,
            $eligible,
            ! $eligible && $now <= $origin_at + self::MAX_WINDOW_SECONDS,
            $origin_at,
            $expires_at,
            (int) $lifecycle['anchor_post_id']
        );
    }

    public function resume(int $video_id, int $now): bool
    {
        if (null !== $this->finalizer_recovery) {
            $retry = $this->finalizer_recovery->status($video_id);
            if (PeerTube_Publication_Finalizer_Recovery::RESUMABLE === ($retry['status'] ?? null)) {
                return in_array(
                    $this->finalizer_recovery->resume($video_id, $now),
                    array(Task_Repository::APPLIED, Task_Repository::PRESENT),
                    true
                );
            }
        }
        $state = $this->status($video_id, $now, true);
        if (true !== ($state['pending'] ?? false) || true !== ($state['resumable'] ?? false)) {
            return false;
        }
        $window = array(
            'version' => 1,
            'origin_at' => (int) $state['origin_at'],
            'resumed_at' => $now,
        );
        update_post_meta($video_id, Video_Meta::PEERTUBE_RECOVERY_WINDOW, $window);
        return $window === Video_Meta::sanitize_peertube_recovery_window(
            get_post_meta($video_id, Video_Meta::PEERTUBE_RECOVERY_WINDOW, true)
        );
    }

    private static function refresh_post_cache(int $post_id): void
    {
        if ($post_id > 0 && function_exists('wp_cache_delete')) {
            wp_cache_delete($post_id, 'posts');
        }
    }

    private static function refresh_post_meta_cache(int $post_id): void
    {
        if ($post_id > 0 && function_exists('wp_cache_delete')) {
            wp_cache_delete($post_id, 'post_meta');
        }
    }

    /** @return array{status:string,pending:bool,eligible:bool,resumable:bool,origin_at:int,expires_at:int,anchor_post_id:int} */
    private static function status_result(
        string $status,
        bool $pending = false,
        bool $eligible = false,
        bool $resumable = false,
        int $origin_at = 0,
        int $expires_at = 0,
        int $anchor_post_id = 0
    ): array {
        return compact('status', 'pending', 'eligible', 'resumable', 'origin_at', 'expires_at', 'anchor_post_id');
    }
}

// EOF: includes/PeerTube_Incomplete_Work_Reconciler.php
