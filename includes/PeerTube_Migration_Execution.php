<?php
/**
 * File: includes/PeerTube_Migration_Execution.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Crash-recoverable, non-secret R46.8 migration-promotion journal.
 *
 * This record is local execution evidence only. It contains no PeerTube token,
 * remote asset identity, source path, or serving authority. `prepared` is the
 * one-way commitment boundary: after that point editor destination changes are
 * refused and the executor may only converge forward.
 */
final class PeerTube_Migration_Execution
{
    public const VERSION = 1;
    public const STATUS_PREPARED = 'prepared';
    public const STATUS_PROMOTED = 'promoted';
    public const STATUS_DISPATCHED = 'dispatched';

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        $keys = array(
            'version','video_id','migration_plan_sha256','backend_id','channel_id','anchor_post_id',
            'status','lifecycle_generation','task_id','started_at','updated_at','promoted_at','dispatched_at',
        );
        if (! is_array($value) || $keys !== array_keys($value) || self::VERSION !== ($value['version'] ?? null)) {
            return array();
        }

        $video_id = self::positive_int($value['video_id'] ?? null);
        $hash = is_string($value['migration_plan_sha256'] ?? null) ? $value['migration_plan_sha256'] : '';
        $backend_id = Backend_Identity::sanitize($value['backend_id'] ?? null);
        $channel_id = self::decimal_id($value['channel_id'] ?? null);
        $anchor_post_id = self::positive_int($value['anchor_post_id'] ?? null);
        $status = is_string($value['status'] ?? null) ? $value['status'] : '';
        $generation = self::nonnegative_int($value['lifecycle_generation'] ?? null);
        $task_id = self::nonnegative_int($value['task_id'] ?? null);
        $started_at = self::positive_int($value['started_at'] ?? null);
        $updated_at = self::positive_int($value['updated_at'] ?? null);
        $promoted_at = self::nonnegative_int($value['promoted_at'] ?? null);
        $dispatched_at = self::nonnegative_int($value['dispatched_at'] ?? null);

        if (
            $video_id < 1 || 1 !== preg_match('/^[a-f0-9]{64}$/D', $hash)
            || '' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id || '' === $channel_id
            || $anchor_post_id < 1
            || ! in_array($status, array(self::STATUS_PREPARED,self::STATUS_PROMOTED,self::STATUS_DISPATCHED), true)
            || $started_at < 1 || $updated_at < $started_at
        ) {
            return array();
        }

        if (self::STATUS_PREPARED === $status) {
            if (0 !== $generation || 0 !== $task_id || 0 !== $promoted_at || 0 !== $dispatched_at) {
                return array();
            }
        } elseif (self::STATUS_PROMOTED === $status) {
            if ($promoted_at < $started_at || $promoted_at > $updated_at || 0 !== $generation || 0 !== $task_id || 0 !== $dispatched_at) {
                return array();
            }
        } else {
            if ($promoted_at < $started_at || $promoted_at > $updated_at
                || $dispatched_at < $promoted_at || $dispatched_at > $updated_at || $generation < 1 || $task_id < 1) {
                return array();
            }
        }

        return array(
            'version'=>self::VERSION,
            'video_id'=>$video_id,
            'migration_plan_sha256'=>$hash,
            'backend_id'=>$backend_id,
            'channel_id'=>$channel_id,
            'anchor_post_id'=>$anchor_post_id,
            'status'=>$status,
            'lifecycle_generation'=>$generation,
            'task_id'=>$task_id,
            'started_at'=>$started_at,
            'updated_at'=>$updated_at,
            'promoted_at'=>$promoted_at,
            'dispatched_at'=>$dispatched_at,
        );
    }

    public static function committed(mixed $value): bool
    {
        return array() !== self::sanitize($value);
    }

    /** @param array<string,mixed> $plan */
    public static function migration_plan_sha256(array $plan): string
    {
        $plan = PeerTube_Migration_Plan::sanitize($plan);
        if (array() === $plan || PeerTube_Migration_Plan::STATUS_READY !== $plan['status']) {
            return '';
        }
        try {
            return hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } catch (\JsonException) {
            return '';
        }
    }

    private static function positive_int(mixed $value): int
    {
        $value = self::nonnegative_int($value);
        return $value > 0 ? $value : 0;
    }

    private static function nonnegative_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : -1;
        }
        if (! is_string($value) || 1 !== preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
            return -1;
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
            return -1;
        }
        return (int) $value;
    }

    private static function decimal_id(mixed $value): string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : '';
        }
        return is_string($value) && 1 === preg_match('/^[1-9][0-9]*$/D', $value) ? $value : '';
    }
}

// EOF: includes/PeerTube_Migration_Execution.php
