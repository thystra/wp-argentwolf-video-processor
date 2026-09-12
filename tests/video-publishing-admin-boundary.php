<?php
/** Focused R46.2/R46.3a source boundary tests for publishing administration. */
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
$assert(str_contains($admin, "public const ACTION_REFRESH_CHOICES = 'argent_video_refresh_peertube_publication_choices'"), 'Publication-choice refresh action changed.');
$assert(substr_count($admin, "current_user_can('manage_options')") >= 2, 'Publishing page/action must require manage_options.');
$assert(str_contains($admin, 'check_admin_referer(self::NONCE_ACTION)'), 'Publishing mutation must verify a nonce.');
$assert(str_contains($admin, "check_admin_referer(self::NONCE_REFRESH . ':' . \$backend_id)"), 'Publication-choice refresh must verify a backend-scoped nonce.');
$assert(str_contains($admin, 'wp_safe_redirect('), 'Publishing mutation must terminate through a safe redirect.');
$assert(str_contains($admin, 'Existing videos keep their current destination and are not migrated automatically.'), 'Upgrade-safe destination guidance disappeared from publishing page.');
$assert(str_contains($admin, 'Videos sent to PeerTube remain locally playable while they upload and process.'), 'Prepublication privacy guidance disappeared from publishing page.');
$assert(str_contains($admin, "esc_html_e('Before WordPress publishes'"), 'Publishing settings do not expose pre-publication visibility.');
$assert(str_contains($admin, 'PeerTube_Publication_Plan::PRE_PUBLISH_PRIVATE') && str_contains($admin, 'PeerTube_Publication_Plan::PRE_PUBLISH_UNLISTED'), 'Publishing settings do not constrain pre-publication visibility to Private/Unlisted.');
$assert(str_contains($admin, 'Private is the safe default. Unlisted allows the ready PeerTube copy to be shared by direct link before WordPress publishes.'), 'Publishing settings do not explain scheduled Send-now pre-publication visibility.');
$assert(str_contains($admin, 'Every PeerTube video still requires an explicit sensitive-content review'), 'Moderation review guidance disappeared from publishing page.');
$assert(str_contains($admin, 'The support Markdown is attached to the video when the video publish operation is created'), 'Support-preset guidance is not written in user-facing publish terminology.');
$assert(str_contains($admin, 'before AWVP will publish the video to the PeerTube server'), 'Sensitive-content guidance is not written in user-facing publish terminology.');
$assert(! str_contains($admin, 'resolved/frozen') && ! str_contains($admin, 'do not mutate') && ! str_contains($admin, 'dispatch-ready'), 'Publishing UI regressed to internal implementation terminology.');

$assert(str_contains($plugin, "add_action('admin_menu', array(\$settings_hub, 'menu'))"), 'Unified settings hub is not registered.');
$assert(str_contains($plugin, "'admin_post_' . Video_Publishing_Admin::ACTION_SAVE"), 'Publishing save action is not registered.');
$assert(str_contains($plugin, "'admin_post_' . Video_Publishing_Admin::ACTION_REFRESH_CHOICES"), 'Publication-choice refresh action is not registered.');
$assert(str_contains($bootstrap, "includes/Video_Publishing_Defaults.php"), 'Publishing-default model is not loaded.');
$assert(str_contains($bootstrap, "includes/Video_Publishing_Defaults_Store.php"), 'Publishing-default store is not loaded.');
$assert(str_contains($bootstrap, "includes/PeerTube_Publication_Catalog_Api.php"), 'Publication-catalog API boundary is not loaded.');
$assert(str_contains($bootstrap, "includes/PeerTube_Publication_Catalog_Store.php"), 'Publication-catalog store is not loaded.');
$assert(str_contains($bootstrap, "includes/PeerTube_Publication_Catalog_Service.php"), 'Publication-catalog service is not loaded.');
$assert(str_contains($bootstrap, "includes/Video_Publishing_Admin.php"), 'Publishing admin is not loaded.');
$assert(str_contains($bootstrap, "includes/Settings_Hub.php"), 'Unified settings hub is not loaded.');

$page_start = strpos($admin, 'public function page(): void');
$page_end = false !== $page_start ? strpos($admin, 'private function render_publication_catalogs', $page_start) : false;
$assert(false !== $page_start && false !== $page_end, 'Could not isolate publishing page method.');
$page_body = substr($admin, $page_start, $page_end - $page_start);
$assert(! str_contains($page_body, '->refresh('), 'Publishing page GET must not refresh PeerTube choices.');
$assert(str_contains($admin, 'Stale since %s; refresh must succeed before these choices can be treated as current.'), 'Stale publication-choice state is not surfaced to administrators.');
$assert(str_contains($admin, "(int) \$catalog['secret_generation']"), 'Publication-choice page stopped identifying the observed credential generation.');
$assert(str_contains($admin, 'Load publishing options') && str_contains($admin, 'Refresh publishing options'), 'Publishing options use ambiguous refresh-only administrator wording.');
$assert(str_contains($admin, 'Use activated channel: %s'), 'Backend channel choice is not rendered by human-readable provider label.');
$assert(str_contains($admin, "esc_html_e('Serving priority'"), 'PeerTube server defaults do not expose serving priority.');
$assert(str_contains($admin, 'Higher healthy priority serves first. WordPress local is fixed at priority 0.'), 'Serving-priority behavior is not explained in the publishing UI.');
$assert(str_contains($admin, "provider_override_select(\$backend_id, 'language'"), 'PeerTube server defaults do not expose a language override dropdown.');
$assert(str_contains($admin, "'backend_priorities'"), 'Publishing save boundary does not persist backend serving priorities.');
$assert(str_contains($admin, 'Remote publication health notifications'), 'Publishing settings do not expose remote-health email policy.');
$assert(str_contains($admin, 'Immediately after the first failed health check'), 'Publishing settings do not offer immediate health email delivery.');
$assert(str_contains($admin, 'After persistent failure (about 2 hours)'), 'Publishing settings do not offer delayed health email delivery.');
$assert(str_contains($admin, 'Backend-wide outages are deduplicated to one administrator email'), 'Publishing settings do not explain backend-outage email deduplication.');
$assert(str_contains($plugin, 'Remote_Health_Notification_Service'), 'Remote-health notification service is not wired into plugin runtime.');
$assert(str_contains($admin, 'Previously selected option is no longer available'), 'Provider dropdowns do not preserve unavailable stored selections safely.');
$assert(! str_contains($admin, "esc_html_e('Licence ID'"), 'Publishing settings still ask administrators for a raw licence ID.');
$assert(! str_contains($admin, "esc_html_e('Category ID'"), 'Publishing settings still ask administrators for a raw category ID.');
$assert(! str_contains($admin, "esc_html_e('Channel ID'"), 'Publishing settings still ask administrators for a raw channel ID.');
$assert(str_contains($admin, "if ('category_id' === \$field)") && str_contains($admin, 'alphabetical_choices'), 'Publishing settings do not alphabetize PeerTube categories by label.');

fwrite(STDOUT, "R46 video publishing admin-boundary tests passed.\n");
