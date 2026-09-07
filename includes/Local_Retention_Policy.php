<?php
/** File: includes/Local_Retention_Policy.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Explicit per-video post-cutover local retention policy. */
final class Local_Retention_Policy
{
    public const VERSION = 1;
    public const MODE_KEEP = 'keep';
    public const MODE_DELETE_MANAGED = 'delete_managed';
    public const MODE_DELETE_ALL = 'delete_all';
    public const MIN_GRACE_DAYS = 1;
    public const MAX_GRACE_DAYS = 365;

    /** @return array<string,mixed> */
    public static function create(string $mode, int $grace_days, int $user_id, int $now): array
    {
        if (! in_array($mode, array(self::MODE_KEEP,self::MODE_DELETE_MANAGED,self::MODE_DELETE_ALL), true)
            || $user_id < 1 || $now < 1) {
            return array();
        }
        if (self::MODE_KEEP === $mode) {
            $grace_days = 0;
        } elseif ($grace_days < self::MIN_GRACE_DAYS || $grace_days > self::MAX_GRACE_DAYS) {
            return array();
        }
        return array(
            'version'=>self::VERSION,
            'mode'=>$mode,
            'grace_days'=>$grace_days,
            'confirmed_by'=>$user_id,
            'confirmed_at'=>$now,
        );
    }

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value)
            || array('version','mode','grace_days','confirmed_by','confirmed_at') !== array_keys($value)
            || self::VERSION !== ($value['version'] ?? null)
            || ! is_string($value['mode'] ?? null)
            || ! in_array($value['mode'], array(self::MODE_KEEP,self::MODE_DELETE_MANAGED,self::MODE_DELETE_ALL), true)
            || ! is_int($value['grace_days'] ?? null)
            || ! is_int($value['confirmed_by'] ?? null) || $value['confirmed_by'] < 1
            || ! is_int($value['confirmed_at'] ?? null) || $value['confirmed_at'] < 1) {
            return array();
        }
        if (self::MODE_KEEP === $value['mode']) {
            if (0 !== $value['grace_days']) return array();
        } elseif ($value['grace_days'] < self::MIN_GRACE_DAYS || $value['grace_days'] > self::MAX_GRACE_DAYS) {
            return array();
        }
        return $value;
    }

    public static function sha256(array $policy): string
    {
        $policy = self::sanitize($policy);
        if (array() === $policy) return '';
        $json = wp_json_encode($policy, JSON_UNESCAPED_SLASHES);
        return is_string($json) ? hash('sha256', 'awvp-local-retention-policy:v1:' . $json) : '';
    }

    public static function destructive(array $policy): bool
    {
        $policy = self::sanitize($policy);
        return array() !== $policy && self::MODE_KEEP !== $policy['mode'];
    }

    public static function deletes_source(array $policy): bool
    {
        $policy = self::sanitize($policy);
        return array() !== $policy && self::MODE_DELETE_ALL === $policy['mode'];
    }
}
// EOF
