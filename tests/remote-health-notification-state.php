<?php
/** Focused notification delivery journal tests. */
declare(strict_types=1);
namespace ArgentVideo { final class Backend_Identity{public static function sanitize(mixed $v):string{return is_string($v)&&1===preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D',$v)?$v:'';}} }
namespace {
$GLOBALS['awvp_rhns_options']=array();
function get_option(string $k,mixed $d=false):mixed{return $GLOBALS['awvp_rhns_options'][$k]??$d;}
function update_option(string $k,mixed $v,?bool $autoload=null):bool{$GLOBALS['awvp_rhns_options'][$k]=$v;return true;}
require_once dirname(__DIR__).'/includes/Remote_Health_Notification_State_Store.php';
use ArgentVideo\Remote_Health_Notification_State_Store as Store;
$a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};$s=new Store();
$a($s->mark_asset(7,100,array('administrator','origin_author'),200),'Could not mark asset alert.');$r=$s->asset(7);$a(array('administrator','origin_author')===$r['notified_roles'],'Asset roles not normalized.');
$a($s->mark_asset(7,100,array('administrator'),300,true),'Could not mark recovery.');$r=$s->asset(7);$a(array('administrator')===$r['recovered_roles'],'Recovery role not recorded.');
$a($s->mark_asset(7,400,array('publishing_user'),500),'New incident could not replace old journal.');$r=$s->asset(7);$a(400===$r['failure_since']&&array('publishing_user')===$r['notified_roles']&&array()===$r['recovered_roles'],'New incident did not reset old delivery state.');
$a($s->mark_backend('pt1',600,array('administrator'),700),'Backend alert state did not persist.');$a(in_array('administrator',$s->backend('pt1')['notified_roles'],true),'Backend alert role absent.');
$a($s->clear_asset(7)&&0===$s->asset(7)['failure_since'],'Asset journal did not clear.');$a($s->clear_backend('pt1')&&0===$s->backend('pt1')['failure_since'],'Backend journal did not clear.');
fwrite(STDOUT,"Remote health notification state tests passed.\n");
}
