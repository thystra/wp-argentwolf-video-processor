<?php
/** Focused tests for site-time rendering of operator-visible timestamps. */
declare(strict_types=1);

$GLOBALS['awvp_operator_time_options'] = array(
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i',
);
function get_option(string $name): mixed { return $GLOBALS['awvp_operator_time_options'][$name] ?? ''; }
function wp_timezone(): DateTimeZone { return new DateTimeZone('America/New_York'); }
function wp_date(string $format, int $timestamp, ?DateTimeZone $timezone = null): string
{
    return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone ?? new DateTimeZone('UTC'))->format($format);
}

require_once dirname(__DIR__) . '/includes/Operator_Time.php';

use ArgentVideo\Operator_Time;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$timestamp = (new DateTimeImmutable('2026-09-12 01:30:14', new DateTimeZone('UTC')))->getTimestamp();
$assert('2026-09-11 21:30' === Operator_Time::format($timestamp), 'Operator time did not use the WordPress site timezone.');
$assert('2026-09-11 21:30 EDT' === Operator_Time::format($timestamp, true), 'Operator time did not append the effective site timezone.');
$assert('2026-09-11 21:30 EDT' === Operator_Time::mysql_utc('2026-09-12 01:30:14', true), 'Stored UTC MySQL time was not rendered in site time.');
$assert('' === Operator_Time::format(0), 'Invalid timestamp must render empty.');
$assert('' === Operator_Time::mysql_utc(''), 'Empty stored timestamp must render empty.');

fwrite(STDOUT, "Operator time rendering tests passed.\n");
