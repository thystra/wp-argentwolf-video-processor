<?php
/** Dependency-free backend serving-priority policy tests. */
declare(strict_types=1);
namespace ArgentVideo {
    $GLOBALS['awvp_priority_option']=array();
    function get_option(string $name,mixed $default=false):mixed{return $GLOBALS['awvp_priority_option'][$name]??$default;}
    function update_option(string $name,mixed $value,?bool $autoload=null):bool{unset($autoload);$GLOBALS['awvp_priority_option'][$name]=$value;return true;}
    final class Backend_Registry { public const LOCAL_ID='local'; }
}
namespace {
    require_once dirname(__DIR__).'/includes/Backend_Identity.php';
    require_once dirname(__DIR__).'/includes/Backend_Serving_Priority_Store.php';
    use ArgentVideo\Backend_Serving_Priority_Store as Store;
    $a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
    $s=new Store();
    $a(0===$s->priority('local'),'Local priority must remain zero.');
    $a(Store::DEFAULT_REMOTE_PRIORITY===$s->priority('pt1'),'Unconfigured remote did not use default priority.');
    $a($s->save('pt1',200),'Valid backend priority did not save.');
    $a(200===$s->priority('pt1'),'Saved backend priority was not returned.');
    $a(!$s->save('local',999),'Local priority was mutable.');
    $a(!$s->save('pt1',0)&&!$s->save('pt1',Store::MAX_PRIORITY+1),'Out-of-range remote priority was accepted.');
    fwrite(STDOUT,"Backend serving priority tests passed.\n");
}
