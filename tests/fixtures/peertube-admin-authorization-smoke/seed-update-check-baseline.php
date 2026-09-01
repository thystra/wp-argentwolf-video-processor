<?php
/**
 * Seed a complete recent WordPress update-check baseline for the isolated R38 site.
 *
 * The R38 Docker network intentionally cannot reach WordPress.org. Authenticated
 * wp-admin requests still run core's normal admin_init update hooks, so a fresh
 * site needs a deterministic cache baseline before the browser fixture starts.
 */

declare(strict_types=1);

if (! defined('ABSPATH')) {
    fwrite(STDERR, "WordPress was not loaded.\n");
    exit(1);
}

$fail = static function (string $message): void {
    fwrite(STDERR, "PEERTUBE_ADMIN_AUTHORIZATION_UPDATE_BASELINE_ASSERTION_FAILED: {$message}\n");
    exit(1);
};

if (! function_exists('get_plugins')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
}

// Load the unfiltered core version used by _maybe_update_core() on both matrix versions.
require ABSPATH . WPINC . '/version.php';

if (! isset($wp_version) || ! is_string($wp_version) || '' === $wp_version) {
    $fail('The exact WordPress core version was unavailable.');
}

$checked_at     = time();
$plugin_checked = array();
$theme_checked  = array();

foreach (get_plugins() as $plugin_file => $plugin_data) {
    $plugin_checked[$plugin_file] = (string) $plugin_data['Version'];
}

foreach (wp_get_themes() as $stylesheet => $theme) {
    $theme_checked[$stylesheet] = (string) $theme->get('Version');
}

$core = (object) array(
    'last_checked'    => $checked_at,
    'version_checked' => $wp_version,
    'updates'         => array(),
    'translations'    => array(),
);

$plugins = (object) array(
    'last_checked' => $checked_at,
    'checked'      => $plugin_checked,
    'response'     => array(),
    'no_update'    => array(),
    'translations' => array(),
);

$themes = (object) array(
    'last_checked' => $checked_at,
    'checked'      => $theme_checked,
    'response'     => array(),
    'no_update'    => array(),
    'translations' => array(),
);

set_site_transient('update_core', $core, DAY_IN_SECONDS);
set_site_transient('update_plugins', $plugins, DAY_IN_SECONDS);
set_site_transient('update_themes', $themes, DAY_IN_SECONDS);

$stored_core    = get_site_transient('update_core');
$stored_plugins = get_site_transient('update_plugins');
$stored_themes  = get_site_transient('update_themes');

if (! is_object($stored_core) || get_object_vars($core) !== get_object_vars($stored_core)) {
    $fail('The WordPress core update baseline did not round-trip exactly.');
}
if (! is_object($stored_plugins) || get_object_vars($plugins) !== get_object_vars($stored_plugins)) {
    $fail('The plugin update baseline did not round-trip exactly.');
}
if (! is_object($stored_themes) || get_object_vars($themes) !== get_object_vars($stored_themes)) {
    $fail('The theme update baseline did not round-trip exactly.');
}

echo "WORDPRESS_UPDATE_CHECK_BASELINE_ASSERTIONS=PASS\n";
