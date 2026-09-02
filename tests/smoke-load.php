<?php
/**
 * File: tests/smoke-load.php
 */

declare(strict_types=1);

define('ABSPATH', '/tmp/wordpress/');
define('MINUTE_IN_SECONDS', 60);
$GLOBALS['wpdb'] = (object) array('prefix' => 'wp_');
$GLOBALS['awvp_smoke_actions'] = array();
$GLOBALS['awvp_smoke_filters'] = array();

function plugin_dir_path(string $file): string
{
    return dirname($file) . '/';
}

function plugin_dir_url(string $file): string
{
    return 'https://example.test/wp-content/plugins/' . basename(dirname($file)) . '/';
}

function plugin_basename(string $file): string
{
    return basename(dirname($file)) . '/' . basename($file);
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
    unset($callback);
    $GLOBALS['awvp_smoke_filters'][] = array($hook, $priority, $accepted_args);
}

function add_action(string $hook, callable $callback, int $priority = 10, int $accepted_args = 1): void
{
    unset($callback);
    $GLOBALS['awvp_smoke_actions'][] = array($hook, $priority, $accepted_args);
}

function is_admin(): bool
{
    return true;
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

if (! interface_exists(ArgentVideo\PeerTube_Connection_Admin_Actions::class, false)) {
    fwrite(STDERR, "Plugin smoke load missed required R38 interface " . ArgentVideo\PeerTube_Connection_Admin_Actions::class . ".\n");
    exit(1);
}

if (! interface_exists(ArgentVideo\PeerTube_Token_Lifecycle_Api::class, false)) {
    fwrite(STDERR, "Plugin smoke load missed required R41 interface " . ArgentVideo\PeerTube_Token_Lifecycle_Api::class . ".\n");
    exit(1);
}

foreach (
    array(
        ArgentVideo\Atomic_Option_Mutation_Plan::class,
        ArgentVideo\Atomic_Option_Plan_Result::class,
        ArgentVideo\PeerTube_Connection_Coordinator::class,
        ArgentVideo\PeerTube_Password_Grant_Service::class,
        ArgentVideo\PeerTube_Connection_Input::class,
        ArgentVideo\PeerTube_Connection_Admin_Service::class,
        ArgentVideo\PeerTube_Connection_Admin::class,
        ArgentVideo\PeerTube_Backend_Adapter::class,
        ArgentVideo\PeerTube_Backend_Activation_Service::class,
        ArgentVideo\PeerTube_Token_Lifecycle_Store::class,
        ArgentVideo\PeerTube_Token_Lifecycle_Service::class,
    ) as $required_class
) {
    if (! class_exists($required_class, false)) {
        fwrite(STDERR, "Plugin smoke load missed required connection/activation class {$required_class}.\n");
        exit(1);
    }
}

$registered_actions = array_column($GLOBALS['awvp_smoke_actions'], 0);
$expected_admin_posts = array(
    'admin_post_' . ArgentVideo\PeerTube_Connection_Admin::ACTION_START,
    'admin_post_' . ArgentVideo\PeerTube_Connection_Admin::ACTION_RESUME,
    'admin_post_' . ArgentVideo\PeerTube_Connection_Admin::ACTION_GRANT,
    'admin_post_' . ArgentVideo\PeerTube_Connection_Admin::ACTION_RECONCILE,
    'admin_post_' . ArgentVideo\PeerTube_Connection_Admin::ACTION_VERIFY_IDENTITY,
    'admin_post_' . ArgentVideo\PeerTube_Connection_Admin::ACTION_SELECT_DESTINATION,
    'admin_post_' . ArgentVideo\PeerTube_Connection_Admin::ACTION_ACTIVATE,
    'admin_post_' . ArgentVideo\PeerTube_Connection_Admin::ACTION_REFRESH,
    'admin_post_' . ArgentVideo\PeerTube_Connection_Admin::ACTION_DISCONNECT,
);
foreach ($expected_admin_posts as $hook) {
    if (1 !== count(array_keys($registered_actions, $hook, true))) {
        fwrite(STDERR, "Plugin smoke load missed or duplicated connection action {$hook}.\n");
        exit(1);
    }
}
foreach ($registered_actions as $hook) {
    if (
        str_starts_with($hook, 'admin_post_nopriv_argentwolf_video_processor_peertube_')
        || str_starts_with($hook, 'wp_ajax_argentwolf_video_processor_peertube_')
        || str_starts_with($hook, 'wp_ajax_nopriv_argentwolf_video_processor_peertube_')
    ) {
        fwrite(STDERR, "Plugin smoke load exposed an unauthorized connection action {$hook}.\n");
        exit(1);
    }
}

fwrite(STDOUT, "Plugin smoke load passed.\n");

// EOF: tests/smoke-load.php
