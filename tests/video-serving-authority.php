<?php
/** Focused tests for R46.6 durable serving authority. */
declare(strict_types=1);
function absint(mixed $v):int{return abs((int)$v);} function sanitize_key(mixed $v):string{return strtolower((string)$v);} function wp_json_encode(mixed $v,int $flags=0):string|false{return json_encode($v,$flags);} function wp_parse_url(string $v):array|false{return parse_url($v);}
require_once dirname(__DIR__).'/includes/Backend_Identity.php';
require_once dirname(__DIR__).'/includes/Backend_Registry.php';
require_once dirname(__DIR__).'/includes/PeerTube_Connection_Input.php';
require_once dirname(__DIR__).'/includes/PeerTube_Origin.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Plan.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Lifecycle.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Manifest.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Execution.php';
require_once dirname(__DIR__).'/includes/Video_Serving_Authority.php';
use ArgentVideo\PeerTube_Publication_Plan as Plan; use ArgentVideo\PeerTube_Publication_Lifecycle as Lifecycle; use ArgentVideo\PeerTube_Publication_Manifest as Manifest; use ArgentVideo\PeerTube_Publication_Execution as Execution; use ArgentVideo\Video_Serving_Authority as Authority;
$a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
$plan=array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41','title'=>'Reviewed title','description_markdown'=>'','tags'=>array(),'support'=>array('mode'=>'none','preset_id'=>'','markdown'=>''),'final_privacy_id'=>'1','licence_id'=>'','category_id'=>'','language'=>'','thumbnail_attachment_id'=>0,'download_enabled'=>true,'originally_published_at'=>'','comments_policy'=>'enabled','moderation'=>array('reviewed'=>true,'sensitive'=>false,'reason'=>'','violent'=>false,'sexually_explicit'=>false),'embed'=>array('restricted'=>false,'domains'=>array()),'review'=>array('title'=>true,'channel'=>true,'tags'=>true,'privacy'=>true,'moderation'=>true),'dispatch_policy'=>Plan::DISPATCH_SEND_NOW,'release_policy'=>Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,'anchor_post_id'=>10);
$sha=Lifecycle::plan_sha256($plan);
$lifecycle=Lifecycle::sanitize(array('version'=>1,'generation'=>4,'backend_id'=>'pt-primary','anchor_post_id'=>10,'plan_sha256'=>$sha,'dispatch_policy'=>Plan::DISPATCH_SEND_NOW,'wordpress_status'=>'publish','upload_authorized'=>true,'reveal_authorized'=>true,'target_privacy_id'=>'1','task_pending'=>false,'updated_at'=>1000));
$manifest=Manifest::sanitize(array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41','anchor_post_id'=>10,'plan_sha256'=>$sha,'title'=>'Reviewed title','description_markdown'=>'','tags'=>array(),'support_markdown'=>'','final_privacy_id'=>'1','licence_id'=>'','category_id'=>'','language'=>'','thumbnail_attachment_id'=>0,'thumbnail_sha256'=>'','thumbnail_bytes'=>0,'thumbnail_mime'=>'','download_enabled'=>true,'originally_published_at'=>'','comments_policy'=>'enabled','moderation'=>array('reviewed'=>true,'sensitive'=>false,'reason'=>'','violent'=>false,'sexually_explicit'=>false)));
$execution=Execution::create($manifest,1000);$execution=Execution::with_operation($execution,'upload_'.str_repeat('a',32),1010);$execution=Execution::with_remote($execution,55,'123e4567-e89b-42d3-a456-426614174000',1020);$execution=Execution::mark_applied($execution,$manifest,1030);
$asset=array('id'=>55,'video_post_id'=>77,'backend_id'=>'pt-primary','channel_id'=>'41','remote_id'=>'123e4567-e89b-42d3-a456-426614174000','role'=>'secondary','state'=>'ready','desired_privacy'=>'public','actual_privacy'=>'public','remote_processing_state'=>'1:published','embed_url'=>'https://video.example.org/videos/embed/123e4567-e89b-42d3-a456-426614174000','last_verified_at'=>'2026-09-07 13:00:00');
$record=Authority::create(77,$lifecycle,$execution,$asset,1040);$a([]!==$record,'Verified public evidence did not create serving authority.');$a('peertube'===$record['mode']&&'1'===$record['privacy_id'],'Serving authority fields drifted.');$a($record===Authority::sanitize($record),'Serving authority did not round-trip exactly.');
$bad=$asset;$bad['embed_url']='https://evil.example/videos/embed/aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';$a([]===Authority::create(77,$lifecycle,$execution,$bad,1040),'Mismatched embed UUID authorized serving.');
$private=$lifecycle;$private['reveal_authorized']=false;$private['target_privacy_id']='3';$a([]===Authority::create(77,$private,$execution,$asset,1040),'Private lifecycle created remote serving authority.');
$badexec=$execution;$badexec['applied_manifest_sha256']='';$badexec['applied_manifest']=array();$a([]===Authority::create(77,$lifecycle,$badexec,$asset,1040),'Unapplied publication execution created serving authority.');
fwrite(STDOUT,"R46.6 serving authority tests passed.\n");
