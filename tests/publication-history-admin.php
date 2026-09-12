<?php
/** Structural safety tests for the RC13.2 read-only publication history surface. */
declare(strict_types=1);

$assert=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}};
$admin=(string)file_get_contents(dirname(__DIR__).'/includes/Publication_History_Admin.php');
$events=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Event_Repository.php');
$settings=(string)file_get_contents(dirname(__DIR__).'/includes/Settings_Hub.php');
$plugin=(string)file_get_contents(dirname(__DIR__).'/includes/Plugin.php');
$bootstrap=(string)file_get_contents(dirname(__DIR__).'/argentwolf-video-processor.php');

foreach(array('Publication History & Logs','Details & log','Current serving','Last event','Filter history','Open remote video','recent_global','Event retention remains limited') as $needle){
    $assert(str_contains($admin,$needle),'Publication history UI is missing: '.$needle);
}
foreach(array('admin_post_','update_post_meta(','delete_post_meta(','->enqueue(','wp_remote_','PeerTube_Api_Client') as $forbidden){
    $assert(!str_contains($admin,$forbidden),'Read-only history surface acquired consequential behavior: '.$forbidden);
}
$assert(str_contains($events,'MAX_GLOBAL_RECENT = 100'),'Global history query is not explicitly bounded.');
$assert(str_contains($events,'public function recent_global'),'Event repository lacks bounded global history retrieval.');
$assert(str_contains($settings,"TAB_HISTORY = 'publication-history'"),'Settings hub lacks History & Logs tab identity.');
$assert(str_contains($plugin,'new Publication_History_Admin('),'Plugin does not compose publication history.');
$assert(str_contains($bootstrap,'includes/Publication_History_Admin.php'),'Plugin bootstrap does not load publication history.');

echo "Publication history admin structural tests passed.\n";
