<?php
/** Focused model tests for R46.8 one-way migration execution journal. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Input.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Plan.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Migration_Plan.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Migration_Execution.php';

use ArgentVideo\PeerTube_Migration_Execution;
use ArgentVideo\PeerTube_Migration_Plan;
use ArgentVideo\PeerTube_Publication_Plan;

$a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
$publication=array(
    'version'=>1,'backend_id'=>'pt-primary','channel_id'=>'77','title'=>'Legacy Clip','description_markdown'=>'',
    'tags'=>array(),'support'=>array('mode'=>'none','preset_id'=>'','markdown'=>''),'final_privacy_id'=>'2',
    'licence_id'=>'','category_id'=>'','language'=>'','thumbnail_attachment_id'=>0,'download_enabled'=>true,
    'originally_published_at'=>'','comments_policy'=>'enabled',
    'moderation'=>array('reviewed'=>true,'sensitive'=>false,'reason'=>'','violent'=>false,'sexually_explicit'=>false),
    'embed'=>array('restricted'=>false,'domains'=>array()),
    'review'=>array('title'=>true,'channel'=>true,'tags'=>true,'privacy'=>true,'moderation'=>true),
    'dispatch_policy'=>PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,
    'release_policy'=>PeerTube_Publication_Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,'anchor_post_id'=>10,
);
$migration=array(
    'version'=>1,'video_id'=>100,'attachment_id'=>20,'anchor_post_id'=>10,'backend_id'=>'pt-primary','channel_id'=>'77',
    'status'=>'ready','issues'=>array(),'suggested_tags'=>array('alpha'),'publication_plan'=>$publication,'planned_at'=>1000,'updated_at'=>1100,
);
$hash=PeerTube_Migration_Execution::migration_plan_sha256($migration);
$a(1===preg_match('/^[a-f0-9]{64}$/D',$hash),'Ready migration plan did not hash canonically.');
$prepared=array(
    'version'=>1,'video_id'=>100,'migration_plan_sha256'=>$hash,'backend_id'=>'pt-primary','channel_id'=>'77','anchor_post_id'=>10,
    'status'=>'prepared','lifecycle_generation'=>0,'task_id'=>0,'started_at'=>1200,'updated_at'=>1200,'promoted_at'=>0,'dispatched_at'=>0,
);
$a(array()!==PeerTube_Migration_Execution::sanitize($prepared),'Valid prepared execution rejected.');
$a(PeerTube_Migration_Execution::committed($prepared),'Prepared journal was not treated as one-way commitment.');
$promoted=$prepared;$promoted['status']='promoted';$promoted['updated_at']=1300;$promoted['promoted_at']=1300;
$a(array()!==PeerTube_Migration_Execution::sanitize($promoted),'Valid promoted execution rejected.');
$dispatched=$promoted;$dispatched['status']='dispatched';$dispatched['lifecycle_generation']=2;$dispatched['task_id']=44;$dispatched['updated_at']=1400;$dispatched['dispatched_at']=1400;
$a(array()!==PeerTube_Migration_Execution::sanitize($dispatched),'Valid dispatched execution rejected.');
$bad=$prepared;$bad['task_id']=1;$a(array()===PeerTube_Migration_Execution::sanitize($bad),'Prepared execution accepted task identity.');
$bad=$promoted;$bad['promoted_at']=1100;$a(array()===PeerTube_Migration_Execution::sanitize($bad),'Promoted execution accepted pre-start promotion timestamp.');
$bad=$dispatched;$bad['dispatched_at']=1200;$a(array()===PeerTube_Migration_Execution::sanitize($bad),'Dispatched execution accepted dispatch before promotion.');
$needs=$migration;$needs['status']='needs_review';$needs['issues']=array('review_tags');$needs['publication_plan']['review']['tags']=false;
$a(''===PeerTube_Migration_Execution::migration_plan_sha256($needs),'Needs Review migration plan received execution hash.');
$keys=array_keys(PeerTube_Migration_Execution::sanitize($prepared));
$a(array('version','video_id','migration_plan_sha256','backend_id','channel_id','anchor_post_id','status','lifecycle_generation','task_id','started_at','updated_at','promoted_at','dispatched_at')===$keys,'Migration execution key order changed unexpectedly.');
echo "PeerTube migration execution test passed.\n";
