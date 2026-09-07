<?php
/** Dependency-free orchestration tests for R46.8 one-way migration promotion. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Task_Repository
    {
        public const APPLIED='applied'; public const PRESENT='present'; public const CONFLICT='conflict'; public const INDETERMINATE='indeterminate';
        public array $rows=array(); public int $next_id=1;
        public function enqueue(string $type,int $video_id,?int $op,string $backend,string $key,array $payload,int $created,int $run_after,int $max,int $priority): array
        {
            unset($op,$backend,$payload,$created,$run_after,$max,$priority);
            if (isset($this->rows[$key])) return array('status'=>self::PRESENT,'task_id'=>$this->rows[$key]);
            $id=$this->next_id++; $this->rows[$key]=$id; return array('status'=>self::APPLIED,'task_id'=>$id);
        }
    }
    final class Editorial_Publish_Validator {}
}

namespace {
$GLOBALS['awvp_r468_options']=array();
$GLOBALS['awvp_r468_posts']=array();
$GLOBALS['awvp_r468_meta']=array();
function __(string $t,string $d=''):string{unset($d);return $t;}
function sanitize_text_field(mixed $v):string{return trim((string)$v);}
function sanitize_key(mixed $v):string{return preg_replace('/[^a-z0-9_-]/','',strtolower((string)$v))??'';}
function esc_url_raw(string $v):string{return $v;}
function wp_json_encode(mixed $v,int $flags=0,int $depth=512):string|false{return json_encode($v,$flags,$depth);}
function get_option(string $n,mixed $d=false):mixed{return $GLOBALS['awvp_r468_options'][$n]??$d;}
function add_option(string $n,mixed $v='',string $deprecated='',bool|string $autoload='yes'):bool{unset($deprecated,$autoload);if(array_key_exists($n,$GLOBALS['awvp_r468_options']))return false;$GLOBALS['awvp_r468_options'][$n]=$v;return true;}
function update_option(string $n,mixed $v,bool|string|null $autoload=null):bool{unset($autoload);$GLOBALS['awvp_r468_options'][$n]=$v;return true;}
function delete_option(string $n):bool{if(!array_key_exists($n,$GLOBALS['awvp_r468_options']))return false;unset($GLOBALS['awvp_r468_options'][$n]);return true;}
function wp_set_option_autoload(string $n,bool|string $v):bool{unset($n,$v);return true;}
function wp_load_alloptions(bool $force=false):array{unset($force);return array();}
function get_post(int $id):object|false{return $GLOBALS['awvp_r468_posts'][$id]??false;}
function metadata_exists(string $type,int $id,string $key):bool{unset($type);return array_key_exists($key,$GLOBALS['awvp_r468_meta'][$id]??array());}
function get_post_meta(int $id,string $key,bool $single=false):mixed{$v=$GLOBALS['awvp_r468_meta'][$id][$key]??($single?'':array());return $single?$v:array($v);}
function update_post_meta(int $id,string $key,mixed $v):int|bool{$GLOBALS['awvp_r468_meta'][$id][$key]=$v;return 1;}
function get_posts(array $args=array()):array{unset($args);return array();}
function wp_attachment_is(string $type,int $id):bool{$p=$GLOBALS['awvp_r468_posts'][$id]??null;return 'video'===$type&&is_object($p)&&str_starts_with((string)($p->post_mime_type??''),'video/');}

$root=dirname(__DIR__).'/includes/';
foreach (array(
'Backend_Identity.php','PeerTube_Origin.php','PeerTube_Connection_Input.php','Atomic_Option_Snapshot.php','Atomic_Option_Result.php','Atomic_Option_Mutation_Plan.php','Atomic_Option_Plan_Result.php','Atomic_Option_Store.php','Backend_Registry.php',
'Video_Destination.php','PeerTube_Publication_Plan.php','PeerTube_Migration_Plan.php','PeerTube_Migration_Execution.php','PeerTube_Publication_Lifecycle.php','PeerTube_Publication_Catalog.php','PeerTube_Publication_Catalog_Store.php','Video_Publishing_Defaults.php','Video_Publishing_Defaults_Store.php','PeerTube_Publication_Manifest.php','Video_Post_Type.php','Video_Meta.php','PeerTube_Publication_Synchronizer.php','PeerTube_Migration_Executor.php'
) as $file) require_once $root.$file;

use ArgentVideo\Backend_Registry; use ArgentVideo\PeerTube_Migration_Execution; use ArgentVideo\PeerTube_Migration_Executor; use ArgentVideo\PeerTube_Migration_Plan; use ArgentVideo\PeerTube_Publication_Catalog_Store; use ArgentVideo\PeerTube_Publication_Plan; use ArgentVideo\PeerTube_Publication_Synchronizer; use ArgentVideo\Task_Repository; use ArgentVideo\Editorial_Publish_Validator; use ArgentVideo\Video_Destination; use ArgentVideo\Video_Meta; use ArgentVideo\Video_Publishing_Defaults; use ArgentVideo\Video_Publishing_Defaults_Store;
$a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};

$local=array('id'=>'local','type'=>'local','label'=>'Local','state'=>'active','default_destination'=>'','secret_ref'=>'','config_version'=>1,'config'=>array());
$pt=array('id'=>'pt-primary','type'=>'peertube','label'=>'PeerTube','state'=>'active','default_destination'=>'41','secret_ref'=>'managed:pt-primary','config_version'=>1,'config'=>array('origin'=>'https://video.example.org'));
$GLOBALS['awvp_r468_options'][Backend_Registry::OPTION]=array('version'=>1,'backends'=>array('local'=>$local,'pt-primary'=>$pt));
$settings=Video_Publishing_Defaults::defaults();
$GLOBALS['awvp_r468_options'][Video_Publishing_Defaults_Store::OPTION]=$settings;
$catalog=array('version'=>1,'backend_id'=>'pt-primary','origin'=>'https://video.example.org','secret_generation'=>3,'server_version'=>'8.1.2','refreshed_at'=>1900,'stale'=>false,'stale_since'=>null,'stale_reason'=>'','channels'=>array(array('id'=>'77','name'=>'main','display_name'=>'Main','authority'=>'owned')),'privacies'=>array('1'=>'Public','2'=>'Unlisted','3'=>'Private','5'=>'Password protected'),'licences'=>array('1'=>'Attribution'),'categories'=>array('2'=>'People'),'languages'=>array('en'=>'English'),'capabilities'=>array('sensitive_content'=>true,'sensitive_flags'=>true,'password_privacy'=>true));
$GLOBALS['awvp_r468_options'][PeerTube_Publication_Catalog_Store::option_name('pt-primary')]=$catalog;

$make_ready=static function(int $video_id,int $attachment_id,int $anchor_id):array{
    $publication=array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'77','title'=>'Legacy Clip','description_markdown'=>'','tags'=>array(),'support'=>array('mode'=>'none','preset_id'=>'','markdown'=>''),'final_privacy_id'=>'2','licence_id'=>'','category_id'=>'','language'=>'','thumbnail_attachment_id'=>0,'download_enabled'=>true,'originally_published_at'=>'','comments_policy'=>'enabled','moderation'=>array('reviewed'=>true,'sensitive'=>false,'reason'=>'','violent'=>false,'sexually_explicit'=>false),'embed'=>array('restricted'=>false,'domains'=>array()),'review'=>array('title'=>true,'channel'=>true,'tags'=>true,'privacy'=>true,'moderation'=>true),'dispatch_policy'=>PeerTube_Publication_Plan::DISPATCH_SEND_NOW,'release_policy'=>PeerTube_Publication_Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,'anchor_post_id'=>$anchor_id);
    return array('version'=>1,'video_id'=>$video_id,'attachment_id'=>$attachment_id,'anchor_post_id'=>$anchor_id,'backend_id'=>'pt-primary','channel_id'=>'77','status'=>'ready','issues'=>array(),'suggested_tags'=>array(),'publication_plan'=>$publication,'planned_at'=>1700,'updated_at'=>1800);
};
$GLOBALS['awvp_r468_posts'][10]=(object)array('ID'=>10,'post_type'=>'post','post_status'=>'draft','post_author'=>8);
$GLOBALS['awvp_r468_posts'][20]=(object)array('ID'=>20,'post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'video/mp4');
$GLOBALS['awvp_r468_posts'][100]=(object)array('ID'=>100,'post_type'=>'argent_video_asset','post_status'=>'publish');
$GLOBALS['awvp_r468_meta'][100]=array(Video_Meta::ATTACHMENT_ID=>20,Video_Meta::ORIGIN_POST_ID=>10,Video_Meta::SOURCE_STATE=>'present',Video_Meta::PEERTUBE_MIGRATION_PLAN=>$make_ready(100,20,10));
$tasks=new Task_Repository(); $sync=new PeerTube_Publication_Synchronizer($tasks,new Editorial_Publish_Validator()); $registry=new Backend_Registry(); $defaults=new Video_Publishing_Defaults_Store($registry); $executor=new PeerTube_Migration_Executor($registry,new PeerTube_Publication_Catalog_Store(),$defaults,$sync,static fn(array $descriptor,string $backend_id):int=>3);
$r=$executor->execute(100,2000);
$a(PeerTube_Migration_Executor::APPLIED===$r['status'],'Ready migration did not execute.');
$exec=PeerTube_Migration_Execution::sanitize($GLOBALS['awvp_r468_meta'][100][Video_Meta::PEERTUBE_MIGRATION_EXECUTION]??null);
$a(PeerTube_Migration_Execution::STATUS_DISPATCHED===$exec['status'],'Migration did not reach dispatched handoff.');
$a(1===$exec['lifecycle_generation'] && 1===$exec['task_id'],'Migration did not freeze lifecycle/task handoff identity.');
$a(($GLOBALS['awvp_r468_meta'][100][Video_Meta::PEERTUBE_PUBLICATION_PLAN]??null)===$GLOBALS['awvp_r468_meta'][100][Video_Meta::PEERTUBE_MIGRATION_PLAN]['publication_plan'],'Migration did not promote exact reviewed publication plan.');
$a(array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'77')===($GLOBALS['awvp_r468_meta'][100][Video_Meta::DESTINATION]??null),'Migration did not promote exact destination.');
$a(metadata_exists('post',100,Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE),'Migration did not hand off to qualified lifecycle synchronizer.');
$a(1===count($tasks->rows),'Migration created unexpected duplicate task work.');
$again=$executor->execute(100,2010);$a(PeerTube_Migration_Executor::PRESENT===$again['status'] && 1===count($tasks->rows),'Dispatched migration replay was not idempotent.');

// A stale catalog blocks the one-way commitment before live state is changed.
$GLOBALS['awvp_r468_posts'][11]=(object)array('ID'=>11,'post_type'=>'post','post_status'=>'publish','post_author'=>9);
$GLOBALS['awvp_r468_posts'][21]=(object)array('ID'=>21,'post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'video/mp4');
$GLOBALS['awvp_r468_posts'][101]=(object)array('ID'=>101,'post_type'=>'argent_video_asset','post_status'=>'publish');
$GLOBALS['awvp_r468_meta'][101]=array(Video_Meta::ATTACHMENT_ID=>21,Video_Meta::ORIGIN_POST_ID=>11,Video_Meta::SOURCE_STATE=>'present',Video_Meta::PEERTUBE_MIGRATION_PLAN=>$make_ready(101,21,11));
$stale=$catalog;$stale['stale']=true;$stale['stale_since']=1990;$stale['stale_reason']='refresh_failed';$GLOBALS['awvp_r468_options'][PeerTube_Publication_Catalog_Store::option_name('pt-primary')]=$stale;
$refused=$executor->execute(101,2020);$a(PeerTube_Migration_Executor::REFUSED===$refused['status'],'Stale execution-time provider catalog was accepted.');
$a(!metadata_exists('post',101,Video_Meta::PEERTUBE_MIGRATION_EXECUTION) && !metadata_exists('post',101,Video_Meta::PEERTUBE_PUBLICATION_PLAN),'Refused execution changed live/journal state.');
$GLOBALS['awvp_r468_options'][PeerTube_Publication_Catalog_Store::option_name('pt-primary')]=$catalog;

// Crash recovery: prepared journal + already-written publication plan must converge forward.
$GLOBALS['awvp_r468_posts'][12]=(object)array('ID'=>12,'post_type'=>'post','post_status'=>'future','post_author'=>7);
$GLOBALS['awvp_r468_posts'][22]=(object)array('ID'=>22,'post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'video/mp4');
$GLOBALS['awvp_r468_posts'][102]=(object)array('ID'=>102,'post_type'=>'argent_video_asset','post_status'=>'publish');
$m102=$make_ready(102,22,12);$GLOBALS['awvp_r468_meta'][102]=array(Video_Meta::ATTACHMENT_ID=>22,Video_Meta::ORIGIN_POST_ID=>12,Video_Meta::SOURCE_STATE=>'present',Video_Meta::DESTINATION=>Video_Destination::local(),Video_Meta::PEERTUBE_MIGRATION_PLAN=>$m102,Video_Meta::PEERTUBE_PUBLICATION_PLAN=>$m102['publication_plan']);
$h102=PeerTube_Migration_Execution::migration_plan_sha256($m102);$GLOBALS['awvp_r468_meta'][102][Video_Meta::PEERTUBE_MIGRATION_EXECUTION]=array('version'=>1,'video_id'=>102,'migration_plan_sha256'=>$h102,'backend_id'=>'pt-primary','channel_id'=>'77','anchor_post_id'=>12,'status'=>'prepared','lifecycle_generation'=>0,'task_id'=>0,'started_at'=>2000,'updated_at'=>2000,'promoted_at'=>0,'dispatched_at'=>0);
$recover=$executor->execute(102,2030);$a(PeerTube_Migration_Executor::APPLIED===$recover['status'],'Prepared partial promotion did not recover forward.');
$a('dispatched'===($GLOBALS['awvp_r468_meta'][102][Video_Meta::PEERTUBE_MIGRATION_EXECUTION]['status']??''),'Recovered migration did not dispatch.');

// A live lock serializes explicit execution attempts.
$lock_name='argentwolf_video_processor_migration_execute_lock_'.hash('sha256','103');
$GLOBALS['awvp_r468_posts'][13]=(object)array('ID'=>13,'post_type'=>'post','post_status'=>'draft','post_author'=>7);$GLOBALS['awvp_r468_posts'][23]=(object)array('ID'=>23,'post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'video/mp4');$GLOBALS['awvp_r468_posts'][103]=(object)array('ID'=>103,'post_type'=>'argent_video_asset','post_status'=>'publish');$GLOBALS['awvp_r468_meta'][103]=array(Video_Meta::ATTACHMENT_ID=>23,Video_Meta::ORIGIN_POST_ID=>13,Video_Meta::SOURCE_STATE=>'present',Video_Meta::PEERTUBE_MIGRATION_PLAN=>$make_ready(103,23,13));$GLOBALS['awvp_r468_options'][$lock_name]=array('token'=>'other','created_at'=>2040);
$busy=$executor->execute(103,2050);$a(PeerTube_Migration_Executor::BUSY===$busy['status'],'Live migration execution lock did not serialize attempt.');

// A catalog from a previous managed-secret generation cannot authorize one-way commitment.
$GLOBALS['awvp_r468_posts'][14]=(object)array('ID'=>14,'post_type'=>'post','post_status'=>'draft','post_author'=>7);
$GLOBALS['awvp_r468_posts'][24]=(object)array('ID'=>24,'post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'video/mp4');
$GLOBALS['awvp_r468_posts'][104]=(object)array('ID'=>104,'post_type'=>'argent_video_asset','post_status'=>'publish');
$GLOBALS['awvp_r468_meta'][104]=array(Video_Meta::ATTACHMENT_ID=>24,Video_Meta::ORIGIN_POST_ID=>14,Video_Meta::SOURCE_STATE=>'present',Video_Meta::PEERTUBE_MIGRATION_PLAN=>$make_ready(104,24,14));
$wrong_generation=new PeerTube_Migration_Executor($registry,new PeerTube_Publication_Catalog_Store(),$defaults,$sync,static fn(array $descriptor,string $backend_id):int=>4);
$generation_refused=$wrong_generation->execute(104,2060);
$a(PeerTube_Migration_Executor::REFUSED===$generation_refused['status']&&!metadata_exists('post',104,Video_Meta::PEERTUBE_MIGRATION_EXECUTION),'Previous-generation provider catalog authorized migration commitment.');

// Source identity is revalidated as an actual video immediately before commitment.
$GLOBALS['awvp_r468_posts'][15]=(object)array('ID'=>15,'post_type'=>'post','post_status'=>'draft','post_author'=>7);
$GLOBALS['awvp_r468_posts'][25]=(object)array('ID'=>25,'post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'image/jpeg');
$GLOBALS['awvp_r468_posts'][105]=(object)array('ID'=>105,'post_type'=>'argent_video_asset','post_status'=>'publish');
$GLOBALS['awvp_r468_meta'][105]=array(Video_Meta::ATTACHMENT_ID=>25,Video_Meta::ORIGIN_POST_ID=>15,Video_Meta::SOURCE_STATE=>'present',Video_Meta::PEERTUBE_MIGRATION_PLAN=>$make_ready(105,25,15));
$source_refused=$executor->execute(105,2070);
$a(PeerTube_Migration_Executor::REFUSED===$source_refused['status']&&!metadata_exists('post',105,Video_Meta::PEERTUBE_MIGRATION_EXECUTION),'Non-video replacement source authorized migration commitment.');

$source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Migration_Executor.php');
foreach(array('wp_remote_','PeerTube_Api_Client','PeerTube_Staged_Upload','Remote_Asset_Repository','Video_Serving_Authority','delete_attachment','wp_delete_post','wp_publish_post') as $forbidden){$a(!str_contains($source,$forbidden),'Migration executor acquired forbidden direct authority: '.$forbidden);}
echo "PeerTube migration executor test passed.\n";
}
