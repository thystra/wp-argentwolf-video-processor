<?php
/** Focused daily PeerTube credential/catalog maintenance tests. */
declare(strict_types=1);
namespace ArgentVideo {
    final class Backend_Identity { public static function sanitize(mixed $v):string{return is_string($v)&&1===preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D',$v)?$v:'';} }
    final class Backend_Registry {
        public const LOCAL_ID='local'; public const PEERTUBE_TYPE='peertube';
        public array $rows=array(); public function all():array{return $this->rows;}
    }
    final class PeerTube_Token_Lifecycle_Service {
        public const STATUS_COMPLETE='complete'; public const STATUS_WAIT='wait'; public const STATUS_REAUTHENTICATION_REQUIRED='reauthentication_required'; public const STATUS_INDETERMINATE='indeterminate'; public const STATUS_CONFLICT='conflict';
    }
    final class PeerTube_Publication_Authority_Repair {
        public array $results=array(); public array $calls=array();
        public function repair(string $id,int $now):array{$this->calls[]=array($id,$now);return $this->results[$id]??array('status'=>'wait');}
    }
    final class PeerTube_Publication_Catalog_Service {
        public const COMPLETE='complete'; public array $results=array(); public array $calls=array();
        public function refresh(string $id,int $now):array{$this->calls[]=array($id,$now);return $this->results[$id]??array('status'=>'remote_failed');}
    }
    final class Backend_Maintenance_Status_Store {
        public const HEALTHY='healthy'; public const WARNING='warning'; public const ERROR='error'; public array $records=array();
        public function record(string $id,string $status,string $code,string $message,int $now):bool{$this->records[$id]=compact('status','code','message','now');return true;}
    }
}
namespace {
    require_once dirname(__DIR__).'/includes/PeerTube_Daily_Maintenance_Service.php';
    use ArgentVideo\Backend_Registry as Registry; use ArgentVideo\PeerTube_Publication_Authority_Repair as Repair; use ArgentVideo\PeerTube_Publication_Catalog_Service as Catalog; use ArgentVideo\Backend_Maintenance_Status_Store as Status; use ArgentVideo\PeerTube_Daily_Maintenance_Service as Service;
    $a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
    $r=new Registry(); $r->rows=array(
        'local'=>array('type'=>'local','state'=>'active'),
        'pt1'=>array('type'=>'peertube','state'=>'active'),
        'pt2'=>array('type'=>'peertube','state'=>'active'),
        'disabled'=>array('type'=>'peertube','state'=>'disabled'),
    );
    $repair=new Repair(); $repair->results=array('pt1'=>array('status'=>'complete'),'pt2'=>array('status'=>'reauthentication_required'));
    $catalog=new Catalog(); $catalog->results=array('pt1'=>array('status'=>'complete'));
    $status=new Status(); $svc=new Service($r,$repair,$catalog,$status);
    $a(2===$svc->run(2000),'Daily maintenance did not visit exactly active PeerTube backends.');
    $a(array(array('pt1',2000),array('pt2',2000))===$repair->calls,'Authority repair calls drifted.');
    $a(array(array('pt1',2000))===$catalog->calls,'Catalog must refresh daily only after usable authority.');
    $a(Status::HEALTHY===($status->records['pt1']['status']??''),'Healthy backend was not recorded current.');
    $a(Status::ERROR===($status->records['pt2']['status']??''),'Reauthentication requirement must be an error.');
    fwrite(STDOUT,"PeerTube daily maintenance tests passed.\n");
}
