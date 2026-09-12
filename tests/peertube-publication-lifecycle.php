<?php
/** Focused tests for the R46.5a local publication lifecycle contract. */
declare(strict_types=1);

function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false { return json_encode($value, $flags, $depth); }
require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Plan.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Lifecycle.php';

use ArgentVideo\PeerTube_Publication_Lifecycle as Lifecycle;
use ArgentVideo\PeerTube_Publication_Plan as Plan;

$assert = static function(bool $ok,string $message): void { if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);} };
$plan = array(
    'version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41','title'=>'Reviewed title',
    'description_markdown'=>'','tags'=>array(),'support'=>array('mode'=>'none','preset_id'=>'','markdown'=>''),
    'final_privacy_id'=>'1','licence_id'=>'','category_id'=>'','language'=>'','thumbnail_attachment_id'=>0,
    'download_enabled'=>true,'originally_published_at'=>'','comments_policy'=>'enabled',
    'moderation'=>array('reviewed'=>true,'sensitive'=>false,'reason'=>'','violent'=>false,'sexually_explicit'=>false),
    'embed'=>array('restricted'=>false,'domains'=>array()),
    'review'=>array('title'=>true,'channel'=>true,'tags'=>true,'privacy'=>true,'moderation'=>true),
    'dispatch_policy'=>Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,
    'release_policy'=>Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,'anchor_post_id'=>10,
);
$sha=Lifecycle::plan_sha256($plan);
$assert(1===preg_match('/^[a-f0-9]{64}$/D',$sha),'Plan SHA commitment was not deterministic.');
$assert('e58170c14ad9b21d1c98e8a6df8c83ac1135d32de1f2d72505cb5c3f75f35956'===$sha,'Legacy version-1 plan hash changed after adding optional pre-publication visibility.');
$base=array(
    'version'=>1,'generation'=>1,'backend_id'=>'pt-primary','anchor_post_id'=>10,'plan_sha256'=>$sha,
    'dispatch_policy'=>Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,'wordpress_status'=>'future',
    'upload_authorized'=>true,'reveal_authorized'=>false,'target_privacy_id'=>'3','task_pending'=>true,'updated_at'=>1000,
);
$clean=Lifecycle::sanitize($base);
$assert($clean===$base,'Valid scheduled/private lifecycle intent was rejected.');
$published=$base; $published['wordpress_status']='publish'; $published['reveal_authorized']=true; $published['target_privacy_id']='1';
$assert($published===Lifecycle::sanitize($published),'Actual WordPress publish did not permit reviewed final privacy.');
$bad=$base; $bad['target_privacy_id']='1';
$assert(array()===Lifecycle::sanitize($bad),'Non-published state was allowed to target non-private PeerTube privacy.');
$pre=$base; $pre['dispatch_policy']=Plan::DISPATCH_SEND_NOW; $pre['target_privacy_id']=Plan::PRE_PUBLISH_UNLISTED;
$assert($pre===Lifecycle::sanitize($pre),'Scheduled Send-now lifecycle rejected reviewed Unlisted pre-publication visibility.');
$preBad=$pre; $preBad['dispatch_policy']=Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH;
$assert(array()===Lifecycle::sanitize($preBad),'On-schedule lifecycle incorrectly allowed Unlisted pre-publication visibility.');
$preDraft=$pre; $preDraft['wordpress_status']='draft';
$assert(array()===Lifecycle::sanitize($preDraft),'Draft Send-now lifecycle incorrectly allowed Unlisted pre-publication visibility.');
$private=$published; $private['wordpress_status']='private'; $private['reveal_authorized']=false; $private['target_privacy_id']='3';
$assert($private===Lifecycle::sanitize($private),'WordPress private could not express remote-private intent.');
$sem1=Lifecycle::semantic($base); $again=$base; $again['generation']=8; $again['task_pending']=false; $again['updated_at']=2000;
$assert($sem1===Lifecycle::semantic($again),'Generation/queue bookkeeping leaked into semantic lifecycle equality.');
$assert('other'===Lifecycle::status_bucket('custom-status'),'Unknown WordPress status did not fail into the private other bucket.');
fwrite(STDOUT,"R46.5a publication lifecycle tests passed.\n");
