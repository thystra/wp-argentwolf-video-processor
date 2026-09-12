<?php
/**
 * File: includes/Local_Retention_Default_Policy_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Site-wide policy template applied by the Local Retention bulk workflow. */
final class Local_Retention_Default_Policy_Store
{
    public const OPTION = 'argentwolf_video_processor_local_retention_default';
    public const VERSION = 1;
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';

    /** @return array{version:int,mode:string,updated_by:int,updated_at:int} */
    public function get(): array
    {
        $stored = self::sanitize(get_option(self::OPTION, null));
        return array() === $stored ? self::defaults() : $stored;
    }

    /** @return array{status:string,policy:array{version:int,mode:string,updated_by:int,updated_at:int}} */
    public function save(string $mode, int $user_id, int $now): array
    {
        $mode = self::sanitize_mode($mode);
        if ('' === $mode || $user_id < 1 || $now < 1) {
            return array('status'=>self::REFUSED, 'policy'=>$this->get());
        }
        $before = $this->get();
        $next = array('version'=>self::VERSION, 'mode'=>$mode, 'updated_by'=>$user_id, 'updated_at'=>$now);
        if ($before['mode'] === $mode) {
            return array('status'=>self::PRESENT, 'policy'=>$before);
        }
        update_option(self::OPTION, $next, false);
        $after = self::sanitize(get_option(self::OPTION, null));
        return $after === $next
            ? array('status'=>self::APPLIED, 'policy'=>$after)
            : array('status'=>self::INDETERMINATE, 'policy'=>$before);
    }

    public function mode(): string
    {
        return $this->get()['mode'];
    }

    /** @return array{version:int,mode:string,updated_by:int,updated_at:int} */
    public static function defaults(): array
    {
        return array('version'=>self::VERSION, 'mode'=>Local_Retention_Policy::MODE_KEEP, 'updated_by'=>0, 'updated_at'=>0);
    }

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value)
            || array('version','mode','updated_by','updated_at') !== array_keys($value)
            || self::VERSION !== ($value['version'] ?? null)
            || '' === self::sanitize_mode($value['mode'] ?? null)
            || ! is_int($value['updated_by'] ?? null) || $value['updated_by'] < 0
            || ! is_int($value['updated_at'] ?? null) || $value['updated_at'] < 0
        ) {
            return array();
        }
        if ((0 === $value['updated_by']) !== (0 === $value['updated_at'])) {
            return array();
        }
        return $value;
    }

    public static function sanitize_mode(mixed $value): string
    {
        return is_string($value) && in_array(
            $value,
            array(
                Local_Retention_Policy::MODE_KEEP,
                Local_Retention_Policy::MODE_DELETE_MANAGED,
                Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY,
                Local_Retention_Policy::MODE_DELETE_ALL,
            ),
            true
        ) ? $value : '';
    }
}

// EOF: includes/Local_Retention_Default_Policy_Store.php
