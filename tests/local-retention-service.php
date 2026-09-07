<?php
/** Dependency-free R46.9 scheduling/cleanup orchestration tests. */
declare(strict_types=1);
namespace ArgentVideo {
$GLOBALS['r469_meta']=array();$GLOBALS['r469_outputs']=array(20=>array('mp4'=>array('path'=>'x')));$GLOBALS['r469_storage_removed']=0;$GLOBALS['r469_source_deleted']=0;$GLOBALS['r469_source_matches']=true;$GLOBALS['r469_source_absent']=false;$GLOBALS['r469_video_status']=array(100=>'publish',101=>'publish',102=>'publish');$GLOBALS['r469_video_refs']=array(100);
function wp_json_encode(mixed $v,int $flags=0):string|false{return json_encode($v,$flags);}
function sanitize_text_field(mixed $v):string{return trim(strip_tags((string)$v));}
function get_post(int $id):mixed{if(isset($GLOBALS['r469_video_status'][$id]))return(object)array('ID'=>$id,'post_type'=>Video_Post_Type::POST_TYPE,'post_status'=>$GLOBALS['r469_video_status'][$id]);if(in_array($id,array(20,21),true))return(object)array('ID'=>$id,'post_type'=>'attachment','post_status'=>'inherit');return null;}
function get_post_meta(int $id,string $k,bool $single=true):mixed{unset($single);if(20===$id&&'_argent_video_outputs'===$k)return $GLOBALS['r469_outputs'][20]??'';return $GLOBALS['r469_meta'][$id][$k]??'';}
function update_post_meta(int $id,string $k,mixed $v):int|bool{$GLOBALS['r469_meta'][$id][$k]=$v;return 1;}
function metadata_exists(string $type,int $id,string $k):bool{unset($type);if(20===$id&&'_argent_video_outputs'===$k)return isset($GLOBALS['r469_outputs'][20]);return array_key_exists($k,$GLOBALS['r469_meta'][$id]??array());}
function delete_post_meta(int $id,string $k):bool{if(20===$id&&'_argent_video_outputs'===$k){unset($GLOBALS['r469_outputs'][20]);return true;}unset($GLOBALS['r469_meta'][$id][$k]);return true;}
function get_posts(array $args=array()):array{unset($args);return $GLOBALS['r469_video_refs'];}
function is_dir(string $path):bool{return str_starts_with($path,'/tmp/fake-');}
final class Video_Post_Type{public const POST_TYPE='argent_video_asset';}
final class Video_Meta{
 public const MASTER_AUTHORITY='master';public const SOURCE_STATE='source';public const CLEANUP_STATE='cleanup';public const LOCAL_RETENTION_POLICY='policy';public const LOCAL_RETENTION_EXECUTION='execution';public const ATTACHMENT_ID='attachment';public const SERVING_AUTHORITY='authority';
 public static function sanitize_master_authority(mixed $v):string{return in_array($v,array('wordpress_source','backend_source','external_archive'),true)?$v:'unknown';}
 public static function sanitize_source_state(mixed $v):string{return in_array($v,array('present','verified_remote','removed','error'),true)?$v:'error';}
 public static function sanitize_cleanup_state(mixed $v):string{return in_array($v,array('none','pending','eligible','running','complete','blocked','failed'),true)?$v:'none';}
 public static function sanitize_positive_id(mixed $v):int{return is_numeric($v)&&((int)$v)>0?(int)$v:0;}
}
final class Local_Retention_Policy{
 public const MODE_KEEP='keep',MODE_DELETE_MANAGED='delete_managed',MODE_DELETE_ALL='delete_all';
 public static function create(string $m,int $g,int $u,int $n):array{if(!in_array($m,array(self::MODE_KEEP,self::MODE_DELETE_MANAGED,self::MODE_DELETE_ALL),true)||$u<1||$n<1)return array();if(self::MODE_KEEP===$m)$g=0;elseif($g<1||$g>365)return array();return array('version'=>1,'mode'=>$m,'grace_days'=>$g,'confirmed_by'=>$u,'confirmed_at'=>$n);}
 public static function sanitize(mixed $v):array{return is_array($v)&&1===($v['version']??0)?$v:array();}
 public static function sha256(array $p):string{return hash('sha256',json_encode($p));}
 public static function destructive(array $p):bool{return self::MODE_KEEP!==($p['mode']??'keep');}
 public static function deletes_source(array $p):bool{return self::MODE_DELETE_ALL===($p['mode']??'');}
}
final class Video_Serving_Authority{
 public static function sanitize(mixed $v):array{return is_array($v)&&isset($v['generation'],$v['plan_sha256'],$v['manifest_sha256'],$v['remote_asset_id'],$v['remote_uuid'],$v['verified_at'],$v['backend_id'])?$v:array();}
}
final class WordPress_Source_File{
 public static function capture(int $id):array{return 20===$id?array('relative_path'=>'2026/09/video.mp4','bytes'=>10,'device'=>1,'inode'=>2,'mtime'=>3,'ctime'=>4):array();}
 public static function sanitize_identity(mixed $v):array{return is_array($v)?$v:array();}
 public static function matches(int $id,array $v):bool{unset($id,$v);return (bool)$GLOBALS['r469_source_matches'];}
 public static function absent(int $id,array $v):bool{unset($id,$v);return (bool)$GLOBALS['r469_source_absent'];}
 public static function delete(int $id,array $v):bool{unset($id,$v);$GLOBALS['r469_source_deleted']++;$GLOBALS['r469_source_absent']=true;return true;}
}
final class Local_Retention_Execution{
 public const STATUS_QUEUED='queued',STATUS_RUNNING='running',STATUS_COMPLETE='complete',STATUS_BLOCKED='blocked',STATUS_FAILED='failed';
 public static function create(int $vid,int $att,array $p,array $a,array $s,int $attempt,int $eligible,int $now):array{return array('version'=>1,'attempt'=>$attempt,'video_id'=>$vid,'attachment_id'=>$att,'policy_sha256'=>Local_Retention_Policy::sha256($p),'mode'=>$p['mode'],'authority_sha256'=>self::authority_sha256($a),'serving_generation'=>$a['generation'],'plan_sha256'=>$a['plan_sha256'],'manifest_sha256'=>$a['manifest_sha256'],'remote_asset_id'=>$a['remote_asset_id'],'remote_uuid'=>$a['remote_uuid'],'source'=>$s,'eligible_at'=>$eligible,'status'=>self::STATUS_QUEUED,'task_id'=>0,'prepared_at'=>$now,'completed_at'=>0,'last_error'=>'');}
 public static function sanitize(mixed $v):array{return is_array($v)&&1===($v['version']??0)?$v:array();}
 public static function immutable_sha256(array $r):string{$x=$r;unset($x['status'],$x['task_id'],$x['completed_at'],$x['last_error']);return hash('sha256',json_encode($x));}
 public static function with_task(array $r,int $id):array{$r['task_id']=$id;return$r;}
 public static function transition(array $r,string $s,int $n,string $e=''):array{$r['status']=$s;$r['last_error']=$e;$r['completed_at']=in_array($s,array(self::STATUS_COMPLETE,self::STATUS_BLOCKED,self::STATUS_FAILED),true)?$n:0;return$r;}
 public static function authority_sha256(array $a):string{return hash('sha256',json_encode($a));}
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
use ArgentVideo\{Local_Retention_Service as S,Task_Repository,Job_Repository,FakeServing,Video_Meta,Local_Retention_Policy as P};
$f=0;$a=function(bool $v,string $m)use(&$f){if(!$v){fwrite(STDERR,"FAIL: $m\n");$f++;}};$tasks=new Task_Repository();$jobs=new Job_Repository();$serving=new FakeServing();$svc=new S($tasks,$serving,$jobs);
$auth=array('generation'=>2,'plan_sha256'=>str_repeat('a',64),'manifest_sha256'=>str_repeat('b',64),'remote_asset_id'=>9,'remote_uuid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','verified_at'=>1000,'backend_id'=>'pt');
$GLOBALS['r469_meta'][100]=array(Video_Meta::ATTACHMENT_ID=>20,Video_Meta::SOURCE_STATE=>'present',Video_Meta::MASTER_AUTHORITY=>'wordpress_source',Video_Meta::SERVING_AUTHORITY=>$auth);
$GLOBALS['r469_meta'][101]=array(Video_Meta::ATTACHMENT_ID=>21,Video_Meta::SOURCE_STATE=>'present',Video_Meta::MASTER_AUTHORITY=>'wordpress_source',Video_Meta::SERVING_AUTHORITY=>$auth);
$GLOBALS['r469_meta'][102]=array(Video_Meta::ATTACHMENT_ID=>20,Video_Meta::SOURCE_STATE=>'present',Video_Meta::MASTER_AUTHORITY=>'wordpress_source',Video_Meta::SERVING_AUTHORITY=>$auth);
$GLOBALS['r469_video_refs']=range(100,120);$a(S::attachment_local_processing_blocked(20),'Attachment reuse beyond the bounded reference scan must fail closed.');
$GLOBALS['r469_video_refs']=array(100,102);$r=$svc->configure(100,P::MODE_DELETE_MANAGED,1,'wordpress_source',7,850);$a(S::REFUSED===$r['status']&&0===count($tasks->enqueues),'Non-exclusive attachment ownership must refuse per-video cleanup.');
$GLOBALS['r469_video_refs']=array(100);
$jobs->jobs[20]=array('status'=>'queued');$r=$svc->configure(100,P::MODE_DELETE_MANAGED,1,'wordpress_source',7,900);$a(S::REFUSED===$r['status']&&0===count($tasks->enqueues),'Active local processing job must block retention scheduling.');unset($jobs->jobs[20]);
$r=$svc->configure(100,P::MODE_DELETE_ALL,7,'wordpress_source',7,1000);$a(S::REFUSED===$r['status']&&0===count($tasks->enqueues),'Delete-all must refuse while WordPress remains master.');
$r=$svc->configure(100,P::MODE_DELETE_MANAGED,1,'wordpress_source',7,1000);$a(S::APPLIED===$r['status']&&1===count($tasks->enqueues)&&87400===$r['eligible_at'],'Managed cleanup must enqueue only after one-day grace.');$a(0===$GLOBALS['r469_storage_removed'],'Admin/configure path must not delete inline.');
$q=$tasks->enqueues[0];$task=array('id'=>1,'video_post_id'=>100,'task_type'=>S::TASK_TYPE,'lock_token'=>'11111111-1111-4111-8111-111111111111','payload_json'=>json_encode($q['payload']));
$w=$svc->advance_claimed($task,2000);$a(S::STATUS_REQUEUED===$w['status']&&87400===$w['run_after'],'Cleanup before grace must reschedule without deletion.');
$serving->url='';$w=$svc->advance_claimed($task,87400);$a(S::STATUS_COMPLETE===$w['status']&&'blocked_keep'===$w['service_status']&&0===$GLOBALS['r469_storage_removed'],'Changed serving authority must KEEP local copies.');
$serving->url='https://pt.example/videos/embed/uuid';$GLOBALS['r469_video_refs']=array(101);$r=$svc->configure(101,P::MODE_DELETE_MANAGED,1,'wordpress_source',7,88000);$q=end($tasks->enqueues);$GLOBALS['r469_meta'][102][Video_Meta::ATTACHMENT_ID]=21;$GLOBALS['r469_video_refs']=array(101,102);$task=array('id'=>$r['task_id'],'video_post_id'=>101,'task_type'=>S::TASK_TYPE,'lock_token'=>'22222222-2222-4222-8222-222222222221','payload_json'=>json_encode($q['payload']));$w=$svc->advance_claimed($task,$r['eligible_at']);$a(S::STATUS_COMPLETE===$w['status']&&'blocked_keep'===$w['service_status']&&0===$GLOBALS['r469_storage_removed'],'Attachment ownership becoming ambiguous after scheduling must block cleanup and KEEP local bytes.');$GLOBALS['r469_meta'][102][Video_Meta::ATTACHMENT_ID]=20;$GLOBALS['r469_video_refs']=array(101);
$r=$svc->configure(101,P::MODE_DELETE_MANAGED,1,'wordpress_source',7,90000);$q=end($tasks->enqueues);$GLOBALS['r469_video_status'][101]='trash';$task=array('id'=>$r['task_id'],'video_post_id'=>101,'task_type'=>S::TASK_TYPE,'lock_token'=>'22222222-2222-4222-8222-222222222222','payload_json'=>json_encode($q['payload']));$w=$svc->advance_claimed($task,$r['eligible_at']);$a(S::STATUS_COMPLETE===$w['status']&&'blocked_keep'===$w['service_status']&&0===$GLOBALS['r469_storage_removed'],'Trashed AWVP Video must block cleanup and KEEP local bytes.');$GLOBALS['r469_video_status'][101]='publish';
$r=$svc->configure(101,P::MODE_DELETE_MANAGED,1,'wordpress_source',7,95000);$q=end($tasks->enqueues);$GLOBALS['r469_meta'][101][Video_Meta::ATTACHMENT_ID]=20;$task=array('id'=>$r['task_id'],'video_post_id'=>101,'task_type'=>S::TASK_TYPE,'lock_token'=>'22222222-2222-4222-8222-222222222224','payload_json'=>json_encode($q['payload']));$w=$svc->advance_claimed($task,$r['eligible_at']);$a(S::STATUS_COMPLETE===$w['status']&&'blocked_keep'===$w['service_status']&&0===$GLOBALS['r469_storage_removed'],'Attachment reassignment after scheduling must block cleanup and KEEP local bytes.');$GLOBALS['r469_meta'][101][Video_Meta::ATTACHMENT_ID]=21;
$GLOBALS['r469_video_refs']=array(100);
$GLOBALS['r469_outputs'][20]=array('mp4'=>array('path'=>'x'));$r=$svc->configure(100,P::MODE_DELETE_MANAGED,1,'wordpress_source',7,100000);$q=end($tasks->enqueues);$task=array('id'=>$r['task_id'],'video_post_id'=>100,'task_type'=>S::TASK_TYPE,'lock_token'=>'22222222-2222-4222-8222-222222222223','payload_json'=>json_encode($q['payload']));$w=$svc->advance_claimed($task,$r['eligible_at']);$a(S::STATUS_COMPLETE===$w['status']&&1===$GLOBALS['r469_storage_removed']&&0===$GLOBALS['r469_source_deleted'],'Managed-only retention must not delete WordPress source.');$a(!isset($GLOBALS['r469_outputs'][20]),'Managed output metadata must be cleared after managed cleanup.');
# Fresh video for full deletion.
$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='none';$GLOBALS['r469_meta'][100][Video_Meta::SOURCE_STATE]='present';$GLOBALS['r469_outputs'][20]=array('mp4'=>array('path'=>'x'));$r=$svc->configure(100,P::MODE_DELETE_ALL,1,'backend_source',7,200000);$q=end($tasks->enqueues);$task=array('id'=>$r['task_id'],'video_post_id'=>100,'task_type'=>S::TASK_TYPE,'lock_token'=>'33333333-3333-4333-8333-333333333333','payload_json'=>json_encode($q['payload']));$w=$svc->advance_claimed($task,$r['eligible_at']);$a(S::STATUS_COMPLETE===$w['status']&&1===$GLOBALS['r469_source_deleted'],'Delete-all retention did not delete exact source.');$a('removed'===($GLOBALS['r469_meta'][100][Video_Meta::SOURCE_STATE]??''),'Physical cleanup must persist removed source state.');$a(is_object(ArgentVideo\get_post(20)),'Attachment object must survive source cleanup.');
# Simulate crash after physical delete but before audit completion: running journal + exact absence converges without a second delete.
$GLOBALS['r469_meta'][100][Video_Meta::CLEANUP_STATE]='none';$GLOBALS['r469_meta'][100][Video_Meta::SOURCE_STATE]='present';$GLOBALS['r469_source_absent']=false;$r=$svc->configure(100,P::MODE_DELETE_ALL,1,'backend_source',7,400000);$q=end($tasks->enqueues);$exec=$GLOBALS['r469_meta'][100][Video_Meta::LOCAL_RETENTION_EXECUTION];$exec['status']='running';$GLOBALS['r469_meta'][100][Video_Meta::LOCAL_RETENTION_EXECUTION]=$exec;$GLOBALS['r469_source_absent']=true;$beforeDeletes=$GLOBALS['r469_source_deleted'];$task=array('id'=>$r['task_id'],'video_post_id'=>100,'task_type'=>S::TASK_TYPE,'lock_token'=>'44444444-4444-4444-8444-444444444444','payload_json'=>json_encode($q['payload']));$w=$svc->advance_claimed($task,$r['eligible_at']);$a(S::STATUS_COMPLETE===$w['status']&&$beforeDeletes===$GLOBALS['r469_source_deleted'],'Running-journal crash recovery must confirm exact absence without replaying deletion.');
if($f>0)exit(1);echo "R46.9 local retention service tests passed.\n";
}
