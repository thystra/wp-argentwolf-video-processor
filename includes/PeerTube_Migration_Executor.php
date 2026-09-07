<?php
/**
 * File: includes/PeerTube_Migration_Executor.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Throwable;

/**
 * R46.8 explicit one-way promotion of a reviewed migration plan.
 *
 * This service performs local revalidation/promotion only. It never performs
 * PeerTube HTTP and never creates a second upload path. Once promoted, it hands
 * the live plan/destination to the already-qualified R46.5 synchronizer.
 */
final class PeerTube_Migration_Executor
{
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const REFUSED = 'refused';
    public const BUSY = 'busy';
    public const INDETERMINATE = 'indeterminate';

    private const LOCK_PREFIX = 'argentwolf_video_processor_migration_execute_lock_';
    private const LOCK_TTL_SECONDS = 120;

    public function __construct(
        private readonly Backend_Registry $registry,
        private readonly PeerTube_Publication_Catalog_Store $catalogs,
        private readonly Video_Publishing_Defaults_Store $defaults,
        private readonly PeerTube_Publication_Synchronizer $synchronizer,
        private readonly \Closure $secret_generation
    ) {
    }

    /** @return array{status:string,phase:string,generation:int,task_id:int} */
    public function execute(int $video_id, int $now): array
    {
        if ($video_id < 1 || $now < 1) {
            return self::result(self::REFUSED);
        }
        $lock = $this->acquire_lock($video_id, $now);
        if (null === $lock) {
            return self::result(self::BUSY);
        }

        try {
            $migration = $this->ready_migration($video_id);
            if (array() === $migration) {
                return self::result(self::REFUSED);
            }
            $hash = PeerTube_Migration_Execution::migration_plan_sha256($migration);
            if ('' === $hash) {
                return self::result(self::REFUSED);
            }

            $execution_exists = metadata_exists('post', $video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION);
            $execution = $execution_exists
                ? PeerTube_Migration_Execution::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION, true))
                : array();
            if ($execution_exists && array() === $execution) {
                return self::result(self::REFUSED);
            }
            if (array() !== $execution && ! $this->execution_matches($execution, $migration, $hash)) {
                return self::result(self::REFUSED);
            }

            if (array() === $execution) {
                if (! $this->source_is_still_local($video_id, $migration) || ! $this->provider_revalidated($migration)) {
                    return self::result(self::REFUSED);
                }
                $execution = array(
                    'version'=>PeerTube_Migration_Execution::VERSION,
                    'video_id'=>$video_id,
                    'migration_plan_sha256'=>$hash,
                    'backend_id'=>(string)$migration['backend_id'],
                    'channel_id'=>(string)$migration['channel_id'],
                    'anchor_post_id'=>(int)$migration['anchor_post_id'],
                    'status'=>PeerTube_Migration_Execution::STATUS_PREPARED,
                    'lifecycle_generation'=>0,
                    'task_id'=>0,
                    'started_at'=>$now,
                    'updated_at'=>$now,
                    'promoted_at'=>0,
                    'dispatched_at'=>0,
                );
                if (! $this->write_execution($video_id, $execution)) {
                    return self::result(self::INDETERMINATE);
                }
            }

            if (PeerTube_Migration_Execution::STATUS_DISPATCHED === $execution['status']) {
                return self::result(
                    self::PRESENT,
                    PeerTube_Migration_Execution::STATUS_DISPATCHED,
                    (int)$execution['lifecycle_generation'],
                    (int)$execution['task_id']
                );
            }

            if (PeerTube_Migration_Execution::STATUS_PREPARED === $execution['status']) {
                if (! $this->provider_revalidated($migration) || ! $this->promote_live_state($video_id, $migration)) {
                    return self::result(self::REFUSED, PeerTube_Migration_Execution::STATUS_PREPARED);
                }
                $execution['status'] = PeerTube_Migration_Execution::STATUS_PROMOTED;
                $execution['promoted_at'] = $now;
                $execution['updated_at'] = $now;
                if (! $this->write_execution($video_id, $execution)) {
                    return self::result(self::INDETERMINATE, PeerTube_Migration_Execution::STATUS_PREPARED);
                }
            }

            if (! $this->live_state_matches($video_id, $migration)) {
                return self::result(self::REFUSED, PeerTube_Migration_Execution::STATUS_PROMOTED);
            }

            $anchor = get_post((int)$migration['anchor_post_id']);
            if (! is_object($anchor)) {
                return self::result(self::REFUSED, PeerTube_Migration_Execution::STATUS_PROMOTED);
            }
            $sync = $this->synchronizer->sync_video($video_id, (string)($anchor->post_status ?? ''), $now);
            $sync_status = is_string($sync['status'] ?? null) ? $sync['status'] : Task_Repository::INDETERMINATE;
            if (! in_array($sync_status, array(Task_Repository::APPLIED,Task_Repository::PRESENT), true)) {
                return self::result(
                    Task_Repository::INDETERMINATE === $sync_status ? self::INDETERMINATE : self::REFUSED,
                    PeerTube_Migration_Execution::STATUS_PROMOTED
                );
            }
            $generation = Video_Meta::sanitize_positive_id($sync['generation'] ?? 0);
            $task_id = Video_Meta::sanitize_positive_id($sync['task_id'] ?? 0);
            if ($generation < 1 || $task_id < 1) {
                return self::result(self::INDETERMINATE, PeerTube_Migration_Execution::STATUS_PROMOTED);
            }

            $execution['status'] = PeerTube_Migration_Execution::STATUS_DISPATCHED;
            $execution['lifecycle_generation'] = $generation;
            $execution['task_id'] = $task_id;
            $execution['dispatched_at'] = $now;
            $execution['updated_at'] = $now;
            if (! $this->write_execution($video_id, $execution)) {
                return self::result(self::INDETERMINATE, PeerTube_Migration_Execution::STATUS_PROMOTED, $generation, $task_id);
            }
            return self::result(self::APPLIED, PeerTube_Migration_Execution::STATUS_DISPATCHED, $generation, $task_id);
        } finally {
            $this->release_lock($video_id, $lock);
        }
    }

    /** @return array<string,mixed> */
    private function ready_migration(int $video_id): array
    {
        if (! metadata_exists('post', $video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN)) {
            return array();
        }
        $migration = PeerTube_Migration_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN, true));
        if (array() === $migration || PeerTube_Migration_Plan::STATUS_READY !== ($migration['status'] ?? null)
            || $video_id !== (int)$migration['video_id'] || ! is_array($migration['publication_plan'] ?? null)) {
            return array();
        }
        return $migration;
    }

    /** @param array<string,mixed> $execution @param array<string,mixed> $migration */
    private function execution_matches(array $execution, array $migration, string $hash): bool
    {
        return (int)$execution['video_id'] === (int)$migration['video_id']
            && (string)$execution['migration_plan_sha256'] === $hash
            && (string)$execution['backend_id'] === (string)$migration['backend_id']
            && (string)$execution['channel_id'] === (string)$migration['channel_id']
            && (int)$execution['anchor_post_id'] === (int)$migration['anchor_post_id'];
    }

    /** @param array<string,mixed> $migration */
    private function source_is_still_local(int $video_id, array $migration): bool
    {
        $video = get_post($video_id);
        if (! is_object($video) || Video_Post_Type::POST_TYPE !== ($video->post_type ?? null)) {
            return false;
        }
        $attachment_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true));
        $anchor_post_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true));
        if ((int)$migration['attachment_id'] !== $attachment_id || (int)$migration['anchor_post_id'] !== $anchor_post_id) {
            return false;
        }
        $attachment = $attachment_id > 0 ? get_post($attachment_id) : null;
        $anchor = $anchor_post_id > 0 ? get_post($anchor_post_id) : null;
        if (! is_object($attachment) || 'attachment' !== ($attachment->post_type ?? null)
            || ! wp_attachment_is('video', $attachment_id) || ! is_object($anchor)
            || in_array((string)($anchor->post_type ?? ''), array('attachment','revision'), true)
            || 'trash' === ($anchor->post_status ?? null)) {
            return false;
        }
        $source_state = (string)get_post_meta($video_id, Video_Meta::SOURCE_STATE, true);
        if ('' !== $source_state && 'present' !== $source_state) {
            return false;
        }
        if (metadata_exists('post', $video_id, Video_Meta::PEERTUBE_PUBLICATION_EXECUTION)
            || metadata_exists('post', $video_id, Video_Meta::SERVING_AUTHORITY)) {
            return false;
        }
        if (metadata_exists('post', $video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN)) {
            return false;
        }
        $exists = metadata_exists('post', $video_id, Video_Meta::DESTINATION);
        $destination = Video_Destination::resolve(get_post_meta($video_id, Video_Meta::DESTINATION, true), $exists);
        return array() !== $destination && Video_Destination::is_local($destination);
    }

    /** @param array<string,mixed> $migration */
    private function provider_revalidated(array $migration): bool
    {
        $backend_id = Backend_Identity::sanitize($migration['backend_id'] ?? null);
        $descriptor = '' !== $backend_id ? $this->registry->get($backend_id) : null;
        if (! is_array($descriptor) || Backend_Registry::PEERTUBE_TYPE !== ($descriptor['type'] ?? null)
            || 'active' !== ($descriptor['state'] ?? null)) {
            return false;
        }
        $origin = PeerTube_Origin::sanitize($descriptor['config']['origin'] ?? null);
        try {
            $secret_generation = ($this->secret_generation)($descriptor, $backend_id);
        } catch (Throwable) {
            $secret_generation = 0;
        }
        if (! is_int($secret_generation) || $secret_generation < 1) {
            return false;
        }
        $catalog = $this->catalogs->get_for_context($backend_id, $origin, $secret_generation);
        if ('' === $origin || ! is_array($catalog) || true === ($catalog['stale'] ?? true)) {
            return false;
        }
        $plan = PeerTube_Publication_Plan::sanitize($migration['publication_plan'] ?? null);
        if (array() === $plan || ! PeerTube_Publication_Plan::ready_for_dispatch($plan)) {
            return false;
        }
        $settings = $this->defaults->get();
        $manifest = PeerTube_Publication_Manifest::build($plan, $catalog, is_array($settings) ? $settings : null);
        return array() !== $manifest;
    }

    /** @param array<string,mixed> $migration */
    private function promote_live_state(int $video_id, array $migration): bool
    {
        $plan = PeerTube_Publication_Plan::sanitize($migration['publication_plan'] ?? null);
        $destination = array(
            'version'=>Video_Destination::VERSION,
            'backend_id'=>(string)$migration['backend_id'],
            'channel_id'=>(string)$migration['channel_id'],
        );
        $destination = Video_Destination::sanitize($destination);
        if (array() === $plan || array() === $destination) {
            return false;
        }

        $plan_exists = metadata_exists('post', $video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN);
        $before_plan = $plan_exists
            ? PeerTube_Publication_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN, true)) : array();
        if ($plan_exists && $before_plan !== $plan) {
            return false;
        }
        if (! $plan_exists) {
            update_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN, $plan);
        }

        $destination_exists = metadata_exists('post', $video_id, Video_Meta::DESTINATION);
        $before_destination = Video_Destination::resolve(
            get_post_meta($video_id, Video_Meta::DESTINATION, true),
            $destination_exists
        );
        if (array() === $before_destination) {
            return false;
        }
        if (! Video_Destination::is_local($before_destination) && $before_destination !== $destination) {
            return false;
        }
        if ($before_destination !== $destination) {
            update_post_meta($video_id, Video_Meta::DESTINATION, $destination);
        }
        return $this->live_state_matches($video_id, $migration);
    }

    /** @param array<string,mixed> $migration */
    private function live_state_matches(int $video_id, array $migration): bool
    {
        $plan = PeerTube_Publication_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN, true));
        $destination = Video_Destination::sanitize(get_post_meta($video_id, Video_Meta::DESTINATION, true));
        return $plan === PeerTube_Publication_Plan::sanitize($migration['publication_plan'] ?? null)
            && $destination === Video_Destination::sanitize(array(
                'version'=>Video_Destination::VERSION,
                'backend_id'=>(string)$migration['backend_id'],
                'channel_id'=>(string)$migration['channel_id'],
            ));
    }

    /** @param array<string,mixed> $execution */
    private function write_execution(int $video_id, array $execution): bool
    {
        $execution = PeerTube_Migration_Execution::sanitize($execution);
        if (array() === $execution) {
            return false;
        }
        update_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION, $execution);
        return $execution === PeerTube_Migration_Execution::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION, true)
        );
    }

    /** @return array{token:string,created_at:int}|null */
    private function acquire_lock(int $video_id, int $now): ?array
    {
        $option = self::LOCK_PREFIX . hash('sha256', (string)$video_id);
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
            || $current['created_at'] > $now - self::LOCK_TTL_SECONDS) {
            return null;
        }
        delete_option($option);
        return add_option($option, $lock, '', false) ? $lock : null;
    }

    /** @param array{token:string,created_at:int} $lock */
    private function release_lock(int $video_id, array $lock): void
    {
        $option = self::LOCK_PREFIX . hash('sha256', (string)$video_id);
        $current = get_option($option, null);
        if (is_array($current) && ($current['token'] ?? null) === $lock['token']) {
            delete_option($option);
        }
    }

    /** @return array{status:string,phase:string,generation:int,task_id:int} */
    private static function result(string $status, string $phase = '', int $generation = 0, int $task_id = 0): array
    {
        return array('status'=>$status,'phase'=>$phase,'generation'=>$generation,'task_id'=>$task_id);
    }
}

// EOF: includes/PeerTube_Migration_Executor.php
