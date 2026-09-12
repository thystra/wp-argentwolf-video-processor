<?php
/**
 * File: includes/Archive_Of_Record_Policy_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Site-wide authority for whether WordPress may automatically remove originals. */
final class Archive_Of_Record_Policy_Store
{
    public const OPTION = 'argentwolf_video_processor_archive_of_record';
    public const VERSION = 1;
    public const WORDPRESS = 'wordpress';
    public const NOT_WORDPRESS = 'not_wordpress';
    public const DEFAULT_GRACE_DAYS = 0;
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';

    /** @return array{version:int,archive_of_record:string,grace_days:int,confirmed_by:int,confirmed_at:int} */
    public function get(): array
    {
        $stored = self::sanitize(get_option(self::OPTION, null));
        return array() === $stored ? self::defaults() : $stored;
    }

    /**
     * Changing from WordPress-as-archive to not-WordPress requires one explicit
     * acknowledgement. Subsequent grace edits do not repeat that confirmation.
     * A zero-day grace is a durable Never-delete default, not immediate deletion.
     *
     * @return array{status:string,policy:array<string,mixed>}
     */
    public function save(string $mode, int $grace_days, int $user_id, int $now, bool $confirmed): array
    {
        if (! in_array($mode, array(self::WORDPRESS, self::NOT_WORDPRESS), true)
            || $user_id < 1 || $now < 1
            || $grace_days < Local_Retention_Policy::MIN_GRACE_DAYS
            || $grace_days > Local_Retention_Policy::MAX_GRACE_DAYS
        ) {
            return array('status' => self::REFUSED, 'policy' => $this->get());
        }
        $before = $this->get();
        if (self::WORDPRESS === $before['archive_of_record']
            && self::NOT_WORDPRESS === $mode
            && ! $confirmed
        ) {
            return array('status' => self::REFUSED, 'policy' => $before);
        }

        $confirmed_by = (int) $before['confirmed_by'];
        $confirmed_at = (int) $before['confirmed_at'];
        if (self::NOT_WORDPRESS === $mode && self::WORDPRESS === $before['archive_of_record']) {
            $confirmed_by = $user_id;
            $confirmed_at = $now;
        }
        $next = array(
            'version'           => self::VERSION,
            'archive_of_record' => $mode,
            'grace_days'        => $grace_days,
            'confirmed_by'      => self::NOT_WORDPRESS === $mode ? $confirmed_by : 0,
            'confirmed_at'      => self::NOT_WORDPRESS === $mode ? $confirmed_at : 0,
        );
        $next = self::sanitize($next);
        if (array() === $next) {
            return array('status' => self::REFUSED, 'policy' => $before);
        }
        if ($next === $before) {
            return array('status' => self::PRESENT, 'policy' => $before);
        }
        update_option(self::OPTION, $next, false);
        $after = self::sanitize(get_option(self::OPTION, null));
        return $after === $next
            ? array('status' => self::APPLIED, 'policy' => $after)
            : array('status' => self::INDETERMINATE, 'policy' => $before);
    }

    public function wordpress_is_archive(): bool
    {
        return self::WORDPRESS === $this->get()['archive_of_record'];
    }

    public function source_deletion_allowed(): bool
    {
        return self::NOT_WORDPRESS === $this->get()['archive_of_record'];
    }

    public function grace_days(): int
    {
        return (int) $this->get()['grace_days'];
    }

    /** @return array{version:int,archive_of_record:string,grace_days:int,confirmed_by:int,confirmed_at:int} */
    public static function defaults(): array
    {
        return array(
            'version'           => self::VERSION,
            'archive_of_record' => self::WORDPRESS,
            'grace_days'        => self::DEFAULT_GRACE_DAYS,
            'confirmed_by'      => 0,
            'confirmed_at'      => 0,
        );
    }

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value)
            || array('version','archive_of_record','grace_days','confirmed_by','confirmed_at') !== array_keys($value)
            || self::VERSION !== ($value['version'] ?? null)
            || ! is_string($value['archive_of_record'] ?? null)
            || ! in_array($value['archive_of_record'], array(self::WORDPRESS, self::NOT_WORDPRESS), true)
            || ! is_int($value['grace_days'] ?? null)
            || $value['grace_days'] < Local_Retention_Policy::MIN_GRACE_DAYS
            || $value['grace_days'] > Local_Retention_Policy::MAX_GRACE_DAYS
            || ! is_int($value['confirmed_by'] ?? null) || $value['confirmed_by'] < 0
            || ! is_int($value['confirmed_at'] ?? null) || $value['confirmed_at'] < 0
        ) {
            return array();
        }
        if (self::WORDPRESS === $value['archive_of_record']) {
            return 0 === $value['confirmed_by'] && 0 === $value['confirmed_at'] ? $value : array();
        }
        return $value['confirmed_by'] > 0 && $value['confirmed_at'] > 0 ? $value : array();
    }
}

// EOF: includes/Archive_Of_Record_Policy_Store.php
