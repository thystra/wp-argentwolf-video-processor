<?php
/** Structural/runtime checks for the unified ArgentWolf Video Processor settings hub. */
declare(strict_types=1);

$GLOBALS['awvp_settings_hub_get'] = array();
$GLOBALS['awvp_settings_hub_options'] = array(
    'date_format' => 'Y-m-d',
    'time_format' => 'H:i T',
);

function __(string $text, string $domain = ''): string
{
    unset($domain);
    return $text;
}

function sanitize_key(mixed $key): string
{
    $key = strtolower((string) $key);
    return preg_replace('/[^a-z0-9_\-]/', '', $key) ?? '';
}

function wp_unslash(mixed $value): mixed
{
    return $value;
}

function admin_url(string $path = ''): string
{
    return 'https://example.test/wp-admin/' . ltrim($path, '/');
}

/** @param array<string,string|int> $args */
function add_query_arg(array $args, string $url): string
{
    return $url . '?' . http_build_query($args, '', '&', PHP_QUERY_RFC3986);
}

function get_option(string $name): mixed
{
    return $GLOBALS['awvp_settings_hub_options'][$name] ?? '';
}

function wp_timezone(): DateTimeZone
{
    return new DateTimeZone('America/New_York');
}

function wp_date(string $format, int $timestamp, ?DateTimeZone $timezone = null): string
{
    $date = (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone ?? new DateTimeZone('UTC'));
    return $date->format($format);
}

require_once dirname(__DIR__) . '/includes/Settings_Hub.php';

use ArgentVideo\Settings_Hub;

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) {
        $failures[] = $message;
    }
};

$tabs = Settings_Hub::tabs();
$assert(
    array(
        Settings_Hub::TAB_OVERVIEW,
        Settings_Hub::TAB_VIDEOS,
        Settings_Hub::TAB_LOCAL,
        Settings_Hub::TAB_PEERTUBE,
        Settings_Hub::TAB_PUBLISHING,
        Settings_Hub::TAB_MIGRATION,
        Settings_Hub::TAB_RETENTION,
    ) === array_keys($tabs),
    'Unified settings tabs changed or are incomplete.'
);
$assert('Overview' === $tabs[Settings_Hub::TAB_OVERVIEW], 'Overview tab label changed.');
$assert('Videos & Routing' === $tabs[Settings_Hub::TAB_VIDEOS], 'Videos & Routing tab label changed.');
$assert('Local Processing' === $tabs[Settings_Hub::TAB_LOCAL], 'Local Processing tab label changed.');
$assert('PeerTube Servers' === $tabs[Settings_Hub::TAB_PEERTUBE], 'PeerTube Servers tab label changed.');
$assert('Publishing' === $tabs[Settings_Hub::TAB_PUBLISHING], 'Publishing tab label changed.');
$assert('Video Migration' === $tabs[Settings_Hub::TAB_MIGRATION], 'Video Migration tab label changed.');
$assert('Local Retention' === $tabs[Settings_Hub::TAB_RETENTION], 'Local Retention tab label changed.');

$_GET = array();
$assert(Settings_Hub::TAB_OVERVIEW === Settings_Hub::current_tab(), 'Missing tab must default to Overview.');
$_GET = array('tab' => Settings_Hub::TAB_PEERTUBE);
$assert(Settings_Hub::TAB_PEERTUBE === Settings_Hub::current_tab(), 'PeerTube tab selector was not accepted.');
$_GET = array('tab' => 'not-a-real-tab');
$assert(Settings_Hub::TAB_OVERVIEW === Settings_Hub::current_tab(), 'Unknown tab must fail closed to Overview.');

$url = Settings_Hub::tab_url(Settings_Hub::TAB_PUBLISHING, array('notice' => 'saved'));
$assert(str_contains($url, 'options-general.php?'), 'Settings tab URL must target WordPress Settings.');
$assert(str_contains($url, 'page=argent-video-processor'), 'Settings tab URL lost the canonical AWVP page slug.');
$assert(str_contains($url, 'tab=publishing'), 'Settings tab URL lost the requested tab.');
$assert(str_contains($url, 'notice=saved'), 'Settings tab URL lost supplied redirect state.');

$timestamp = (new DateTimeImmutable('2026-09-08 01:00:00', new DateTimeZone('UTC')))->getTimestamp();
$assert(
    '2026-09-07 21:00 EDT' === Settings_Hub::format_datetime($timestamp),
    'Administrator-facing time must use the WordPress-configured timezone/date/time formats.'
);
$assert(
    '2026-09-07 21:00 EDT' === Settings_Hub::format_mysql_utc('2026-09-08 01:00:00'),
    'Stored UTC MySQL timestamps must be converted to the WordPress-configured timezone for display.'
);

$plugin = (string) file_get_contents(dirname(__DIR__) . '/includes/Plugin.php');
$bootstrap = (string) file_get_contents(dirname(__DIR__) . '/argentwolf-video-processor.php');
$assert(str_contains($bootstrap, "includes/Settings_Hub.php"), 'Plugin bootstrap does not load the unified settings hub.');
$assert(str_contains($plugin, '$settings_hub = new Settings_Hub('), 'Plugin does not compose the unified settings hub.');
$assert(
    1 === substr_count($plugin, "add_action('admin_menu'"),
    'Plugin must expose only one registered admin-menu settings surface.'
);
$assert(
    str_contains($plugin, "add_action('admin_menu', array(\$settings_hub, 'menu'))"),
    'Unified settings hub is not the registered admin-menu surface.'
);
foreach (array('$admin', '$video_routing_admin', '$peertube_admin', '$video_publishing_admin', '$peertube_migration_admin', '$local_retention_admin') as $legacy_admin) {
    $assert(
        ! str_contains($plugin, "add_action('admin_menu', array({$legacy_admin}, 'menu'))"),
        "A legacy separate admin-menu surface is still registered: {$legacy_admin}."
    );
}

if ([] !== $failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "Unified settings hub tests passed.\n");
