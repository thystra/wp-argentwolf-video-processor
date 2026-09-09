<?php
/**
 * File: includes/PeerTube_Publication_Synchronizer.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * R46.5a local-only lifecycle synchronizer.
 *
 * Hooks may inspect WordPress/local AWVP state and enqueue a durable generic
 * task. They never call PeerTube, refresh credentials, create upload sessions,
 * mutate remote visibility, or change serving authority.
 */
final class PeerTube_Publication_Synchronizer
{
    public const TASK_TYPE = 'peertube_publication_sync';
    public const PAYLOAD_VERSION = 1;
    public const LOCK_SECONDS = 30;

    private const LOCK_PREFIX = 'argent_video_processor_publication_sync_lock_';

    public function __construct(
        private readonly Task_Repository $tasks,
        private readonly Editorial_Publish_Validator $validator,
        private readonly ?Job_Repository $jobs = null
    ) {
    }

    public function register(): void
    {
        // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook is already fully prefixed with argentwolf_video_processor_.
        add_action('argentwolf_video_processor_publication_plan_saved', array($this, 'plan_saved'), 10, 2);
        add_action('transition_post_status', array($this, 'transition'), 10, 3);
    }

    public function plan_saved(int $video_id, int $anchor_post_id): void
    {
        if ($video_id < 1 || $anchor_post_id < 1) {
            return;
        }
        $post = get_post($anchor_post_id);
        if (! is_object($post)) {
            return;
        }
        $this->cancel_local_processing_for_remote_destination($video_id);
        $this->sync_video($video_id, (string) ($post->post_status ?? ''), time());
    }

    private function cancel_local_processing_for_remote_destination(int $video_id): void
    {
        if (null === $this->jobs || $video_id < 1) {
            return;
        }
        $destination = Video_Destination::resolve(
            get_post_meta($video_id, Video_Meta::DESTINATION, true),
            metadata_exists('post', $video_id, Video_Meta::DESTINATION)
        );
        if (array() === $destination || Video_Destination::is_local($destination)) {
            return;
        }
        $attachment_id = Video_Meta::sanitize_positive_id(
            get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true)
        );
        if ($attachment_id > 0 && $this->jobs->cancel($attachment_id)) {
            update_post_meta($attachment_id, '_argent_video_status', 'cancelled');
            delete_post_meta($attachment_id, '_argent_video_last_error');
        }
    }

    public function transition(string $new_status, string $old_status, mixed $post): void
    {
        if ($new_status === $old_status || ! is_object($post)) {
            return;
        }
        $post_id = Video_Meta::sanitize_positive_id($post->ID ?? 0);
        if (
            $post_id < 1
            || Video_Post_Type::POST_TYPE === ($post->post_type ?? null)
            || false !== wp_is_post_revision($post_id)
            || false !== wp_is_post_autosave($post_id)
        ) {
            return;
        }

        $content = is_string($post->post_content ?? null) ? $post->post_content : '';
        $validation = $this->validator->validate($post_id, $content);
        $video_ids = array();
        foreach (($validation['video_ids'] ?? array()) as $video_id) {
            $video_id = Video_Meta::sanitize_positive_id($video_id);
            if ($video_id < 1) {
                continue;
            }

            // A block reused from another post is display-only for publication
            // authority. Only the video's immutable origin anchor may advance or
            // revoke its PeerTube lifecycle. This also keeps copied block content
            // from becoming a second status-transition authority.
            $origin_post_id = Video_Meta::sanitize_positive_id(
                get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true)
            );
            if ($origin_post_id === $post_id) {
                $video_ids[$video_id] = true;
            }
        }

        // Also supersede intent for anchored videos no longer present in the
        // current block tree. This is critical when a scheduled/published block
        // is removed before a later draft/reschedule transition.
        foreach ($this->anchored_video_ids($post_id) as $video_id) {
            $video_ids[$video_id] = true;
        }

        $now = time();
        foreach (array_keys($video_ids) as $video_id) {
            $this->sync_video((int) $video_id, $new_status, $now);
        }
    }

    /** @return array{status:string,generation:int,task_id:int} */
    public function sync_video(int $video_id, string $wordpress_status, int $now): array
    {
        if ($video_id < 1 || $now < 1) {
            return self::result(Task_Repository::CONFLICT);
        }

        $lock = $this->acquire_lock($video_id, $now);
        if (null === $lock) {
            return self::result(Task_Repository::INDETERMINATE);
        }

        try {
            $desired = $this->derive($video_id, $wordpress_status, $now);
            if (array() === $desired) {
                return self::result(Task_Repository::CONFLICT);
            }

            $exists = metadata_exists('post', $video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE);
            $before = $exists ? PeerTube_Publication_Lifecycle::sanitize(
                get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, true)
            ) : array();
            if ($exists && array() === $before) {
                return self::result(Task_Repository::CONFLICT);
            }

            $generation = array() === $before ? 1 : (int) $before['generation'];
            if (array() !== $before && PeerTube_Publication_Lifecycle::semantic($before) !== PeerTube_Publication_Lifecycle::semantic($desired)) {
                if ($generation >= PHP_INT_MAX) {
                    return self::result(Task_Repository::CONFLICT);
                }
                ++$generation;
            }

            $desired['generation'] = $generation;
            $desired['task_pending'] = true;
            $desired['updated_at'] = $now;
            $desired = PeerTube_Publication_Lifecycle::sanitize($desired);
            if (array() === $desired) {
                return self::result(Task_Repository::CONFLICT);
            }

            if ($before !== $desired) {
                update_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, $desired);
                $after = PeerTube_Publication_Lifecycle::sanitize(
                    get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, true)
                );
                if ($after !== $desired) {
                    return self::result(Task_Repository::INDETERMINATE, $generation);
                }
            }

            return $this->enqueue_record($video_id, $desired, $now);
        } finally {
            $this->release_lock($video_id, $lock);
        }
    }

    /** @return array<string,mixed> */
    private function derive(int $video_id, string $wordpress_status, int $now): array
    {
        $video = get_post($video_id);
        if (! is_object($video) || Video_Post_Type::POST_TYPE !== ($video->post_type ?? null)) {
            return array();
        }
        $destination = Video_Destination::sanitize(get_post_meta($video_id, Video_Meta::DESTINATION, true));
        $plan = PeerTube_Publication_Plan::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN, true)
        );
        if (array() === $destination || Video_Destination::is_local($destination)
            || array() === $plan || ! PeerTube_Publication_Plan::ready_for_dispatch($plan)
            || ($destination['backend_id'] ?? null) !== ($plan['backend_id'] ?? null)
            || (string) ($destination['channel_id'] ?? '') !== (string) ($plan['channel_id'] ?? '')
        ) {
            return array();
        }

        $anchor = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true));
        if ($anchor < 1 || $anchor !== (int) $plan['anchor_post_id']) {
            return array();
        }
        $anchor_post = get_post($anchor);
        if (! is_object($anchor_post)) {
            return array();
        }

        $status = PeerTube_Publication_Lifecycle::status_bucket($wordpress_status);
        $dispatch = (string) $plan['dispatch_policy'];
        $scheduled_or_published = in_array($status, array('future','publish','private'), true);
        $upload_authorized = PeerTube_Publication_Plan::DISPATCH_SEND_NOW === $dispatch || $scheduled_or_published;
        $reveal_authorized = 'publish' === $status;
        $target_privacy = $reveal_authorized ? (string) $plan['final_privacy_id'] : '3';
        $plan_sha = PeerTube_Publication_Lifecycle::plan_sha256($plan);
        if ('' === $plan_sha) {
            return array();
        }

        return array(
            'version'            => PeerTube_Publication_Lifecycle::VERSION,
            'generation'         => 1,
            'backend_id'         => (string) $plan['backend_id'],
            'anchor_post_id'     => $anchor,
            'plan_sha256'        => $plan_sha,
            'dispatch_policy'    => $dispatch,
            'wordpress_status'   => $status,
            'upload_authorized'  => $upload_authorized,
            'reveal_authorized'  => $reveal_authorized,
            'target_privacy_id'  => $target_privacy,
            'task_pending'       => true,
            'updated_at'         => $now,
        );
    }

    /** @param array<string,mixed> $record @return array{status:string,generation:int,task_id:int} */
    private function enqueue_record(int $video_id, array $record, int $now): array
    {
        $record = PeerTube_Publication_Lifecycle::sanitize($record);
        if (array() === $record || $video_id < 1 || $now < 1) {
            return self::result(Task_Repository::CONFLICT);
        }

        $generation = (int) $record['generation'];
        $payload = array(
            'version'     => self::PAYLOAD_VERSION,
            'generation'  => $generation,
            'plan_sha256' => (string) $record['plan_sha256'],
        );
        $idempotency = hash(
            'sha256',
            'awvp-task:v1:' . self::TASK_TYPE . ':' . $video_id . ':' . $generation . ':' . $record['plan_sha256']
        );
        $queued = $this->tasks->enqueue(
            self::TASK_TYPE,
            $video_id,
            null,
            (string) $record['backend_id'],
            $idempotency,
            $payload,
            $now,
            $now,
            90,
            10
        );
        $status = is_string($queued['status'] ?? null) ? $queued['status'] : Task_Repository::INDETERMINATE;
        $task_id = Video_Meta::sanitize_positive_id($queued['task_id'] ?? 0);
        if (in_array($status, array(Task_Repository::APPLIED, Task_Repository::PRESENT), true)) {
            $record['task_pending'] = false;
            $record['updated_at'] = $now;
            update_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, $record);
            $after = PeerTube_Publication_Lifecycle::sanitize(
                get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, true)
            );
            if ($after !== $record) {
                return self::result(Task_Repository::INDETERMINATE, $generation, $task_id);
            }
        }
        return self::result($status, $generation, $task_id);
    }

    /** @return list<int> */
    private function anchored_video_ids(int $post_id): array
    {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Anchored AWVP Video lookup intentionally filters by its private origin-post meta key/value.
        $ids = get_posts(array(
            'post_type'      => Video_Post_Type::POST_TYPE,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => Video_Meta::ORIGIN_POST_ID,
            'meta_value'     => (string) $post_id,
        ));
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        if (! is_array($ids)) {
            return array();
        }
        $out = array();
        foreach ($ids as $id) {
            $id = Video_Meta::sanitize_positive_id($id);
            if ($id > 0) {
                $out[$id] = $id;
            }
        }
        return array_values($out);
    }

    /** @return array{token:string,created_at:int}|null */
    private function acquire_lock(int $video_id, int $now): ?array
    {
        $option = self::LOCK_PREFIX . hash('sha256', (string) $video_id);
        try {
            $token = bin2hex(random_bytes(16));
        } catch (Throwable) {
            return null;
        }
        $lock = array('token'=>$token,'created_at'=>$now);
        if (add_option($option, $lock, '', false)) {
            return $lock;
        }
        $current = get_option($option, null);
        if (! is_array($current) || ! is_int($current['created_at'] ?? null)
            || $current['created_at'] < 1 || $current['created_at'] > $now
            || $current['created_at'] > $now - self::LOCK_SECONDS) {
            return null;
        }
        delete_option($option);
        return add_option($option, $lock, '', false) ? $lock : null;
    }

    /** @param array{token:string,created_at:int} $lock */
    private function release_lock(int $video_id, array $lock): void
    {
        $option = self::LOCK_PREFIX . hash('sha256', (string) $video_id);
        $current = get_option($option, null);
        if (is_array($current) && ($current['token'] ?? null) === $lock['token']) {
            delete_option($option);
        }
    }

    /** @return array{status:string,generation:int,task_id:int} */
    private static function result(string $status, int $generation = 0, int $task_id = 0): array
    {
        return array('status'=>$status,'generation'=>$generation,'task_id'=>$task_id);
    }
}

// EOF: includes/PeerTube_Publication_Synchronizer.php
