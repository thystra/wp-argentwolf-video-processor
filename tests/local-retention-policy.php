<?php
/** R46.9 local retention policy/model tests. */
declare(strict_types=1);
namespace ArgentVideo {
    function wp_json_encode(mixed $v,int $flags=0):string|false{return json_encode($v,$flags);}
    function sanitize_text_field(mixed $v):string{return trim(strip_tags((string)$v));}
    final class Video_Serving_Authority { public static function sanitize(mixed $v):array{return is_array($v)?$v:array();} }
    final class WordPress_Source_File { public static function sanitize_identity(mixed $v):array{return is_array($v)?$v:array();} }
}
namespace {
require_once dirname(__DIR__).'/includes/Local_Retention_Policy.php';
require_once dirname(__DIR__).'/includes/Local_Retention_Execution.php';
use ArgentVideo\Local_Retention_Policy as P;use ArgentVideo\Local_Retention_Execution as E;
$f=0;$a=function(bool $v,string $m)use(&$f){if(!$v){fwrite(STDERR,"FAIL: $m\n");$f++;}};
$keep=P::create(P::MODE_KEEP,99,7,1000);$a(P::MODE_KEEP===($keep['mode']??null)&&0===($keep['grace_days']??-1),'KEEP policy must force zero grace.');
$managed=P::create(P::MODE_DELETE_MANAGED,7,7,1000);$a(7===($managed['grace_days']??0)&&P::destructive($managed)&&!P::deletes_source($managed),'Managed cleanup policy semantics mismatch.');
$all=P::create(P::MODE_DELETE_ALL,30,7,1000);$a(P::deletes_source($all),'Delete-all policy must identify source deletion.');
$a(array()===P::create(P::MODE_DELETE_ALL,0,7,1000),'Destructive policy must require at least one grace day.');
$a(array()===P::create(P::MODE_DELETE_ALL,366,7,1000),'Grace must be bounded at 365 days.');
$a(64===strlen(P::sha256($all)),'Policy hash must be bounded SHA-256.');
$authority=array('generation'=>3,'plan_sha256'=>str_repeat('a',64),'manifest_sha256'=>str_repeat('b',64),'remote_asset_id'=>4,'remote_uuid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');
$source=array('relative_path'=>'2026/09/video.mp4','bytes'=>10,'device'=>1,'inode'=>2,'mtime'=>3,'ctime'=>4);
$exec=E::create(10,20,$all,$authority,$source,1,2000,1000);$a(array()!==$exec&&E::STATUS_QUEUED===$exec['status'],'Execution journal create failed.');
$a(64===strlen(E::immutable_sha256($exec)),'Execution immutable hash missing.');
$with=E::with_task($exec,44);$a(44===($with['task_id']??0)&&E::immutable_sha256($with)===E::immutable_sha256($exec),'Task ID must not alter immutable execution identity.');
$done=E::transition($with,E::STATUS_COMPLETE,2100);$a(2100===($done['completed_at']??0),'Completion audit timestamp missing.');
if($f>0)exit(1);echo "R46.9 local retention policy/execution tests passed.\n";
}
