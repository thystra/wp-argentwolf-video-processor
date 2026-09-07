<?php
/** Structural safety tests for R46.7 administrator migration workflow. */
declare(strict_types=1);

$a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
$admin=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Migration_Admin.php');
$planner=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Migration_Planner.php');
$plugin=(string)file_get_contents(dirname(__DIR__).'/includes/Plugin.php');
$bootstrap=(string)file_get_contents(dirname(__DIR__).'/argentwolf-video-processor.php');

foreach (array('manage_options','check_admin_referer','ACTION_PLAN','ACTION_REVIEW','Plan selected','Plan all eligible','Migration review queue','Save migration review') as $needle) {
    $a(str_contains($admin,$needle),'Migration admin missing required boundary/UI marker: '.$needle);
}
foreach (array('wp_remote_','PeerTube_Api_Client','Task_Repository','wp_publish_post','transition_post_status','delete_attachment','wp_delete_post') as $forbidden) {
    $a(!str_contains($admin,$forbidden),'Migration admin acquired forbidden consequential authority: '.$forbidden);
    $a(!str_contains($planner,$forbidden),'Migration planner acquired forbidden consequential authority: '.$forbidden);
}
$a(str_contains($plugin,"add_action('admin_menu', array(\$peertube_migration_admin, 'menu'))"),'Migration admin menu is not wired.');
$a(str_contains($plugin,"'admin_post_' . PeerTube_Migration_Admin::ACTION_PLAN"),'Migration plan action is not wired.');
$a(str_contains($plugin,"'admin_post_' . PeerTube_Migration_Admin::ACTION_REVIEW"),'Migration review action is not wired.');
foreach (array('PeerTube_Migration_Plan.php','PeerTube_Migration_Planner.php','PeerTube_Migration_Admin.php') as $file) {
    $a(str_contains($bootstrap,$file),'Bootstrap missing '.$file);
}
$a(str_contains($planner,'MAX_SELECT_ALL = 500'),'Select-all planning is not explicitly bounded.');
$a(str_contains($planner,'Video_Meta::PEERTUBE_MIGRATION_PLAN'),'Planner does not write isolated migration state.');
$a(!str_contains($planner,'update_post_meta($video_id, Video_Meta::DESTINATION'),'Planner writes live destination.');
$a(!str_contains($planner,'update_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN'),'Planner writes live publication plan.');
$a(!str_contains($planner,'update_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE'),'Planner writes live lifecycle.');
$a(!str_contains($planner,'update_post_meta($video_id, Video_Meta::SERVING_AUTHORITY'),'Planner writes serving authority.');

echo "PeerTube migration admin structural test passed.\n";
