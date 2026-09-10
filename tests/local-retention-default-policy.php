<?php
/** Dependency-free RC10 site-wide Local Retention default-policy tests. */
declare(strict_types=1);

namespace ArgentVideo {
$GLOBALS['awvp_retention_default_options']=array();
function get_option(string $key,mixed $default=false):mixed{return $GLOBALS['awvp_retention_default_options'][$key]??$default;}
function update_option(string $key,mixed $value,bool $autoload=false):bool{unset($autoload);$GLOBALS['awvp_retention_default_options'][$key]=$value;return true;}
final class Local_Retention_Policy{public const MODE_KEEP='keep',MODE_DELETE_MANAGED='delete_managed',MODE_DELETE_ALL='delete_all';}
}

namespace {
require_once dirname(__DIR__).'/includes/Local_Retention_Default_Policy_Store.php';
use ArgentVideo\Local_Retention_Default_Policy_Store as Store;
$failures=0;$assert=static function(bool $ok,string $m)use(&$failures):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");++$failures;}};
$store=new Store();
$assert('keep'===$store->mode(),'Missing default-policy option did not fail closed to keep.');
$r=$store->save('delete_managed',7,1000);$assert(Store::APPLIED===($r['status']??'')&&'delete_managed'===$store->mode(),'Valid default policy was not persisted.');
$r=$store->save('delete_managed',7,1001);$assert(Store::PRESENT===($r['status']??''),'Exact default-policy replay was not idempotent.');
$r=$store->save('delete_all',7,1002);$assert(Store::APPLIED===($r['status']??'')&&'delete_all'===$store->mode(),'Delete-all default policy was not representable for a site that allows it at the admin boundary.');
$before=$GLOBALS['awvp_retention_default_options'];$r=$store->save('bogus',7,1003);$assert(Store::REFUSED===($r['status']??'')&&$before===$GLOBALS['awvp_retention_default_options'],'Malformed default policy mutated durable state.');
$GLOBALS['awvp_retention_default_options'][Store::OPTION]=array('version'=>99,'mode'=>'delete_all','updated_by'=>7,'updated_at'=>1002);$assert('keep'===$store->mode(),'Future/malformed default-policy record did not fail closed to keep.');
if($failures>0)exit(1);fwrite(STDOUT,"RC10 Local Retention default-policy tests passed.\n");
}
