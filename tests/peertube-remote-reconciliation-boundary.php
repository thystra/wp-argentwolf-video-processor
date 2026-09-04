<?php
/** R44/R45 boundary: reconciliation is reachable only through one-shot WP-CLI task execution. */
declare(strict_types=1);

require_once dirname(__DIR__).'/includes/Backend_Capabilities.php';

use ArgentVideo\Backend_Capabilities;

$assert=static function(bool $ok,string $m):void{
    if(!$ok){
        fwrite(STDERR,"FAIL: $m\n");
        exit(1);
    }
};

$c=Backend_Capabilities::peertube_activation();
foreach(
    array(
        Backend_Capabilities::INGEST_AWVP_STAGING,
        Backend_Capabilities::INGEST_SERVER_PUSH,
        Backend_Capabilities::PROCESSING_VIDEO
    ) as $cap
){
    $assert(false===($c[$cap]??null),'R44/R45 prematurely enabled capability '.$cap);
}

$root=dirname(__DIR__);
$http=(string)file_get_contents($root.'/includes/PeerTube_Http_Client.php');
$api=(string)file_get_contents($root.'/includes/PeerTube_Api_Client.php');
$svc=(string)file_get_contents($root.'/includes/PeerTube_Remote_Asset_Reconciliation_Service.php');
$repo=(string)file_get_contents($root.'/includes/Remote_Asset_Repository.php');
$plugin=(string)file_get_contents($root.'/includes/Plugin.php');
$admin=(string)file_get_contents($root.'/includes/PeerTube_Connection_Admin.php');
$cli=(string)file_get_contents($root.'/includes/CLI_Command.php');
$loader=(string)file_get_contents($root.'/argentwolf-video-processor.php');

$assert(
    str_contains($http,'get_video_status')&&str_contains($api,'video_status'),
    'R44 reviewed GET projection is missing.'
);
$assert(
    !str_contains($svc,'upload_resumable')&&!str_contains($svc,'begin_resumable_upload'),
    'R44 reconciliation service can initiate/continue media upload.'
);
$assert(
    !str_contains($svc,'unlink(')&&!str_contains($svc,'delete(')&&!str_contains($svc,'wp_delete'),
    'R44 reconciliation service acquired source/remote delete authority.'
);
$assert(
    !str_contains($svc,'wp_schedule')&&!str_contains($svc,'wp_cron'),
    'R44 reconciliation service schedules automatic polling.'
);
$assert(
    str_contains($repo,"'role'                    => 'secondary'")
        &&str_contains($repo,"'desired_privacy'         => 'private'"),
    'R44 remote-asset commit escaped secondary/private staging authority.'
);
$assert(
    str_contains($loader,'PeerTube_Remote_Asset_Reconciliation_Service.php')
        &&str_contains($loader,'Remote_Asset_Repository.php'),
    'R44 classes are not class-loadable.'
);

$wp_cli_guard=strpos($plugin,"if (defined('WP_CLI') && WP_CLI)");
$reconcile_build=strpos($plugin,'$peertube_reconciliation = new PeerTube_Remote_Asset_Reconciliation_Service(');
$assert(
    false!==$wp_cli_guard&&false!==$reconcile_build&&$reconcile_build>$wp_cli_guard,
    'R45.3b remote reconciliation is not composed strictly behind the WP_CLI guard.'
);
$assert(
    !str_contains($admin,'PeerTube_Remote_Asset_Reconciliation_Service'),
    'Remote reconciliation leaked into PeerTube admin actions.'
);
$assert(
    str_contains($cli,'public function peertube_task_worker(')
        &&!str_contains($cli,'PeerTube_Remote_Asset_Reconciliation_Service'),
    'PeerTube CLI boundary bypasses the task worker and directly owns R44 reconciliation.'
);

$surface=strtolower($admin);
foreach(
    array(
        'remote_asset_reconciliation',
        'remote_reconciliation',
        'staged_upload',
        'upload_resumable',
        'register_rest_route',
        'wp_ajax'
    ) as $needle
){
    $assert(
        !str_contains($surface,$needle),
        'R44 exposed a browser/admin reconciliation/upload entry point: '.$needle
    );
}
foreach(array('register_rest_route','wp_ajax','admin_post_argentwolf_video_processor_peertube_upload') as $needle){
    $assert(
        !str_contains(strtolower($plugin),strtolower($needle)),
        'R45.3b Plugin exposed an unreviewed reconciliation/upload entry point: '.$needle
    );
}

// R44 adds read-only GET /videos/{uuid}; it must not add generic video mutation helpers.
foreach(array('post_video_status','put_video_status','delete_video','publish_video','update_video') as $needle){
    $assert(
        !str_contains(strtolower($http."\n".$api."\n".$svc),$needle),
        'R44 added an unreviewed remote-video mutation surface: '.$needle
    );
}

fwrite(STDOUT,"PeerTube remote reconciliation production-boundary tests passed.\n");
