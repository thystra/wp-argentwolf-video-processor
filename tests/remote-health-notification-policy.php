<?php
/** Focused remote-health email policy tests. */
declare(strict_types=1);
namespace ArgentVideo {}
namespace {
$GLOBALS['awvp_rhnp_options']=array();
function get_option(string $k,mixed $d=false):mixed{return $GLOBALS['awvp_rhnp_options'][$k]??$d;}
function update_option(string $k,mixed $v,?bool $autoload=null):bool{$GLOBALS['awvp_rhnp_options'][$k]=$v;return true;}
require_once dirname(__DIR__).'/includes/Remote_Health_Notification_Policy_Store.php';
use ArgentVideo\Remote_Health_Notification_Policy_Store as Store;
$a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
$s=new Store();$d=$s->get();
$a(Store::DELAYED===$d['administrator']&&Store::DELAYED===$d['publishing_user']&&Store::OFF===$d['origin_author'],'Upgrade defaults drifted.');
$v=array('version'=>1,'administrator'=>'immediate','publishing_user'=>'off','origin_author'=>'delayed');
$a($s->save($v)&&$s->get()===$v,'Valid policy did not round-trip.');
$a(Store::due('immediate',100,100),'Immediate policy not due at first observation.');
$a(!Store::due('delayed',100,7299),'Delayed policy fired before two hours.');
$a(Store::due('delayed',100,7300),'Delayed policy did not fire at two hours.');
$a(!$s->save(array('version'=>1,'administrator'=>'bogus','publishing_user'=>'off','origin_author'=>'off')),'Malformed policy did not fail closed.');
fwrite(STDOUT,"Remote health notification policy tests passed.\n");
}
