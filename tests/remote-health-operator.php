<?php
/** Dependency-free explicit remote health recheck / immediate restore tests. */
declare(strict_types=1);

namespace ArgentVideo {
    $GLOBALS['awvp_health_operator_meta']=array();
    final class Backend_Registry { public const LOCAL_ID='local'; }
    final class Backend_Identity { public static function sanitize(mixed $v):string{return is_string($v)&&1===preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D',$v)?$v:'';} }
    final class Video_Meta {
        public const REMOTE_HEALTH_OPERATOR_CHECK='_health_operator_check';
        public static function sanitize_positive_id(mixed $v):int{return is_int($v)&&$v>0?$v:(is_string($v)&&ctype_digit($v)&&(int)$v>0?(int)$v:0);}
    }
    final class Serving_Viability {
        public const HEALTHY='healthy'; public const PROCESSING='processing'; public const MISSING='missing'; public const PRIVATE_OR_RESTRICTED='private_or_restricted'; public const EMBED_DISALLOWED='embed_disallowed'; public const TEMPORARILY_UNAVAILABLE='temporarily_unavailable'; public const PROBE_INDETERMINATE='probe_indeterminate';
    }
    final class Remote_Asset_Repository {
        public function __construct(public array $rows){}
        public function find(int $id):?array{return $this->rows[$id]??null;}
    }
    final class Remote_Publication_Health_Repository {
        public const APPLIED='applied'; public const PRESENT='present'; public const CONFLICT='conflict'; public const INDETERMINATE='indeterminate';
        public function __construct(public array $rows){}
        public function find(int $id):?array{return $this->rows[$id]??null;}
        public function operator_restore_eligible(int $asset,int $video,string $backend,string $expected,int $now):string{
            $r=$this->rows[$asset]??null;if(!is_array($r)||$video!==(int)$r['video_post_id']||$backend!==$r['backend_id']||'healthy'!==$r['status']||0!==(int)$r['eligible']||$expected!==$r['last_checked_at'])return self::CONFLICT;
            $r['eligible']=1;$r['success_streak']=max(2,(int)$r['success_streak']);$r['updated_at']=gmdate('Y-m-d H:i:s',$now);$this->rows[$asset]=$r;return self::APPLIED;
        }
    }
    final class Remote_Publication_Health_Service {
        public function __construct(private readonly Remote_Publication_Health_Repository $repo, public string $next='healthy'){}
        public function probe_and_record(array $asset,int $now,bool $initial=false,array $ctx=array()):array{
            unset($initial,$ctx);$id=(int)$asset['id'];$before=$this->repo->rows[$id];$status=$this->next;$healthy='healthy'===$status;
            $this->repo->rows[$id]=array_replace($before,array('status'=>$status,'eligible'=>$healthy?0:0,'success_streak'=>$healthy?1:0,'failure_streak'=>$healthy?0:2,'last_checked_at'=>gmdate('Y-m-d H:i:s',$now),'last_healthy_at'=>$healthy?gmdate('Y-m-d H:i:s',$now):($before['last_healthy_at']??''),'http_status'=>$healthy?200:403,'reason_code'=>$healthy?'':'peertube.health.provider_restricted'));
            return array('recorded'=>true,'viability_status'=>$status,'reason_code'=>$healthy?'':'peertube.health.provider_restricted','message'=>$healthy?'Healthy':'Still restricted','http_status'=>$healthy?200:403);
        }
    }
    final class PeerTube_Event_Repository { public array $events=array(); public function record(int $video,int $step,string $code,string $severity,string $message,int $now,int $task=0,string $op='',int $asset=0,string $backend='',int $http=0,string $auto='',string $operator='',array $ctx=array()):bool{$this->events[]=compact('video','step','code','severity','message','now','asset','backend','http','operator','ctx');return true;} }
    final class Remote_Health_Notification_Service { public array $calls=array(); public function publication_observed(array $asset,array $health,int $now,bool $backend=false):void{$this->calls[]=array($asset,$health,$now,$backend);} }
    function get_post_meta(int $id,string $key,bool $single=true):mixed{unset($single);return $GLOBALS['awvp_health_operator_meta'][$id][$key]??'';}
    function update_post_meta(int $id,string $key,mixed $value):int|bool{$GLOBALS['awvp_health_operator_meta'][$id][$key]=$value;return 1;}
}
namespace {
    require_once dirname(__DIR__).'/includes/Remote_Health_Operator_Check.php';
    require_once dirname(__DIR__).'/includes/Remote_Health_Operator_Service.php';
    use ArgentVideo\Remote_Asset_Repository;use ArgentVideo\Remote_Publication_Health_Repository;use ArgentVideo\Remote_Publication_Health_Service;use ArgentVideo\PeerTube_Event_Repository;use ArgentVideo\Remote_Health_Notification_Service;use ArgentVideo\Remote_Health_Operator_Service;use ArgentVideo\Video_Meta;
    $a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
    $now=2000000;$asset=array('id'=>7,'video_post_id'=>99,'backend_id'=>'pt1','state'=>'ready','embed_url'=>'https://pt.example/videos/embed/x');
    $health=new Remote_Publication_Health_Repository(array(7=>array('remote_asset_id'=>7,'video_post_id'=>99,'backend_id'=>'pt1','status'=>'private_or_restricted','eligible'=>0,'success_streak'=>0,'failure_streak'=>1,'last_checked_at'=>gmdate('Y-m-d H:i:s',$now-30),'last_healthy_at'=>gmdate('Y-m-d H:i:s',$now-60))));
    $probe=new Remote_Publication_Health_Service($health);$events=new PeerTube_Event_Repository();$notifications=new Remote_Health_Notification_Service();$svc=new Remote_Health_Operator_Service(new Remote_Asset_Repository(array(7=>$asset)),$health,$probe,$events,$notifications);
    $r=$svc->check_now(99,7,5,$now);$a(Remote_Health_Operator_Service::APPLIED===$r['status']&&'healthy'===$r['viability_status']&&true===$r['restore_available'],'Fresh successful check did not expose immediate restore.');
    $stored=$GLOBALS['awvp_health_operator_meta'][99][Video_Meta::REMOTE_HEALTH_OPERATOR_CHECK]??array();$a(5===($stored['checked_by']??0)&&$now===($stored['checked_at']??0),'Operator check audit identity was not persisted.');
    $a($svc->restore_available(99,7,$now+1),'Fresh successful check was not accepted for restore.');
    $r=$svc->restore_now(99,7,6,$now+2);$a(Remote_Health_Operator_Service::APPLIED===$r['status']&&1===(int)$health->rows[7]['eligible']&&2===(int)$health->rows[7]['success_streak'],'Immediate restore did not set serving eligibility through the guarded repository boundary.');
    $stored=$GLOBALS['awvp_health_operator_meta'][99][Video_Meta::REMOTE_HEALTH_OPERATOR_CHECK]??array();$a(6===($stored['restored_by']??0)&&$now+2===($stored['restored_at']??0),'Restore actor/time audit was not persisted.');
    $a(1===count($notifications->calls),'Serving recovery notification was not emitted after eligibility restore.');
    $r=$svc->restore_now(99,7,6,$now+3);$a(Remote_Health_Operator_Service::PRESENT===$r['status'],'Restore replay was not idempotent.');
    $health->rows[7]=array('remote_asset_id'=>7,'video_post_id'=>99,'backend_id'=>'pt1','status'=>'private_or_restricted','eligible'=>0,'success_streak'=>0,'failure_streak'=>1,'last_checked_at'=>gmdate('Y-m-d H:i:s',$now+10),'last_healthy_at'=>gmdate('Y-m-d H:i:s',$now));$probe->next='private_or_restricted';
    $r=$svc->check_now(99,7,5,$now+20);$a(Remote_Health_Operator_Service::APPLIED===$r['status']&&!$r['restore_available']&&!$svc->restore_available(99,7,$now+21),'Failed fresh check incorrectly enabled serving restore.');
    fwrite(STDOUT,"Remote health operator recovery tests passed.\n");
}
