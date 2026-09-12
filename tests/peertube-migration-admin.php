<?php
/** Structural safety tests for R46.7/R46.8 administrator migration workflow. */
declare(strict_types=1);

$a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
$admin=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Migration_Admin.php');
$planner=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Migration_Planner.php');
$executor=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Migration_Executor.php');
$plugin=(string)file_get_contents(dirname(__DIR__).'/includes/Plugin.php');
$bootstrap=(string)file_get_contents(dirname(__DIR__).'/argentwolf-video-processor.php');

foreach (array('manage_options','check_admin_referer','ACTION_PLAN','ACTION_REVIEW','ACTION_EXECUTE','Plan selected','Plan all eligible','Migration review queue','Save migration review','Start migration','Before WordPress publishes','After WordPress publishes','I understand this migration is one-way','Resume migration','Referenced by','I reviewed the PeerTube title, target channel, tags (including zero tags if applicable), pre-publication and final visibility, and sensitive-content declaration.','Review saved. This video is ready to migrate.') as $needle) {
    $a(str_contains($admin,$needle),'Migration admin missing required boundary/UI marker: '.$needle);
}
foreach (array('wp_remote_','PeerTube_Api_Client','wp_publish_post','transition_post_status','delete_attachment','wp_delete_post') as $forbidden) {
    $a(!str_contains($admin,$forbidden),'Migration admin acquired forbidden direct remote/publish authority: '.$forbidden);
    $a(!str_contains($planner,$forbidden),'Migration planner acquired forbidden consequential authority: '.$forbidden);
    $a(!str_contains($executor,$forbidden),'Migration executor acquired forbidden direct remote/publish authority: '.$forbidden);
}
$a(!str_contains($executor,'->enqueue('),'Migration executor created a second direct task enqueue path instead of using the qualified synchronizer.');
$a(str_contains($executor,'$this->synchronizer->sync_video'),'Migration executor does not hand promoted state to the qualified publication synchronizer.');
$a(str_contains($plugin,"add_action('admin_menu', array(\$settings_hub, 'menu'))"),'Unified settings hub is not wired.');
$a(str_contains($plugin,"'admin_post_' . PeerTube_Migration_Admin::ACTION_PLAN"),'Migration plan action is not wired.');
$a(str_contains($plugin,"'admin_post_' . PeerTube_Migration_Admin::ACTION_REVIEW"),'Migration review action is not wired.');
$a(str_contains($plugin,"'admin_post_' . PeerTube_Migration_Admin::ACTION_EXECUTE"),'Migration execute action is not wired.');
foreach (array('PeerTube_Migration_Plan.php','PeerTube_Migration_Execution.php','PeerTube_Migration_Planner.php','PeerTube_Migration_Executor.php','PeerTube_Migration_Admin.php','Settings_Hub.php') as $file) {
    $a(str_contains($bootstrap,$file),'Bootstrap missing '.$file);
}
$a(str_contains($planner,'MAX_SELECT_ALL = 500'),'Select-all planning is not explicitly bounded.');
$a(str_contains($planner,'Video_Meta::PEERTUBE_MIGRATION_PLAN'),'Planner does not write isolated migration state.');
$a(!str_contains($planner,'update_post_meta($video_id, Video_Meta::DESTINATION'),'Planner writes live destination.');
$a(!str_contains($planner,'update_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN'),'Planner writes live publication plan.');
$a(!str_contains($planner,'update_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE'),'Planner writes live lifecycle.');
$a(!str_contains($planner,'update_post_meta($video_id, Video_Meta::SERVING_AUTHORITY'),'Planner writes serving authority.');
$a(str_contains($executor,'Video_Meta::PEERTUBE_MIGRATION_EXECUTION'),'Executor lacks crash-recoverable migration journal.');
$a(str_contains($executor,'Video_Meta::PEERTUBE_PUBLICATION_PLAN') && str_contains($executor,'Video_Meta::DESTINATION'),'Executor does not explicitly promote reviewed migration state.');
$a(str_contains($executor,'PeerTube_Publication_Manifest::build'),'Executor does not revalidate the reviewed plan against execution-time provider/default/thumbnail state before commitment.');

$a(str_contains($admin, '$this->planned_table();') && str_contains($admin, '$this->planner_form();'), 'Migration page does not render both queue and planner.');
$a(strpos($admin, '$this->planned_table();') < strpos($admin, '$this->planner_form();'), 'Migration review queue must render before the long candidate planner.');
foreach (array('publication[review][title]','publication[review][channel]','publication[review][tags]','publication[review][privacy]','publication[review][moderation]') as $legacy_checkbox) {
    $a(!str_contains($admin, 'name="' . $legacy_checkbox . '"'), 'Migration UI still renders separate review checkbox: ' . $legacy_checkbox);
}
$a(str_contains($admin, 'name="publication[review_all]"'), 'Migration UI lacks the consolidated review checkbox.');
$a(str_contains($admin, '$this->references->posts_for('), 'Migration UI does not expose referencing post links.');

echo "PeerTube migration admin structural test passed.\n";
