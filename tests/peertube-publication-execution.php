<?php
declare(strict_types=1);
function absint(mixed $v):int{return abs((int)$v);} function sanitize_key(mixed $v):string{return strtolower((string)$v);}
require_once dirname(__DIR__).'/includes/Backend_Identity.php'; require_once dirname(__DIR__).'/includes/Backend_Registry.php'; require_once dirname(__DIR__).'/includes/PeerTube_Publication_Plan.php'; require_once dirname(__DIR__).'/includes/PeerTube_Publication_Lifecycle.php'; require_once dirname(__DIR__).'/includes/PeerTube_Publication_Manifest.php'; require_once dirname(__DIR__).'/includes/PeerTube_Publication_Execution.php';
use ArgentVideo\PeerTube_Publication_Manifest as Manifest; use ArgentVideo\PeerTube_Publication_Execution as Execution;
$a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
$m=array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41','anchor_post_id'=>10,'plan_sha256'=>str_repeat('a',64),'title'=>'Reviewed title','description_markdown'=>'','tags'=>array(),'support_markdown'=>'','final_privacy_id'=>'1','licence_id'=>'','category_id'=>'','language'=>'','thumbnail_attachment_id'=>0,'thumbnail_sha256'=>'','thumbnail_bytes'=>0,'thumbnail_mime'=>'','download_enabled'=>true,'originally_published_at'=>'','comments_policy'=>'enabled','moderation'=>array('reviewed'=>true,'sensitive'=>false,'reason'=>'','violent'=>false,'sexually_explicit'=>false));
$m=Manifest::sanitize($m);$a([]!==$m,'Fixture manifest invalid.');$e=Execution::create($m,1000);$a([]!==$e&&''===$e['operation_id'],'Execution create failed.');
$e=Execution::with_operation($e,'upload_'.str_repeat('a',32),1010);$a('upload_'.str_repeat('a',32)===$e['operation_id'],'Operation identity did not persist.');
$uuid='123e4567-e89b-42d3-a456-426614174000';$e=Execution::with_remote($e,55,$uuid,1020);$a(55===$e['remote_asset_id']&&$uuid===$e['remote_uuid'],'Remote identity did not persist.');
$e=Execution::mark_applied($e,$m,1030);$a(Manifest::sha256($m)===$e['applied_manifest_sha256'],'Applied manifest was not frozen.');
$changed=$m;$changed['title']='Updated title';$changed=Manifest::sanitize($changed);$e2=Execution::with_manifest($e,$changed,1040);$a('Updated title'===$e2['manifest']['title']&&$e2['applied_manifest']['title']==='Reviewed title','New desired manifest overwrote applied manifest.');
$wrong=$changed;$wrong['channel_id']='42';$a([]===Execution::with_manifest($e,$wrong,1050),'Execution silently changed remote channel identity.');
$future=$e;$future['version']=2;$a([]===Execution::sanitize($future),'Future execution schema was accepted.');
fwrite(STDOUT,"R46.5b publication execution tests passed.\n");
