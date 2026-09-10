<?php
/** Focused backend outage-deduplication store tests. */
declare(strict_types=1);
namespace ArgentVideo { final class Backend_Registry{public const LOCAL_ID='local';} final class Backend_Identity{public static function sanitize(mixed $v):string{return is_string($v)&&1===preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D',$v)?$v:'';}} }
namespace {
$GLOBALS['awvp_bhi_options']=array();function get_option(string $k,mixed $d=false):mixed{return $GLOBALS['awvp_bhi_options'][$k]??$d;}function update_option(string $k,mixed $v,?bool $autoload=null):bool{$GLOBALS['awvp_bhi_options'][$k]=$v;return true;}
require_once dirname(__DIR__).'/includes/Serving_Viability.php';require_once dirname(__DIR__).'/includes/Backend_Health_Incident_Store.php';
use ArgentVideo\Backend_Health_Incident_Store as Store;use ArgentVideo\Serving_Viability as V;
$a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};$s=new Store();
$down=V::create(V::TEMPORARILY_UNAVAILABLE,'peertube.health.public_unavailable','Server unreachable.',503);$missing=V::create(V::MISSING,'peertube.health.public_missing','Video missing.',404);
$a(Store::backend_wide($down)&&!Store::backend_wide($missing),'Backend-wide classification drifted.');$a($s->mark_failure('pt1',$down,1000),'Could not establish backend outage.');$r=$s->get('pt1');$a(is_array($r)&&1000===$r['failure_since']&&503===$r['http_status'],'Backend outage did not round-trip.');
$a($s->mark_failure('pt1',$down,1100),'Could not update backend outage.');$r=$s->get('pt1');$a(1000===$r['failure_since']&&1100===$r['last_failure_at'],'Repeated outage reset incident origin.');$a(!$s->mark_failure('pt1',$missing,1200),'Per-video missing state must not become backend outage.');$a($s->mark_healthy('pt1')&&null===$s->get('pt1'),'Healthy observation did not clear backend outage.');
fwrite(STDOUT,"Backend health incident tests passed.\n");
}
