<?php
/** Structural safety tests for the read-only Videos & Routing matrix. */
declare(strict_types=1);

$failures = array();
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (! $condition) $failures[] = $message;
};

$admin = (string) file_get_contents(dirname(__DIR__) . '/includes/Video_Routing_Admin.php');
$references = (string) file_get_contents(dirname(__DIR__) . '/includes/Video_Reference_Index.php');
$hub = (string) file_get_contents(dirname(__DIR__) . '/includes/Settings_Hub.php');
$plugin = (string) file_get_contents(dirname(__DIR__) . '/includes/Plugin.php');
$bootstrap = (string) file_get_contents(dirname(__DIR__) . '/argentwolf-video-processor.php');

foreach (array('Videos & Routing','Referenced by','Primary','Backups','Serving now','Default primary destination for new videos','Fallback serving is active.') as $needle) {
    $assert(str_contains($admin, $needle), 'Routing matrix missing required operator marker: ' . $needle);
}
$assert(str_contains($admin, '$this->serving->serving_candidate($video_id)'), 'Routing matrix does not resolve actual current serving source.');
$assert(str_contains($admin, '$this->references->posts_for('), 'Routing matrix does not use shared post-reference discovery.');
$assert(str_contains($admin, 'Video_Publishing_Defaults::effective_for_backend'), 'Routing matrix does not resolve the new-video default channel.');
$assert(str_contains($hub, "TAB_VIDEOS = 'videos-routing'"), 'Settings hub lacks Videos & Routing tab.');
$assert(str_contains($plugin, '$video_routing_admin = new Video_Routing_Admin('), 'Plugin does not compose routing admin.');
$assert(str_contains($plugin, '$video_references = new Video_Reference_Index();'), 'Plugin does not share the reference index across admin surfaces.');
foreach (array('includes/Video_Reference_Index.php','includes/Video_Routing_Admin.php') as $file) {
    $assert(str_contains($bootstrap, $file), 'Bootstrap missing ' . $file);
}
foreach (array('update_post_meta','delete_post_meta','wp_update_post','wp_delete_post','wp_remote_','admin_post_') as $forbidden) {
    $assert(! str_contains($admin, $forbidden), 'Routing matrix acquired write/network/action authority: ' . $forbidden);
}
$assert(str_contains($references, 'MAX_POSTS = 5000'), 'Reference discovery is not explicitly bounded.');

if ([] !== $failures) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}
fwrite(STDOUT, "Video routing admin structural tests passed.\n");
