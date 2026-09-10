<?php
/** RC10 Overview disposition storage tests. */
declare(strict_types=1);
namespace ArgentVideo {
    $GLOBALS['awvp_overview_options']=array();
    function get_option(string $key,mixed $default=false):mixed{return $GLOBALS['awvp_overview_options'][$key]??$default;}
    function update_option(string $key,mixed $value,bool $autoload=false):bool{unset($autoload);$GLOBALS['awvp_overview_options'][$key]=$value;return true;}
}
namespace {
    require_once dirname(__DIR__).'/includes/Overview_Disposition_Store.php';
    use ArgentVideo\Overview_Disposition_Store;
    $assert=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
    $s=new Overview_Disposition_Store(); $a=str_repeat('a',64); $b=str_repeat('b',64);
    $assert($s->set($a,Overview_Disposition_Store::REVIEWED,10,100),'Could not mark issue reviewed.');
    $assert(Overview_Disposition_Store::REVIEWED===$s->state($a),'Reviewed state did not persist.');
    $assert($s->set($b,Overview_Disposition_Store::DISMISSED,11,101),'Could not dismiss issue.');
    $assert(Overview_Disposition_Store::DISMISSED===$s->state($b),'Dismissed state did not persist.');
    $s->prune(array($b));
    $assert(''===$s->state($a)&&Overview_Disposition_Store::DISMISSED===$s->state($b),'Resolved issue disposition was not pruned.');
    $assert($s->clear($b)&&''===$s->state($b),'Disposition clear failed.');
    fwrite(STDOUT,"Overview disposition store tests passed.\n");
}
