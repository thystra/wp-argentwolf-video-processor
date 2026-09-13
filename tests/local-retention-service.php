<?php
/** Dependency-free RC13.2 scheduling/cleanup orchestration tests. */
declare(strict_types=1);
namespace ArgentVideo {
$GLOBALS['r469_meta']=array();
$GLOBALS['r469_outputs']=array(20=>array('mp4'=>array('path'=>'x')));
$GLOBALS['r469_storage_removed']=0;
$GLOBALS['r469_source_deleted']=0;
$GLOBALS['r469_source_matches']=true;
$GLOBALS['r469_source_absent']=false;
$GLOBALS['r469_local_delivery_available']=true;
$GLOBALS['r469_local_delivery_matches']=true;
$GLOBALS['r469_local_delivery_match_hook']=null;
$GLOBALS['r469_video_status']=array(100=>'publish',101=>'publish',102=>'publish');
$GLOBALS['r469_video_refs']=array(100);
function wp_json_encode(mixed $v,int $flags=0):string|false{return json_encode($v,$flags);}
function sanitize_text_field(mixed $v):string{return trim(strip_tags((string)$v));}
function get_post(int $id):mixed{if(isset($GLOBALS['r469_video_status'][$id]))return(object)array('ID'=>$id,'post_type'=>Video_Post_Type::POST_TYPE,'post_status'=>$GLOBALS['r469_video_status'][$id]);if(in_array($id,array(20,21),true))return(object)array('ID'=>$id,'post_type'=>'attachment','post_status'=>'inherit');return null;}
function get_post_meta(int $id,string $k,bool $single=true):mixed{unset($single);if(in_array($id,array(20,21),true)&&'_argent_video_outputs'===$k)return $GLOBALS['r469_outputs'][$id]??'';return $GLOBALS['r469_meta'][$id][$k]??'';}
function update_post_meta(int $id,string $k,mixed $v):int|bool{$GLOBALS['r469_meta'][$id][$k]=$v;return 1;}
function metadata_exists(string $type,int $id,string $k):bool{unset($type);if(in_array($id,array(20,21),true)&&'_argent_video_outputs'===$k)return isset($GLOBALS['r469_outputs'][$id]);return array_key_exists($k,$GLOBALS['r469_meta'][$id]??array());}
function delete_post_meta(int $id,string $k):bool{if(in_array($id,array(20,21),true)&&'_argent_video_outputs'===$k){unset($GLOBALS['r469_outputs'][$id]);return true;}unset($GLOBALS['r469_meta'][$id][$k]);return true;}
function get_posts(array $args=array()):array{unset($args);return $GLOBALS['r469_video_refs'];}
function is_dir(string $path):bool{return str_starts_with($path,'/tmp/fake-');}
final class Video_Post_Type{public const POST_TYPE='argent_video_asset';}
final class Video_Destination{public static function resolve(mixed $v,bool $e):array{unset($v,$e);return array('version'=>1,'backend_id'=>'local');}public static function is_local(array $v):bool{return 'local'===($v['backend_id']??'');}}
final class Video_Meta{
 public const MASTER_AUTHORITY='master';public const SOURCE_STATE='source';public const CLEANUP_STATE='cleanup';public const LOCAL_RETENTION_POLICY='policy';public const LOCAL_RETENTION_EXECUTION='execution';public const ATTACHMENT_ID='attachment';public const SERVING_AUTHORITY='authority';public const DESTINATION='destination';
 public static function sanitize_master_authority(mixed $v):string{return in_array($v,array('wordpress_source','backend_source','external_archive','none'),true)?$v:'unknown';}
 public static function sanitize_source_state(mixed $v):string{return in_array($v,array('present','verified_remote','removed','error'),true)?$v:'error';}
 public static function sanitize_cleanup_state(mixed $v):string{return in_array($v,array('none','held','pending','eligible','running','complete','blocked','failed'),true)?$v:'none';}
 public static function sanitize_positive_id(mixed $v):int{return is_numeric($v)&&((int)$v)>0?(int)$v:0;}
}
final class Backend_Registry{public const LOCAL_ID='local';}
final class Archive_Of_Record_Policy_Store{
 public bool $allowed=false;public int $grace=7;
 public function source_deletion_allowed():bool{return $this->allowed;}
 public function wordpress_is_archive():bool{return !$this->allowed;}
 public function grace_days():int{return $this->grace;}
}
final class Local_Retention_Policy{
 public const VERSION=1,MODE_KEEP='keep',MODE_DELETE_MANAGED='delete_managed',MODE_DELETE_SOURCE_KEEP_DELIVERY='delete_source_keep_delivery',MODE_DELETE_ALL='delete_all',MIN_GRACE_DAYS=0,MAX_GRACE_DAYS=365;
 public static function create(string $m,int $g,int $u,int $n):array{if(!in_array($m,array(self::MODE_KEEP,self::MODE_DELETE_MANAGED,self::MODE_DELETE_SOURCE_KEEP_DELIVERY,self::MODE_DELETE_ALL),true)||$u<1||$n<1)return array();if(self::MODE_KEEP===$m)$g=0;elseif($g<0||$g>365)return array();return array('version'=>1,'mode'=>$m,'grace_days'=>$g,'confirmed_by'=>$u,'confirmed_at'=>$n);}
 public static function sanitize(mixed $v):array{return is_array($v)&&1===($v['version']??0)?$v:array();}
 public static function sha256(array $p):string{return hash('sha256',json_encode($p));}
 public static function destructive(array $p):bool{return self::MODE_KEEP!==($p['mode']??'keep');}
 public static function automatic_cleanup_enabled(array $p):bool{return self::destructive($p)&&(int)($p['grace_days']??0)>0;}
 public static function deletes_source(array $p):bool{return in_array($p['mode']??'',array(self::MODE_DELETE_SOURCE_KEEP_DELIVERY,self::MODE_DELETE_ALL),true);}
 public static function deletes_managed(array $p):bool{return in_array($p['mode']??'',array(self::MODE_DELETE_MANAGED,self::MODE_DELETE_ALL),true);}
 public static function keeps_local_delivery(array $p):bool{return self::MODE_DELETE_SOURCE_KEEP_DELIVERY===($p['mode']??'');}
}
final class Video_Serving_Authority{public static function sanitize(mixed $v):array{return is_array($v)&&isset($v['generation'],$v['plan_sha256'],$v['manifest_sha256'],$v['remote_asset_id'],$v['remote_uuid'],$v['verified_at'],$v['backend_id'])?$v:array();}}
final class WordPress_Source_File{
 public static function capture(int $id):array{return in_array($id,array(20,21),true)?array('relative_path'=>'2026/09/video-'.$id.'.mp4','bytes'=>10,'device'=>1,'inode'=>$id,'mtime'=>3,'ctime'=>4):array();}
 public static function sanitize_identity(mixed $v):array{return is_array($v)?$v:array();}
 public static function matches(int $id,array $v):bool{unset($id,$v);return (bool)$GLOBALS['r469_source_matches'];}
 public static function absent(int $id,array $v):bool{unset($id,$v);return (bool)$GLOBALS['r469_source_absent'];}
 public static function delete(int $id,array $v):bool{unset($id,$v);$GLOBALS['r469_source_deleted']++;$GLOBALS['r469_source_absent']=true;return true;}
}
final class Local_Delivery_Evidence{
 public static function capture(int $id):array{return $GLOBALS['r469_local_delivery_available']?array('version'=>1,'attachment_id'=>$id,'hls_url'=>'https://example.test/local/master.m3u8','file_count'=>4,'bytes'=>100,'tree_sha256'=>str_repeat('c',64)):array();}
 public static function sanitize(mixed $v):array{return is_array($v)&&1===($v['version']??0)?$v:array();}
 public static function sha256(array $v):string{return array()===$v?'':hash('sha256',json_encode($v));}
 public static function matches(int $id,array $v):bool{unset($id,$v);$hook=$GLOBALS['r469_local_delivery_match_hook']??null;if(is_callable($hook)){$GLOBALS['r469_local_delivery_match_hook']=null;$hook();}return (bool)$GLOBALS['r469_local_delivery_matches'];}
}
final class Local_Retention_Execution{
 public const PROOF_REMOTE='remote',PROOF_LOCAL_HLS='local_hls',STATUS_QUEUED='queued',STATUS_RUNNING='running',STATUS_COMPLETE='complete',STATUS_BLOCKED='blocked',STATUS_FAILED='failed';
 public static function create_remote(int $vid,int $att,array $p,array $a,array $s,int $attempt,int $eligible,int $now):array{return array('version'=>2,'attempt'=>$attempt,'video_id'=>$vid,'attachment_id'=>$att,'policy_sha256'=>Local_Retention_Policy::sha256($p),'mode'=>$p['mode'],'proof_kind'=>self::PROOF_REMOTE,'proof_sha256'=>self::authority_sha256($a),'backend_id'=>$a['backend_id'],'remote_asset_id'=>$a['remote_asset_id'],'local_delivery'=>array(),'source'=>$s,'eligible_at'=>$eligible,'status'=>self::STATUS_QUEUED,'task_id'=>0,'prepared_at'=>$now,'completed_at'=>0,'last_error'=>'');}
 public static function create_local(int $vid,int $att,array $p,array $d,array $s,int $attempt,int $eligible,int $now):array{return array('version'=>2,'attempt'=>$attempt,'video_id'=>$vid,'attachment_id'=>$att,'policy_sha256'=>Local_Retention_Policy::sha256($p),'mode'=>$p['mode'],'proof_kind'=>self::PROOF_LOCAL_HLS,'proof_sha256'=>Local_Delivery_Evidence::sha256($d),'backend_id'=>'local','remote_asset_id'=>0,'local_delivery'=>$d,'source'=>$s,'eligible_at'=>$eligible,'status'=>self::STATUS_QUEUED,'task_id'=>0,'prepared_at'=>$now,'completed_at'=>0,'last_error'=>'');}
 public static function sanitize(mixed $v):array{return is_array($v)&&in_array($v['version']??0,array(1,2),true)?$v:array();}
 public static function immutable_sha256(array $r):string{$x=$r;unset($x['status'],$x['task_id'],$x['completed_at'],$x['last_error']);return hash('sha256',json_encode($x));}
 public static function with_task(array $r,int $id):array{$r['task_id']=$id;return$r;}
 public static function transition(array $r,string $s,int $n,string $e=''):array{$r['status']=$s;$r['last_error']=$e;$r['completed_at']=in_array($s,array(self::STATUS_COMPLETE,self::STATUS_BLOCKED,self::STATUS_FAILED),true)?$n:0;return$r;}
 public static function authority_sha256(array $a):string{return hash('sha256',json_encode($a));}
 public static function proof_kind(array $r):string{return (string)($r['proof_kind']??self::PROOF_REMOTE);}
 public static function proof_sha256(array $r):string{return (string)($r['proof_sha256']??$r['authority_sha256']??'');}
 public static function task_backend_id(array $r):?string{return (string)($r['backend_id']??'')?:null;}
 public static function task_remote_asset_id(array $r):?int{$id=(int)($r['remote_asset_id']??0);return$id>0?$id:null;}
 public static function local_delivery(array $r):array{return is_array($r['local_delivery']??null)?$r['local_delivery']:array();}
}
interface Video_Serving_Resolver{public function peertube_embed_url(int $video_id):string;}
final class FakeServing implements Video_Serving_Resolver{public string $url='https://pt.example/videos/embed/uuid';public function peertube_embed_url(int $video_id):string{unset($video_id);return$this->url;}}
final class Storage{public static function attachment_directory(int $id):string{return '/tmp/fake-'.$id;}public static function remove_tree(string $d):void{unset($d);$GLOBALS['r469_storage_removed']++;}}
final class Job_Repository{public array $jobs=array();public function find_by_attachment(int $id):?array{return $this->jobs[$id]??null;}}
final class Task_Repository{
 public const APPLIED='applied',PRESENT='present',CONFLICT='conflict';public array $enqueues=array(),$reschedules=array(),$completes=array(),$fails=array();private int $next=1;
 public function enqueue(string $type,?int $video,?int $asset,?string $backend,string $key,array $payload,int $run,int $now,int $priority=100,int $max=5):array{$this->enqueues[]=compact('type','video','asset','backend','key','payload','run','now','priority','max');return array('status'=>self::APPLIED,'task_id'=>$this->next++);}
 public function complete(int $id,string $lock,int $now):string{$this->completes[]=compact('id','lock','now');return self::APPLIED;}
 public function reschedule(int $id,string $lock,int $after,string $msg,int $now):string{$this->reschedules[]=compact('id','lock','after','msg','now');return self::APPLIED;}
 public function fail(int $id,string $lock,string $msg,int $now):string{$this->fails[]=compact('id','lock','msg','now');return self::APPLIED;}
}
}
namespace {
require_once dirname(__DIR__).'/includes/Local_Retention_Service.php';
use ArgentVideo\{Local_Retention_Service as S,Task_Repository,Job_Repository,FakeServing,Video_Meta,Local_Retention_Policy as P,Archive_Of_Record_Policy_Store};
$f=0;$a=function(bool $v,string $m)use(&$f){if(!$v){fwrite(STDERR,"FAIL: $m\n");$f++;}};
$auth=array('generation'=>2,'plan_sha256'=>str_repeat('a',64),'manifest_sha256'=>str_repeat('b',64),'remote_asset_id'=>9,'remote_uuid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','verified_at'=>1000,'backend_id'=>'pt');
$GLOBALS['r469_meta'][100]=array(Video_Meta::ATTACHMENT_ID=>20,Video_Meta::SOURCE_STATE=>'present',Video_Meta::MASTER_AUTHORITY=>'wordpress_source',Video_Meta::SERVING_AUTHORITY=>$auth);
$GLOBALS['r469_meta'][101]=array(Video_Meta::ATTACHMENT_ID=>21,Video_Meta::SOURCE_STATE=>'present',Video_Meta::MASTER_AUTHORITY=>'wordpress_source',Video_Meta::SERVING_AUTHORITY=>$auth);
$GLOBALS['r469_meta'][102]=array(Video_Meta::ATTACHMENT_ID=>20,Video_Meta::SOURCE_STATE=>'present',Video_Meta::MASTER_AUTHORITY=>'wordpress_source',Video_Meta::SERVING_AUTHORITY=>$auth);
$tasks=new Task_Repository();$jobs=new Job_Repository();$serving=new FakeServing();$svc=new S($tasks,$serving,$jobs);
$siteTasks=new Task_Repository();$siteJobs=new Job_Repository();$sitePolicy=new Archive_Of_Record_Policy_Store();$siteSvc=new S($siteTasks,$serving,$siteJobs,$sitePolicy);
$GLOBALS['r469_video_refs']=array(100);
$siteDenied=$siteSvc->configure_for_site_policy(100,P::MODE_DELETE_ALL,7,1500);$a(S::REFUSED===$siteDenied['status']&&0===count($siteTasks->enqueues),'WordPress-as-archive site policy did not prohibit per-video original deletion.');
$removeDenied=$siteSvc->remove_local_copies_now(100,7,1501);$a(S::REFUSED===$removeDenied['status']&&0===count($siteTasks->enqueues),'Explicit remove-local-copies action must refuse while WordPress is the archive of record.');
$siteManaged=$siteSvc->configure_for_site_policy(100,P::MODE_DELETE_MANAGED,7,1600);$a(S::APPLIED===$siteManaged['status']&&1600+7*86400===$siteManaged['eligible_at'],'Site policy grace was not applied to managed cleanup.');
$sitePolicy->grace=0;$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='none';
$held=$siteSvc->configure_for_site_policy(100,P::MODE_DELETE_MANAGED,7,1650);$a(S::APPLIED===$held['status']&&0===$held['task_id']&&'held'===($GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]??''),'Site grace 0 must hold cleanup indefinitely without enqueueing deletion.');
$a(!S::attachment_rebuild_blocked(20),'A Never/held retention policy should still permit an explicit local delivery rebuild.');$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='pending';$a(S::attachment_rebuild_blocked(20),'Pending destructive cleanup must fence an explicit local delivery rebuild.');$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='held';
$GLOBALS['r469_outputs'][20]=array('mp4'=>array('path'=>'manual'));$beforeManualRemoved=$GLOBALS['r469_storage_removed'];$manual=$siteSvc->cleanup_now(100,7,1651);$a(S::APPLIED===$manual['status']&&1651===$manual['eligible_at'],'Manual cleanup did not bypass only the zero-day retention wait.');$manualQueue=end($siteTasks->enqueues);$a(true===($manualQueue['payload']['manual_cleanup']??false)&&7===($manualQueue['payload']['requested_by']??0)&&1651===($manualQueue['payload']['requested_at']??0),'Manual cleanup task does not carry bounded operator authorization context.');$manualTask=array('id'=>$manual['task_id'],'video_post_id'=>100,'task_type'=>S::TASK_TYPE,'lock_token'=>'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee','payload_json'=>json_encode($manualQueue['payload']));$manualRun=$siteSvc->advance_claimed($manualTask,1651);$a(S::STATUS_COMPLETE===$manualRun['status']&&$GLOBALS['r469_storage_removed']===$beforeManualRemoved+1,'Manual cleanup with grace 0 did not run through the detached worker.');
$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='held';$GLOBALS['r469_storage_removed']=0;$GLOBALS['r469_outputs'][20]=array('mp4'=>array('path'=>'x'));
$beforeOverride=count($siteTasks->enqueues);$override=$siteSvc->configure_for_site_policy(100,P::MODE_DELETE_MANAGED,7,1660,2);$a(S::APPLIED===$override['status']&&1660+2*86400===$override['eligible_at']&&count($siteTasks->enqueues)===$beforeOverride+1,'Per-video grace override did not permit selected cleanup while site default is Never.');
$sitePolicy->allowed=true;$sitePolicy->grace=7;$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='none';$GLOBALS['r469_meta'][100][Video_Meta::SOURCE_STATE]='present';
$GLOBALS['r469_meta'][100][Video_Meta::MASTER_AUTHORITY]='wordpress_source';$GLOBALS['r469_meta'][100][Video_Meta::LOCAL_RETENTION_POLICY]=P::create(P::MODE_KEEP,0,7,1665);unset($GLOBALS['r469_meta'][100][Video_Meta::LOCAL_RETENTION_EXECUTION]);
$beforeRemoveNow=count($siteTasks->enqueues);$removeNow=$siteSvc->remove_local_copies_now(100,7,1666);$removePolicy=P::sanitize($GLOBALS['r469_meta'][100][Video_Meta::LOCAL_RETENTION_POLICY]??array());$removeQueue=end($siteTasks->enqueues);$a(S::APPLIED===$removeNow['status']&&1666===$removeNow['eligible_at']&&count($siteTasks->enqueues)===$beforeRemoveNow+1&&P::MODE_DELETE_ALL===($removePolicy['mode']??'')&&0===($removePolicy['grace_days']??-1)&&'backend_source'===($GLOBALS['r469_meta'][100][Video_Meta::MASTER_AUTHORITY]??'')&&true===($removeQueue['payload']['manual_cleanup']??false),'Explicit remove-local-copies action did not override Keep/default semantics and queue an immediate guarded delete-all task.');
unset($GLOBALS['r469_meta'][100][Video_Meta::LOCAL_RETENTION_EXECUTION]);$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='none';$GLOBALS['r469_meta'][100][Video_Meta::SOURCE_STATE]='present';$GLOBALS['r469_meta'][100][Video_Meta::MASTER_AUTHORITY]='wordpress_source';
$siteDelete=$siteSvc->configure_for_site_policy(100,P::MODE_DELETE_ALL,7,1700);$a(S::APPLIED===$siteDelete['status'],'Not-WordPress archive policy did not permit delayed original deletion after verified remote serving.');
$siteQueue=end($siteTasks->enqueues);$siteTask=array('id'=>$siteDelete['task_id'],'video_post_id'=>100,'task_type'=>S::TASK_TYPE,'lock_token'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','payload_json'=>json_encode($siteQueue['payload']));
$sitePolicy->allowed=false;$beforeSiteDelete=$GLOBALS['r469_source_deleted'];$siteRun=$siteSvc->advance_claimed($siteTask,$siteDelete['eligible_at']);$a(S::STATUS_COMPLETE===$siteRun['status']&&'blocked_keep'===$siteRun['service_status']&&$beforeSiteDelete===$GLOBALS['r469_source_deleted'],'Switching site policy back to WordPress archive did not stop an already-queued original deletion.');
$sitePolicy->allowed=true;$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='none';$GLOBALS['r469_meta'][100][Video_Meta::SOURCE_STATE]='present';$GLOBALS['r469_source_absent']=false;$GLOBALS['r469_local_delivery_available']=true;$GLOBALS['r469_local_delivery_matches']=true;$GLOBALS['r469_outputs'][20]=array('hls'=>array('path'=>'kept'));
$local=$siteSvc->configure_for_site_policy(100,P::MODE_DELETE_SOURCE_KEEP_DELIVERY,7,2000,1);$a(S::APPLIED===$local['status'],'Verified local-HLS source-prune policy did not schedule.');$localQueue=end($siteTasks->enqueues);$a(null===($localQueue['asset']??null)&&'local'===($localQueue['backend']??null),'Local-HLS cleanup task incorrectly requires a remote asset/backend.');
$beforeManaged=$GLOBALS['r469_storage_removed'];$beforeSource=$GLOBALS['r469_source_deleted'];$localTask=array('id'=>$local['task_id'],'video_post_id'=>100,'task_type'=>S::TASK_TYPE,'lock_token'=>'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb','payload_json'=>json_encode($localQueue['payload']));$localRun=$siteSvc->advance_claimed($localTask,$local['eligible_at']);$a(S::STATUS_COMPLETE===$localRun['status']&&$beforeManaged===$GLOBALS['r469_storage_removed']&&$GLOBALS['r469_source_deleted']===$beforeSource+1&&isset($GLOBALS['r469_outputs'][20]),'Source-prune/local-HLS mode must delete only the original and keep managed HLS outputs.');
$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='none';$GLOBALS['r469_meta'][100][Video_Meta::SOURCE_STATE]='present';$GLOBALS['r469_source_absent']=false;$GLOBALS['r469_source_matches']=true;$GLOBALS['r469_local_delivery_matches']=true;$GLOBALS['r469_outputs'][20]=array('hls'=>array('path'=>'kept'));
$local2=$siteSvc->configure_for_site_policy(100,P::MODE_DELETE_SOURCE_KEEP_DELIVERY,7,3000,1);$localQueue2=end($siteTasks->enqueues);$GLOBALS['r469_local_delivery_matches']=false;$beforeSource=$GLOBALS['r469_source_deleted'];$localTask2=array('id'=>$local2['task_id'],'video_post_id'=>100,'task_type'=>S::TASK_TYPE,'lock_token'=>'cccccccc-cccc-4ccc-8ccc-cccccccccccc','payload_json'=>json_encode($localQueue2['payload']));$localRun2=$siteSvc->advance_claimed($localTask2,$local2['eligible_at']);$a(S::STATUS_COMPLETE===$localRun2['status']&&'blocked_keep'===$localRun2['service_status']&&$beforeSource===$GLOBALS['r469_source_deleted'],'Changed local-HLS evidence must block original deletion.');$GLOBALS['r469_local_delivery_matches']=true;
$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='none';$GLOBALS['r469_meta'][100][Video_Meta::SOURCE_STATE]='present';$GLOBALS['r469_source_absent']=false;$GLOBALS['r469_outputs'][20]=array('hls'=>array('path'=>'kept'));$sitePolicy->allowed=true;
$local3=$siteSvc->configure_for_site_policy(100,P::MODE_DELETE_SOURCE_KEEP_DELIVERY,7,3500,1);$localQueue3=end($siteTasks->enqueues);$beforeSource=$GLOBALS['r469_source_deleted'];$GLOBALS['r469_local_delivery_match_hook']=static function()use($sitePolicy):void{$sitePolicy->allowed=false;};$localTask3=array('id'=>$local3['task_id'],'video_post_id'=>100,'task_type'=>S::TASK_TYPE,'lock_token'=>'dddddddd-dddd-4ddd-8ddd-dddddddddddd','payload_json'=>json_encode($localQueue3['payload']));$localRun3=$siteSvc->advance_claimed($localTask3,$local3['eligible_at']);$a(S::STATUS_COMPLETE===$localRun3['status']&&'blocked_keep'===$localRun3['service_status']&&$beforeSource===$GLOBALS['r469_source_deleted'],'Archive-of-record revocation after the worker starts must still block physical source deletion.');$sitePolicy->allowed=true;
$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='none';$GLOBALS['r469_meta'][100][Video_Meta::SOURCE_STATE]='present';$GLOBALS['r469_video_refs']=range(100,120);$a(S::attachment_local_processing_blocked(20),'Attachment reuse beyond the bounded reference scan must fail closed.');
$GLOBALS['r469_video_refs']=array(100,102);$r=$svc->configure(100,P::MODE_DELETE_MANAGED,1,'wordpress_source',7,850);$a(S::REFUSED===$r['status']&&0===count($tasks->enqueues),'Non-exclusive attachment ownership must refuse per-video cleanup.');
$GLOBALS['r469_video_refs']=array(100);$jobs->jobs[20]=array('status'=>'queued');$r=$svc->configure(100,P::MODE_DELETE_MANAGED,1,'wordpress_source',7,900);$a(S::REFUSED===$r['status']&&0===count($tasks->enqueues),'Active local processing job must block retention scheduling.');unset($jobs->jobs[20]);
$r=$svc->configure(100,P::MODE_DELETE_ALL,7,'wordpress_source',7,1000);$a(S::REFUSED===$r['status']&&0===count($tasks->enqueues),'Delete-all must refuse while WordPress remains master.');
$r=$svc->configure(100,P::MODE_DELETE_MANAGED,1,'wordpress_source',7,1000);$a(S::APPLIED===$r['status']&&1===count($tasks->enqueues)&&87400===$r['eligible_at'],'Managed cleanup must enqueue only after one-day grace.');$a(0===$GLOBALS['r469_storage_removed'],'Admin/configure path must not delete inline.');
$q=$tasks->enqueues[0];$task=array('id'=>1,'video_post_id'=>100,'task_type'=>S::TASK_TYPE,'lock_token'=>'11111111-1111-4111-8111-111111111111','payload_json'=>json_encode($q['payload']));$w=$svc->advance_claimed($task,2000);$a(S::STATUS_REQUEUED===$w['status']&&87400===$w['run_after'],'Cleanup before grace must reschedule without deletion.');
$serving->url='';$w=$svc->advance_claimed($task,87400);$a(S::STATUS_COMPLETE===$w['status']&&'blocked_keep'===$w['service_status']&&0===$GLOBALS['r469_storage_removed'],'Changed remote serving evidence must KEEP local copies.');$serving->url='https://pt.example/videos/embed/uuid';
$GLOBALS['r469_outputs'][20]=array('mp4'=>array('path'=>'x'));$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='none';$GLOBALS['r469_meta'][100][Video_Meta::SOURCE_STATE]='present';$r=$svc->configure(100,P::MODE_DELETE_MANAGED,1,'wordpress_source',7,100000);$q=end($tasks->enqueues);$task=array('id'=>$r['task_id'],'video_post_id'=>100,'task_type'=>S::TASK_TYPE,'lock_token'=>'22222222-2222-4222-8222-222222222223','payload_json'=>json_encode($q['payload']));$w=$svc->advance_claimed($task,$r['eligible_at']);$a(S::STATUS_COMPLETE===$w['status']&&1===$GLOBALS['r469_storage_removed']&&!isset($GLOBALS['r469_outputs'][20]),'Managed-only retention must remove generated outputs and preserve WordPress source.');
$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='none';$GLOBALS['r469_meta'][100][Video_Meta::SOURCE_STATE]='present';$GLOBALS['r469_outputs'][20]=array('mp4'=>array('path'=>'x'));$GLOBALS['r469_source_absent']=false;$beforeDeletes=$GLOBALS['r469_source_deleted'];$r=$svc->configure(100,P::MODE_DELETE_ALL,1,'backend_source',7,200000);$q=end($tasks->enqueues);$task=array('id'=>$r['task_id'],'video_post_id'=>100,'task_type'=>S::TASK_TYPE,'lock_token'=>'33333333-3333-4333-8333-333333333333','payload_json'=>json_encode($q['payload']));$w=$svc->advance_claimed($task,$r['eligible_at']);$a(S::STATUS_COMPLETE===$w['status']&&$GLOBALS['r469_source_deleted']===$beforeDeletes+1&&'removed'===($GLOBALS['r469_meta'][100][Video_Meta::SOURCE_STATE]??''),'Delete-all retention did not delete exact source and persist removed state.');$a(is_object(ArgentVideo\get_post(20)),'Attachment object must survive source cleanup.');
if($f>0)exit(1);echo "RC13.2 local retention service tests passed.\n";
}
