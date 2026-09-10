<?php
/** Dependency-free normalized serving viability tests. */
declare(strict_types=1);
require_once dirname(__DIR__).'/includes/Serving_Viability.php';
use ArgentVideo\Serving_Viability;
$a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
$healthy=Serving_Viability::healthy();
$a($healthy->viable()&&Serving_Viability::HEALTHY===$healthy->status(),'Healthy serving result drifted.');
$processing=Serving_Viability::create(Serving_Viability::PROCESSING,'provider.processing','Still processing.',200);
$a($processing instanceof Serving_Viability&&!$processing->viable(),'Processing result must be valid but not serving-viable.');
$missing=Serving_Viability::create(Serving_Viability::MISSING,'provider.missing','Remote publication is gone.',404);
$a($missing instanceof Serving_Viability&&!$missing->viable()&&404===$missing->http_status(),'Missing serving result did not normalize.');
$a(null===Serving_Viability::create('invented','x','x',500),'Unknown health status was accepted.');
$a(null===Serving_Viability::create(Serving_Viability::MISSING,'','missing',404),'Non-healthy result without reason code was accepted.');
$a(null===Serving_Viability::create(Serving_Viability::HEALTHY,'reason','bad',200),'Healthy result carried failure detail.');
$a(null===Serving_Viability::create(Serving_Viability::MISSING,'BAD SPACE','x',404),'Unsafe reason code was accepted.');
fwrite(STDOUT,"Serving viability tests passed.\n");
