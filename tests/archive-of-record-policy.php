<?php
/** Dependency-free RC10 site-wide archive-of-record policy tests. */
declare(strict_types=1);
namespace ArgentVideo {
$GLOBALS['awvp_archive_options']=array();
function get_option(string $name,mixed $default=false):mixed{return $GLOBALS['awvp_archive_options'][$name]??$default;}
function update_option(string $name,mixed $value,bool|string|null $autoload=null):bool{unset($autoload);$GLOBALS['awvp_archive_options'][$name]=$value;return true;}
function wp_json_encode(mixed $value,int $flags=0):string|false{return json_encode($value,$flags);}
}
namespace {
require_once dirname(__DIR__).'/includes/Local_Retention_Policy.php';
require_once dirname(__DIR__).'/includes/Archive_Of_Record_Policy_Store.php';
use ArgentVideo\Archive_Of_Record_Policy_Store as Store;
$f=0;$a=function(bool $ok,string $m)use(&$f){if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");$f++;}};
$store=new Store();
$default=$store->get();
$a(Store::WORDPRESS===$default['archive_of_record']&&7===$default['grace_days'],'Archive policy must default fail-closed to WordPress as archive of record.');
$a(!$store->source_deletion_allowed(),'Default archive policy unexpectedly permits automatic original deletion.');
$r=$store->save(Store::NOT_WORDPRESS,14,7,1000,false);
$a(Store::REFUSED===$r['status']&&Store::WORDPRESS===$store->get()['archive_of_record'],'Transition away from WordPress archive did not require one-time acknowledgement.');
$r=$store->save(Store::NOT_WORDPRESS,14,7,1000,true);
$a(Store::APPLIED===$r['status']&&$store->source_deletion_allowed(),'Acknowledged transition away from WordPress archive was not persisted.');
$p=$store->get();$a(7===$p['confirmed_by']&&1000===$p['confirmed_at'],'One-time destructive-policy acknowledgement audit is missing.');
$r=$store->save(Store::NOT_WORDPRESS,30,7,1100,false);
$a(Store::APPLIED===$r['status']&&30===$store->grace_days(),'Grace-period edit incorrectly required repeated destructive acknowledgement.');
$p=$store->get();$a(7===$p['confirmed_by']&&1000===$p['confirmed_at'],'Grace-period edit rewrote the original destructive-policy acknowledgement.');
$r=$store->save(Store::WORDPRESS,30,7,1200,false);
$a(Store::APPLIED===$r['status']&&$store->wordpress_is_archive()&&!$store->source_deletion_allowed(),'Switching back to WordPress archive did not immediately prohibit automatic source deletion.');
$p=$store->get();$a(0===$p['confirmed_by']&&0===$p['confirmed_at'],'Safe WordPress-archive policy retained stale destructive acknowledgement fields.');
$r=$store->save(Store::NOT_WORDPRESS,0,7,1250,true);
$a(Store::APPLIED===$r['status']&&0===$store->grace_days(),'Zero-day site grace must persist as Never rather than immediate deletion.');
$GLOBALS['awvp_archive_options'][Store::OPTION]=array('garbage'=>true);
$a(Store::WORDPRESS===$store->get()['archive_of_record'],'Malformed archive policy did not fail closed to WordPress-as-archive.');
if($f>0)exit(1);echo "Archive-of-record policy tests passed.\n";
}
