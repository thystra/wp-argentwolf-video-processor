<?php
/** Focused backend-maintenance status-store tests. */
declare(strict_types=1);
namespace ArgentVideo {
    final class Backend_Registry { public const LOCAL_ID='local'; }
    final class Backend_Identity { public static function sanitize(mixed $v):string{return is_string($v)&&1===preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D',$v)?$v:'';} }
}
namespace {
    $GLOBALS['awvp_backend_maintenance_options']=array();
    function get_option(string $k,mixed $d=false):mixed{return $GLOBALS['awvp_backend_maintenance_options'][$k]??$d;}
    function update_option(string $k,mixed $v,?bool $autoload=null):bool{$GLOBALS['awvp_backend_maintenance_options'][$k]=$v;return true;}
    require_once dirname(__DIR__).'/includes/Backend_Maintenance_Status_Store.php';
    use ArgentVideo\Backend_Maintenance_Status_Store as Store;
    $a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
    $s=new Store();
    $a(array()===$s->all(),'Absent store must be empty.');
    $a($s->record('pt1',Store::WARNING,'peertube.maintenance.wait','Temporary failure.',1000),'Could not record warning.');
    $r=$s->get('pt1');
    $a(is_array($r)&&Store::WARNING===$r['status']&&1000===$r['checked_at'],'Warning did not round-trip.');
    $a($s->record('pt1',Store::HEALTHY,'peertube.maintenance.current','Current.',1100),'Could not record recovery.');
    $r=$s->get('pt1');
    $a(is_array($r)&&Store::HEALTHY===$r['status']&&1100===$r['checked_at'],'Recovery did not replace warning.');
    $a(!$s->record('local',Store::ERROR,'bad.code','No.',1200),'Local backend must not acquire maintenance state.');
    $a(!$s->record('pt1','bogus','bad.code','No.',1200),'Invalid status must fail closed.');
    fwrite(STDOUT,"Backend maintenance status tests passed.\n");
}
