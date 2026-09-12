<?php
/** RC13.2 local retention policy/model tests. */
declare(strict_types=1);
namespace ArgentVideo {
    function wp_json_encode(mixed $v,int $flags=0):string|false{return json_encode($v,$flags);}
    function sanitize_text_field(mixed $v):string{return trim(strip_tags((string)$v));}
    final class Backend_Registry { public const LOCAL_ID='local'; }
    final class Backend_Identity { public static function sanitize(mixed $v):string{return is_string($v)&&''!==$v?$v:'';} }
    final class Video_Serving_Authority { public static function sanitize(mixed $v):array{return is_array($v)?$v:array();} }
    final class WordPress_Source_File { public static function sanitize_identity(mixed $v):array{return is_array($v)?$v:array();} }
    final class Local_Delivery_Evidence {
        public static function sanitize(mixed $v):array{return is_array($v)&&1===($v['version']??0)?$v:array();}
        public static function sha256(array $v):string{return ''===$v?'' : hash('sha256',json_encode($v));}
    }
}
namespace {
require_once dirname(__DIR__).'/includes/Local_Retention_Policy.php';
require_once dirname(__DIR__).'/includes/Local_Retention_Execution.php';
use ArgentVideo\Local_Retention_Policy as P;use ArgentVideo\Local_Retention_Execution as E;
$f=0;$a=function(bool $v,string $m)use(&$f){if(!$v){fwrite(STDERR,"FAIL: $m\n");$f++;}};
$keep=P::create(P::MODE_KEEP,99,7,1000);$a(P::MODE_KEEP===($keep['mode']??null)&&0===($keep['grace_days']??-1),'KEEP policy must force zero grace.');
$managed=P::create(P::MODE_DELETE_MANAGED,7,7,1000);$a(7===($managed['grace_days']??0)&&P::destructive($managed)&&P::deletes_managed($managed)&&!P::deletes_source($managed),'Managed cleanup policy semantics mismatch.');
$held=P::create(P::MODE_DELETE_MANAGED,0,7,1000);$a(array()!==$held&&!P::automatic_cleanup_enabled($held),'Zero grace must represent Never rather than immediate destructive cleanup.');
$local=P::create(P::MODE_DELETE_SOURCE_KEEP_DELIVERY,14,7,1000);$a(P::deletes_source($local)&&!P::deletes_managed($local)&&P::keeps_local_delivery($local),'Local-delivery/source-prune policy semantics mismatch.');
$all=P::create(P::MODE_DELETE_ALL,30,7,1000);$a(P::deletes_source($all)&&P::deletes_managed($all),'Delete-all policy must identify both source and managed deletion.');
$a(array()!==P::create(P::MODE_DELETE_ALL,0,7,1000),'Zero-day destructive policy must remain representable as Never.');
$a(array()===P::create(P::MODE_DELETE_ALL,366,7,1000),'Grace must be bounded at 365 days.');
$a(64===strlen(P::sha256($all)),'Policy hash must be bounded SHA-256.');
$authority=array('generation'=>3,'plan_sha256'=>str_repeat('a',64),'manifest_sha256'=>str_repeat('b',64),'remote_asset_id'=>4,'remote_uuid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa','backend_id'=>'pt1');
$source=array('relative_path'=>'2026/09/video.mp4','bytes'=>10,'device'=>1,'inode'=>2,'mtime'=>3,'ctime'=>4);
$exec=E::create(10,20,$all,$authority,$source,1,2000,1000);$a(array()!==$exec&&E::STATUS_QUEUED===$exec['status']&&E::PROOF_REMOTE===E::proof_kind($exec),'Remote execution journal create failed.');
$a(64===strlen(E::immutable_sha256($exec)),'Execution immutable hash missing.');
$with=E::with_task($exec,44);$a(44===($with['task_id']??0)&&E::immutable_sha256($with)===E::immutable_sha256($exec),'Task ID must not alter immutable execution identity.');
$done=E::transition($with,E::STATUS_COMPLETE,2100);$a(2100===($done['completed_at']??0),'Completion audit timestamp missing.');
$delivery=array('version'=>1,'attachment_id'=>20,'hls_url'=>'https://example.test/hls/master.m3u8','file_count'=>5,'bytes'=>100,'tree_sha256'=>str_repeat('c',64));
$local_exec=E::create_local(10,20,$local,$delivery,$source,1,2500,2000);$a(array()!==$local_exec&&E::PROOF_LOCAL_HLS===E::proof_kind($local_exec)&&'local'===E::task_backend_id($local_exec)&&null===E::task_remote_asset_id($local_exec),'Local-HLS execution journal create failed.');
if($f>0)exit(1);echo "RC13.2 local retention policy/execution tests passed.\n";
}
