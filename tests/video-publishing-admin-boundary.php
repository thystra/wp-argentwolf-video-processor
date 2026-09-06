<?php
/** Focused R46.2 source boundary tests for publishing-default administration. */
declare(strict_types=1);

$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$admin = file_get_contents(dirname(__DIR__) . '/includes/Video_Publishing_Admin.php');
$plugin = file_get_contents(dirname(__DIR__) . '/includes/Plugin.php');
$bootstrap = file_get_contents(dirname(__DIR__) . '/argentwolf-video-processor.php');
$assert(is_string($admin) && is_string($plugin) && is_string($bootstrap), 'Could not read publishing admin sources.');

$assert(str_contains($admin, "public const ACTION_SAVE = 'argent_video_save_publishing_defaults'"), 'Publishing save action changed.');
$assert(substr_count($admin, "current_user_can('manage_options')") >= 2, 'Publishing page/action must require manage_options.');
$assert(str_contains($admin, 'check_admin_referer(self::NONCE_ACTION)'), 'Publishing mutation must verify a nonce.');
$assert(str_contains($admin, 'wp_safe_redirect('), 'Publishing mutation must terminate through a safe redirect.');
$assert(str_contains($admin, 'Existing and legacy videos keep their stored destination'), 'Upgrade-safe destination guidance disappeared from publishing page.');
$assert(str_contains($admin, 'Early PeerTube uploads remain private until the WordPress post is actually published'), 'Prepublication privacy guidance disappeared from publishing page.');
$assert(str_contains($admin, 'Every PeerTube video still requires an explicit sensitive-content review'), 'Moderation review guidance disappeared from publishing page.');

$assert(str_contains($plugin, "add_action('admin_menu', array(\$video_publishing_admin, 'menu'))"), 'Publishing settings page is not registered.');
$assert(str_contains($plugin, "'admin_post_' . Video_Publishing_Admin::ACTION_SAVE"), 'Publishing save action is not registered.');
$assert(str_contains($bootstrap, "includes/Video_Publishing_Defaults.php"), 'Publishing-default model is not loaded.');
$assert(str_contains($bootstrap, "includes/Video_Publishing_Defaults_Store.php"), 'Publishing-default store is not loaded.');
$assert(str_contains($bootstrap, "includes/Video_Publishing_Admin.php"), 'Publishing admin is not loaded.');

fwrite(STDOUT, "R46 video publishing admin-boundary tests passed.\n");
