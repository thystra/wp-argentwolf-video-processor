<?php
/**
 * File: includes/Operator_Time.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Render stored UTC timestamps using the WordPress site's configured timezone. */
final class Operator_Time
{
    public static function format(int $timestamp, bool $include_timezone = false): string
    {
        if ($timestamp < 1) {
            return '';
        }

        $date_format = function_exists('get_option') ? (string) get_option('date_format') : '';
        $time_format = function_exists('get_option') ? (string) get_option('time_format') : '';
        $date_format = '' !== trim($date_format) ? $date_format : 'Y-m-d';
        $time_format = '' !== trim($time_format) ? $time_format : 'H:i:s';
        $format = trim($date_format . ' ' . $time_format);

        $timezone = function_exists('wp_timezone')
            ? wp_timezone()
            : new \DateTimeZone('UTC');

        if (function_exists('wp_date')) {
            $rendered = wp_date($format, $timestamp, $timezone);
            $zone = $include_timezone ? wp_date('T', $timestamp, $timezone) : '';
        } else {
            $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
            $rendered = $date->format($format);
            $zone = $include_timezone ? $date->format('T') : '';
        }

        if (! $include_timezone || '' === $zone) {
            return $rendered;
        }
        if (preg_match('/(?:^|\s)' . preg_quote($zone, '/') . '(?:$|\s)/', $rendered)) {
            return $rendered;
        }
        return trim($rendered . ' ' . $zone);
    }

    public static function mysql_utc(string $value, bool $include_timezone = false): string
    {
        if ('' === $value) {
            return '';
        }
        $date = \DateTimeImmutable::createFromFormat(
            '!Y-m-d H:i:s',
            $value,
            new \DateTimeZone('UTC')
        );
        if (false === $date) {
            return $value;
        }
        return self::format($date->getTimestamp(), $include_timezone);
    }
}

// EOF: includes/Operator_Time.php
