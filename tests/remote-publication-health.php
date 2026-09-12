<?php
/** Dependency-free remote publication health persistence/hysteresis tests. */
declare(strict_types=1);
namespace ArgentVideo {
    if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
    final class Backend_Registry { public const LOCAL_ID='local'; }
    final class Remote_Asset_Repository { public const TABLE_SUFFIX='argent_video_remote_assets'; }
}
namespace {
    require_once dirname(__DIR__).'/includes/Backend_Identity.php';
    require_once dirname(__DIR__).'/includes/Serving_Viability.php';
    require_once dirname(__DIR__).'/includes/Remote_Publication_Health_Repository.php';
    use ArgentVideo\Remote_Publication_Health_Repository as Repo; use ArgentVideo\Serving_Viability;
    $GLOBALS['wpdb']=new class {
        public string $prefix='wp_'; public array $rows=[];
        public function prepare(string $q,mixed ...$args):string{return json_encode(array($q,$args),JSON_THROW_ON_ERROR);}
        public function get_row(string $prepared,string $output):?array{unset($output);[$q,$args]=json_decode($prepared,true,512,JSON_THROW_ON_ERROR);if(str_contains($q,'WHERE remote_asset_id = %d'))return $this->rows[(int)$args[1]]??null;return null;}
        public function insert(string $table,array $data,array $formats):int|false{unset($table,$formats);$id=(int)$data['remote_asset_id'];if(isset($this->rows[$id]))return false;$this->rows[$id]=$data;return 1;}
        public function update(string $table,array $data,array $where,array $formats,array $where_formats):int|false{unset($table,$formats,$where_formats);$id=(int)$where['remote_asset_id'];if(!isset($this->rows[$id]))return false;$this->rows[$id]=$data;return 1;}
        public function query(string $prepared):int|false{[$q,$args]=json_decode($prepared,true,512,JSON_THROW_ON_ERROR);if(!str_contains($q,'SET eligible = 1'))return false;$id=(int)($args[4]??0);$row=$this->rows[$id]??null;if(!is_array($row))return 0;if((int)$row['video_post_id']!==(int)($args[5]??0)||(string)$row['backend_id']!==(string)($args[6]??'')||(string)$row['status']!==(string)($args[7]??'')||1===(int)$row['eligible']||(int)$row['success_streak']<1||(string)$row['last_checked_at']!==(string)($args[8]??''))return 0;$row['eligible']=1;$row['success_streak']=max((int)$row['success_streak'],(int)($args[1]??2));$row['failure_streak']=0;$row['failure_since']=null;$row['next_check_at']=(string)($args[2]??'');$row['updated_at']=(string)($args[3]??'');$this->rows[$id]=$row;return 1;}
        public function get_results(string $prepared,string $output):array{unset($prepared,$output);return array();}
    };
    $a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
    $r=new Repo();$now=1000000;
    $processing=Serving_Viability::create(Serving_Viability::PROCESSING,'provider.processing','Still processing.',200);$a($processing instanceof Serving_Viability,'Processing fixture invalid.');
$a(Repo::APPLIED===$r->record(8,77,'pt1',$processing,$now,true,$now+300),'Processing observation did not persist.');
$processingRow=$r->find(8);$a(0===(int)$processingRow['eligible']&&0===(int)$processingRow['failure_streak']&&(null===$processingRow['failure_since']||''===$processingRow['failure_since']),'Expected processing incorrectly started broken-publication failure state.');
$a(gmdate('Y-m-d H:i:s',$now+300)===$processingRow['next_check_at'],'Processing ETA did not control next-check cadence.');
$a(Repo::APPLIED===$r->record(9,77,'pt1',Serving_Viability::healthy(),$now,true),'Initial verified health did not persist.');
    $row=$r->find(9);$a(1===(int)$row['eligible']&&1===(int)$row['success_streak'],'Initial verified publication must be immediately serving-eligible.');
    $a(gmdate('Y-m-d H:i:s',$now+Repo::FIRST_FAILURE_RETRY)===$row['next_check_at'],'New publication did not receive an early public-serving verification.');
    $missing=Serving_Viability::create(Serving_Viability::MISSING,'provider.missing','Gone',404);$a($missing instanceof Serving_Viability,'Fixture invalid.');
    $a(Repo::APPLIED===$r->record(9,77,'pt1',$missing,$now+10),'Missing observation did not persist.');
    $row=$r->find(9);$a(0===(int)$row['eligible']&&1===(int)$row['failure_streak']&&404===(int)$row['http_status'],'First failure did not immediately remove serving eligibility.');
    $a(Repo::APPLIED===$r->record(9,77,'pt1',Serving_Viability::healthy(),$now+20),'First recovery observation failed.');
    $row=$r->find(9);$a(0===(int)$row['eligible']&&1===(int)$row['success_streak'],'One success after failure must not fail back yet.');
    $checked=(string)$row['last_checked_at'];
    $a(Repo::APPLIED===$r->operator_restore_eligible(9,77,'pt1',$checked,$now+21),'Fresh operator-confirmed recovery did not restore exact healthy row eligibility.');
    $row=$r->find(9);$a(1===(int)$row['eligible']&&2<=(int)$row['success_streak'],'Operator restore did not satisfy serving recovery eligibility.');
    $a(Repo::PRESENT===$r->operator_restore_eligible(9,77,'pt1',$checked,$now+22),'Exact operator restore replay should converge without another update.');

    $a(Repo::APPLIED===$r->record(10,77,'pt1',Serving_Viability::healthy(),$now,true),'Second hysteresis fixture initial health failed.');
    $a(Repo::APPLIED===$r->record(10,77,'pt1',$missing,$now+10),'Second hysteresis fixture failure did not persist.');
    $a(Repo::APPLIED===$r->record(10,77,'pt1',Serving_Viability::healthy(),$now+20),'Second fixture first recovery observation failed.');
    $row=$r->find(10);$a(0===(int)$row['eligible']&&1===(int)$row['success_streak'],'Automatic recovery must still wait after one success.');
    $a(Repo::APPLIED===$r->record(10,77,'pt1',Serving_Viability::healthy(),$now+30),'Second fixture second recovery observation failed.');
    $row=$r->find(10);$a(1===(int)$row['eligible']&&2===(int)$row['success_streak'],'Two consecutive automatic successes must restore serving eligibility.');
    fwrite(STDOUT,"Remote publication health tests passed.\n");
}
