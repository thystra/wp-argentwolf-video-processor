<?php
/** Dependency-free multi-backend priority/failover resolver tests. */
declare(strict_types=1);
namespace ArgentVideo {
    if(!defined('ARRAY_A'))define('ARRAY_A','ARRAY_A');
    $GLOBALS['awvp_health_rows']=array();$GLOBALS['awvp_options']=array();$GLOBALS['awvp_local_available']=true;
    final class Backend_Registry { public const LOCAL_ID='local'; }
    final class Remote_Asset_Repository { public const TABLE_SUFFIX='argent_video_remote_assets'; }
    final class Video_Meta { public const SERVING_AUTHORITY='_authority'; public const ATTACHMENT_ID='_attachment'; public static function sanitize_positive_id(mixed $v):int{return is_numeric($v)&&((int)$v)>0?(int)$v:0;} }
    final class WordPress_Source_File { public static function capture(int $id):array{unset($id);return $GLOBALS['awvp_local_available']?array('ok'=>true):array();} }
    interface PeerTube_Publication_Asset_Store { public function find(int $id):?array; public function record_publication_observation(int $a,int $b,string $c,string $d,string $e,string $f,int $g):string; }
    final class FakeAssets implements PeerTube_Publication_Asset_Store {
        public function __construct(public array $rows){}
        public function find(int $id):?array{foreach($this->rows as $r){if((int)$r['id']===$id)return $r;}return null;}
        public function record_publication_observation(int $a,int $b,string $c,string $d,string $e,string $f,int $g):string{unset($a,$b,$c,$d,$e,$f,$g);return '';}
        public function serving_candidates_for_video(int $id):array{return array_values(array_filter($this->rows,static fn(array $r):bool=>(int)$r['video_post_id']===$id));}
    }
    interface Video_Serving_Resolver { public function peertube_embed_url(int $video_id):string; }
    function get_option(string $n,mixed $d=false):mixed{return $GLOBALS['awvp_options'][$n]??$d;}
    function update_option(string $n,mixed $v,?bool $autoload=null):bool{unset($autoload);$GLOBALS['awvp_options'][$n]=$v;return true;}
    function metadata_exists(string $type,int $id,string $key):bool{unset($type,$id,$key);return false;}
    function get_post_meta(int $id,string $key,bool $single=true):mixed{unset($single);return Video_Meta::ATTACHMENT_ID===$key?55:'';}
    function wp_get_attachment_url(int $id):string{return 'https://site.example/uploads/'.$id.'.mp4';}
}
namespace {
    require_once dirname(__DIR__).'/includes/Backend_Identity.php';
    require_once dirname(__DIR__).'/includes/Serving_Viability.php';
    require_once dirname(__DIR__).'/includes/Backend_Serving_Priority_Store.php';
    require_once dirname(__DIR__).'/includes/Remote_Publication_Health_Repository.php';
    require_once dirname(__DIR__).'/includes/Video_Serving_Service.php';
    use ArgentVideo\Backend_Serving_Priority_Store;use ArgentVideo\Remote_Publication_Health_Repository;use ArgentVideo\Video_Serving_Service;use ArgentVideo\FakeAssets;
    $GLOBALS['wpdb']=new class {public string $prefix='wp_';public function prepare(string $q,mixed ...$args):string{return json_encode(array($q,$args),JSON_THROW_ON_ERROR);}public function get_results(string $p,string $o):array{unset($o);[$q,$args]=json_decode($p,true,512,JSON_THROW_ON_ERROR);if(str_contains($q,'WHERE video_post_id = %d')){return array_values(array_filter($GLOBALS['awvp_health_rows'],static fn(array $r):bool=>(int)$r['video_post_id']===(int)$args[1]));}return array();}public function get_row(string $p,string $o):?array{unset($p,$o);return null;}};
    $a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
    $base=static fn(int $id,string $backend,string $url):array=>array('id'=>$id,'video_post_id'=>77,'backend_id'=>$backend,'channel_id'=>'1','remote_id'=>'123e4567-e89b-42d3-a456-42661417400'.$id,'role'=>'secondary','state'=>'ready','desired_privacy'=>'public','actual_privacy'=>'public','remote_processing_state'=>'1:published','embed_url'=>$url,'last_verified_at'=>'2026-09-10 20:00:00');
    $assets=new FakeAssets(array($base(1,'pt1','https://pt1.example/embed/one'),$base(2,'pt2','https://pt2.example/embed/two')));
    $GLOBALS['awvp_options'][Backend_Serving_Priority_Store::OPTION]=array('version'=>1,'priorities'=>array('pt1'=>500,'pt2'=>200));
    $GLOBALS['awvp_health_rows']=array(
        array('remote_asset_id'=>1,'video_post_id'=>77,'backend_id'=>'pt1','status'=>'healthy','eligible'=>1),
        array('remote_asset_id'=>2,'video_post_id'=>77,'backend_id'=>'pt2','status'=>'healthy','eligible'=>1),
    );
    $service=new Video_Serving_Service($assets,new Remote_Publication_Health_Repository(),new Backend_Serving_Priority_Store());
    $c=$service->serving_candidate(77);$a('pt1'===$c['backend_id']&&500===$c['priority'],'Highest-priority healthy remote was not selected.');
    $GLOBALS['awvp_health_rows'][0]['eligible']=0;$GLOBALS['awvp_health_rows'][0]['status']='missing';
    $c=$service->serving_candidate(77);$a('pt2'===$c['backend_id'],'Resolver did not fail over to second healthy backend.');
    $GLOBALS['awvp_health_rows'][1]['eligible']=0;$GLOBALS['awvp_health_rows'][1]['status']='temporarily_unavailable';
    $c=$service->serving_candidate(77);$a('local'===$c['backend_id']&&'local'===$c['kind'],'Resolver did not fall back to WordPress original.');
    $GLOBALS['awvp_local_available']=false;$a(array()===$service->serving_candidate(77),'Resolver invented a serving source when all candidates were unavailable.');
    fwrite(STDOUT,"Multi-backend serving failover tests passed.\n");
}
