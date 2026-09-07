<?php
/** Focused model tests for R46.7 inert migration plans. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Input.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Plan.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Migration_Plan.php';

use ArgentVideo\PeerTube_Migration_Plan;
use ArgentVideo\PeerTube_Publication_Plan;

$a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
$publication=array(
    'version'=>1,'backend_id'=>'pt-primary','channel_id'=>'77','title'=>'Legacy Clip','description_markdown'=>'',
    'tags'=>array(),'support'=>array('mode'=>'none','preset_id'=>'','markdown'=>''),'final_privacy_id'=>'2',
    'licence_id'=>'','category_id'=>'','language'=>'','thumbnail_attachment_id'=>0,'download_enabled'=>true,
    'originally_published_at'=>'','comments_policy'=>'enabled',
    'moderation'=>array('reviewed'=>false,'sensitive'=>false,'reason'=>'','violent'=>false,'sexually_explicit'=>false),
    'embed'=>array('restricted'=>false,'domains'=>array()),
    'review'=>array('title'=>false,'channel'=>false,'tags'=>false,'privacy'=>false,'moderation'=>false),
    'dispatch_policy'=>PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,
    'release_policy'=>PeerTube_Publication_Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,'anchor_post_id'=>10,
);
$record=array(
    'version'=>1,'video_id'=>100,'attachment_id'=>20,'anchor_post_id'=>10,'backend_id'=>'pt-primary','channel_id'=>'77',
    'status'=>'needs_review','issues'=>array('review_tags','review_title'),'suggested_tags'=>array('alpha','beta'),
    'publication_plan'=>$publication,'planned_at'=>1000,'updated_at'=>1000,
);
$s=PeerTube_Migration_Plan::sanitize($record);
$a(array()!==$s,'Valid Needs Review migration plan rejected.');
$a(array('review_tags','review_title')===$s['issues'],'Issues were not normalized deterministically.');

$bad=$record;$bad['backend_id']='other';$a(array()===PeerTube_Migration_Plan::sanitize($bad),'Publication-plan/backend mismatch accepted.');
$bad=$record;$bad['status']='needs_review';$bad['issues']=array();$a(array()===PeerTube_Migration_Plan::sanitize($bad),'Needs Review record without issues accepted.');
$bad=$record;$bad['suggested_tags']=array_fill(0,PeerTube_Migration_Plan::MAX_SUGGESTED_TAGS+1,'x');$a(array()===PeerTube_Migration_Plan::sanitize($bad),'Unbounded suggested tags accepted.');

$ready=$record;$ready['publication_plan']['review']=array('title'=>true,'channel'=>true,'tags'=>true,'privacy'=>true,'moderation'=>true);$ready['publication_plan']['moderation']['reviewed']=true;$ready['status']='ready';$ready['issues']=array();
$a(array()!==PeerTube_Migration_Plan::sanitize($ready),'Fully reviewed ready migration plan rejected.');
$not_ready=$ready;$not_ready['publication_plan']['review']['privacy']=false;$a(array()===PeerTube_Migration_Plan::sanitize($not_ready),'Ready migration record accepted without required review.');

$keys=array_keys($s);$a(array('version','video_id','attachment_id','anchor_post_id','backend_id','channel_id','status','issues','suggested_tags','publication_plan','planned_at','updated_at')===$keys,'Migration-plan key order changed unexpectedly.');

echo "PeerTube migration plan test passed.\n";
