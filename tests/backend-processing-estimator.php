<?php
/** Dependency-free backend processing ETA/history tests. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Backend_Registry { public const LOCAL_ID='local'; }
}

namespace {
    $GLOBALS['awvp_processing_options']=array();
    function get_option(string $key,mixed $default=false):mixed{return $GLOBALS['awvp_processing_options'][$key]??$default;}
    function update_option(string $key,mixed $value,mixed $autoload=null):bool{unset($autoload);$GLOBALS['awvp_processing_options'][$key]=$value;return true;}
    function delete_option(string $key):bool{$had=array_key_exists($key,$GLOBALS['awvp_processing_options']);unset($GLOBALS['awvp_processing_options'][$key]);return $had;}

    require_once dirname(__DIR__).'/includes/Backend_Identity.php';
    require_once dirname(__DIR__).'/includes/Backend_Processing_Estimator.php';

    use ArgentVideo\Backend_Processing_Estimator as Estimator;

    $a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
    $e=new Estimator();
    $now=2_000_000_000;
    $mib=1048576;

    $fallback=$e->estimate('pt1',50*$mib,$now-60,$now);
    $a('size_fallback'===$fallback['basis']&&'low'===$fallback['confidence']&&$fallback['seconds']>0,'Cold-start size fallback estimate is invalid.');

    // Old observations outside the rolling 90-day window must not influence current estimates.
    $GLOBALS['awvp_processing_options'][Estimator::OPTION]=array(
        'version'=>Estimator::VERSION,
        'backends'=>array('pt1'=>array(array('bytes'=>50*$mib,'seconds'=>7200,'observed_at'=>$now-Estimator::MAX_SAMPLE_AGE-1)))
    );
    $old=$e->estimate('pt1',50*$mib,$now-60,$now);
    $a(0===$old['sample_count']&&'size_fallback'===$old['basis'],'Expired backend performance sample was retained.');

    $GLOBALS['awvp_processing_options']=array();
    for($i=0;$i<12;$i++){
        $a($e->observe('pt1',(40+$i)*$mib,300+$i*10,$now-1200+$i*30),'Recent processing sample did not persist.');
    }
    $history=$e->history($now);
    $a(isset($history['pt1'])&&Estimator::MAX_SAMPLES===count($history['pt1']),'Processing history is not bounded to the last ten recent samples.');
    $a(42*$mib===$history['pt1'][0]['bytes'],'Bounded history did not discard the oldest samples first.');

    $estimate=$e->estimate('pt1',48*$mib,$now-100,$now);
    $a('recent_same_size_band'===$estimate['basis']&&'high'===$estimate['confidence']&&10===$estimate['sample_count'],'Same-size recent history was not preferred.');
    $a($estimate['estimated_ready_at']===$now-100+$estimate['seconds'],'Estimated ready timestamp is inconsistent.');

    // A backend upgrade should naturally replace stale/slower observations as the last-ten window advances.
    $GLOBALS['awvp_processing_options']=array();
    for($i=0;$i<10;$i++){$e->observe('pt1',100*$mib,1800,$now-1000+$i);}
    $slow=$e->estimate('pt1',100*$mib,$now-10,$now);
    for($i=0;$i<10;$i++){$e->observe('pt1',100*$mib,300,$now-100+$i);}
    $fast=$e->estimate('pt1',100*$mib,$now-10,$now);
    $a($fast['seconds']<$slow['seconds']/2,'Recent faster backend turnaround did not supersede old runner performance.');


    $bands=Estimator::size_bands();
    $a(5===count($bands)&&64*$mib===$bands[0]['max_bytes']&&4096*$mib+1===$bands[4]['min_bytes'],'Estimator size-band definitions drifted from the runtime bucket boundaries.');
    $summaries=$e->summaries('pt1',$now);
    $a(5===count($summaries)&&10===$summaries[1]['same_bucket_count']&&'high'===$summaries[1]['confidence'],'Readiness summary did not expose the exact same-band sample basis.');

    $a($e->observe('pt2',500*$mib,800,$now-10),'Second-backend sample did not persist.');
    $a($e->reset('pt1',$now),'Per-backend readiness reset failed.');
    $afterReset=$e->history($now);
    $a(!isset($afterReset['pt1'])&&isset($afterReset['pt2']),'Per-backend reset removed the wrong readiness history.');
    $a($e->reset(null,$now),'All-backend readiness reset failed.');
    $a(array()===$e->history($now),'All-backend reset did not clear readiness history.');

    $a(Estimator::MIN_PROBE_DELAY===Estimator::next_probe_delay($now+30,$now),'Near-ready ETA should poll at minimum delay.');
    $a(Estimator::MAX_PROBE_DELAY>=Estimator::next_probe_delay($now+7200,$now),'Long ETA exceeded bounded probe cadence.');
    $a(Estimator::unusually_long($now+600,$now,$now+2400),'Unusually long processing threshold was not detected.');

    fwrite(STDOUT,"Backend processing estimator tests passed.\n");
}
