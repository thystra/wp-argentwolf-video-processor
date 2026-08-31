<?php
/**
 * File: tests/smoke-load.php
 */

declare(strict_types=1);

define('ABSPATH', '/tmp/wordpress/');
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['wpdb'] = (object) array('prefix' => 'wp_');

function plugin_dir_path(string $file): string
{
    return dirname($file) . '/';
}

function plugin_dir_url(string $file): string
{
    return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/';
}

function register_activation_hook(string $file, callable $callback): void
{
    unset($file, $callback);
}

function register_deactivation_hook(string $file, callable $callback): void
{
    unset($file, $callback);
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
{
    unset($hook, $callback, $priority, $accepted_args);
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
{
    unset($hook, $callback, $priority, $accepted_args);
}

function is_admin(): bool
{
    return false;
}

$plugin_path = dirname(__DIR__) . '/argentwolf-video-processor.php';
require $plugin_path;

$plugin_source = file_get_contents($plugin_path);
$header_version = null;

if (
    false !== $plugin_source
    && 1 === preg_match('/^[\h]*\*[\h]+Version:[\h]*([0-9A-Za-z.-]+)[\h]*$/m', $plugin_source, $matches)
) {
    $header_version = $matches[1];
}

if (
    ! defined('ARGENT_VIDEO_VERSION')
    || null === $header_version
    || ARGENT_VIDEO_VERSION !== $header_version
    || 1 !== preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[.-][0-9A-Za-z.-]+)?$/', ARGENT_VIDEO_VERSION)
) {
    fwrite(STDERR, "Plugin smoke load failed.\n");
    exit(1);
}

if (! interface_exists(ArgentVideo\PeerTube_Password_Grant_Api::class, false)) {
    fwrite(STDERR, "Plugin smoke load missed required R37 interface " . ArgentVideo\PeerTube_Password_Grant_Api::class . ".\n");
    exit(1);
}

foreach (
    array(
        ArgentVideo\Atomic_Option_Mutation_Plan::class,
        ArgentVideo\Atomic_Option_Plan_Result::class,
        ArgentVideo\PeerTube_Connection_Coordinator::class,
        ArgentVideo\PeerTube_Password_Grant_Service::class,
    ) as $required_class
) {
    if (! class_exists($required_class, false)) {
        fwrite(STDERR, "Plugin smoke load missed required R37 class {$required_class}.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Plugin smoke load passed.\n");

// EOF: tests/smoke-load.php
