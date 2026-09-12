<?php
/** Dependency-free tests for explicit local delivery rebuild from a retained source. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Video_Meta {
        public const ATTACHMENT_ID='_argent_video_attachment_id';
        public const LOCAL_DELIVERY_REBUILD_REQUEST='_argentwolf_video_processor_local_delivery_rebuild_request';
        public static function sanitize_positive_id(mixed $v):int{return is_int($v)&&$v>0?$v:(is_string($v)&&preg_match('/^[1-9][0-9]*$/D',$v)?(int)$v:0);}
    }
    final class Video_Post_Type { public const POST_TYPE='argent_video_asset'; }
    final class Video_Block_Editor_Service { public const ATTACHMENT_ASSET_META='_argent_video_asset_id'; }
    final class Backend_Registry { public const LOCAL_ID='local'; }
    final class Settings {
        public static function current_job_profile():string{return 'dual';}
        public static function job_has_hls(string $profile):bool{return str_contains($profile,'+hls')||'adaptive-only'===$profile;}
    }
    final class Local_Retention_Service {
        public static bool $blocked=false;
        public static function attachment_rebuild_blocked(int $attachment_id):bool{unset($attachment_id);return self::$blocked;}
    }
    final class WordPress_Source_File {
        public static string $path='';
        public static function capture(int $attachment_id):array{return $attachment_id===12&&is_file(self::$path)?array('version'=>1,'relative_path'=>'2026/09/source.mp4','size'=>(int)filesize(self::$path),'device'=>1,'inode'=>2,'mtime'=>(int)filemtime(self::$path),'ctime'=>(int)filectime(self::$path)):array();}
        public static function sanitize_identity(mixed $v):array{return is_array($v)&&1===($v['version']??null)&&isset($v['relative_path'],$v['size'],$v['device'],$v['inode'],$v['mtime'],$v['ctime'])?$v:array();}
        public static function matches(int $attachment_id,array $identity):bool{return self::capture($attachment_id)===$identity;}
    }
    final class Job_Repository {
        public array $enqueued=[]; public ?\Closure $on_enqueue=null; public ?array $existing=null; public ?array $last_job=null;
        public function find_by_attachment(int $attachment_id):?array{unset($attachment_id);return $this->existing;}
        public function find(int $job_id):?array{return 44===$job_id?$this->last_job:null;}
        public function enqueue(int $attachment_id,string $source,string $signature,string $profile,bool $force=false):int{
            $this->enqueued=array($attachment_id,$source,$signature,$profile,$force);$job=array('id'=>44,'attachment_id'=>$attachment_id,'source_signature'=>$signature,'profile'=>$profile,'status'=>'queued');$this->last_job=$job;
            if($this->on_enqueue instanceof \Closure){($this->on_enqueue)($job);$this->last_job['status']='processing';}return 44;
        }
    }
    final class Worker_Launcher { public int $dispatches=0; public function dispatch():void{$this->dispatches++;} }
    final class PeerTube_Event_Repository {
        public array $events=[];
        public function record(int $video_id,int $step,string $code,string $severity,string $message,int $now,int $task_id=0,string $operation_id='',int $remote_asset_id=0,string $backend_id='',int $http_status=0,string $automatic_action='',string $operator_action='',array $context=array()):bool{
            $this->events[]=compact('video_id','step','code','severity','message','now','task_id','operation_id','remote_asset_id','backend_id','http_status','automatic_action','operator_action','context');return true;
        }
    }
}

namespace {
    $GLOBALS['awvp_rebuild_meta']=array();
    $GLOBALS['awvp_rebuild_posts']=array(21=>(object)array('ID'=>21,'post_type'=>'argent_video_asset','post_status'=>'publish'));
    function get_post(int $id):?object{return $GLOBALS['awvp_rebuild_posts'][$id]??null;}
    function get_post_meta(int $id,string $key,bool $single=false):mixed{unset($single);return $GLOBALS['awvp_rebuild_meta'][$id][$key]??'';}
    function update_post_meta(int $id,string $key,mixed $value):bool{$GLOBALS['awvp_rebuild_meta'][$id][$key]=$value;return true;}
    function delete_post_meta(int $id,string $key):bool{unset($GLOBALS['awvp_rebuild_meta'][$id][$key]);return true;}
    function metadata_exists(string $type,int $id,string $key):bool{unset($type);return array_key_exists($key,$GLOBALS['awvp_rebuild_meta'][$id]??array());}
    function get_attached_file(int $id,bool $unfiltered=false):string|false{unset($unfiltered);return 12===$id?\ArgentVideo\WordPress_Source_File::$path:false;}
    function wp_normalize_path(string $path):string{return str_replace('\\','/',$path);}

    require_once dirname(__DIR__).'/includes/Local_Delivery_Rebuild_Request.php';
    require_once dirname(__DIR__).'/includes/Local_Delivery_Rebuild_Service.php';

    use ArgentVideo\Job_Repository;
    use ArgentVideo\Local_Delivery_Rebuild_Request as Request;
    use ArgentVideo\Local_Delivery_Rebuild_Service as Service;
    use ArgentVideo\Local_Retention_Service;
    use ArgentVideo\PeerTube_Event_Repository;
    use ArgentVideo\Video_Block_Editor_Service;
    use ArgentVideo\Video_Meta;
    use ArgentVideo\Worker_Launcher;
    use ArgentVideo\WordPress_Source_File;

    $a=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}};
    $source=tempnam(sys_get_temp_dir(),'awvp-rebuild-');$a(is_string($source),'Could not create source fixture.');file_put_contents($source,str_repeat('x',4096));clearstatcache(true,$source);WordPress_Source_File::$path=$source;
    $GLOBALS['awvp_rebuild_meta'][21][Video_Meta::ATTACHMENT_ID]=12;
    $GLOBALS['awvp_rebuild_meta'][12][Video_Block_Editor_Service::ATTACHMENT_ASSET_META]=21;

    $jobs=new Job_Repository();$launcher=new Worker_Launcher();$events=new PeerTube_Event_Repository();$service=new Service($jobs,$launcher,$events);
    // Simulate a detached worker claiming immediately after enqueue, before request() can write QUEUED.
    $jobs->on_enqueue=static function(array $job)use($service,$a):void{$a($service->authorize_claimed_job($job,1001),'Prepared rebuild authorization did not safely cover the enqueue/worker race.');};
    $result=$service->request(21,7,1000);
    $a(Service::APPLIED===($result['status']??''),'Explicit local rebuild was not queued.');
    $a(44===(int)($result['job_id']??0),'Unexpected rebuild job ID.');
    $a(1===$launcher->dispatches,'Detached worker launcher was not dispatched exactly once.');
    $a('dual+hls'===($jobs->enqueued[3]??''),'Rebuild did not force an HLS-capable profile.');
    $a(true===($jobs->enqueued[4]??false),'Rebuild must explicitly force the existing attachment job row back to queued work.');
    $record=Request::sanitize(get_post_meta(21,Video_Meta::LOCAL_DELIVERY_REBUILD_REQUEST,true));
    $a(Request::PROCESSING===($record['status']??'')&&44===(int)($record['job_id']??0),'Racing worker did not durably adopt the exact prepared request.');
    $a(7===(int)($record['requested_by']??0),'Rebuild operator identity was not preserved.');

    $job=array('id'=>44,'attachment_id'=>12,'source_signature'=>$record['source_signature'],'profile'=>$record['profile']);
    $a($service->matches_active_request($job),'Active rebuild request no longer matches its exact claimed job.');
    $service->complete_claimed_job($job,1010);
    $record=Request::sanitize(get_post_meta(21,Video_Meta::LOCAL_DELIVERY_REBUILD_REQUEST,true));
    $a(Request::COMPLETE===($record['status']??'')&&1010===(int)($record['completed_at']??0),'Completed rebuild was not durably recorded.');
    $a($service->source_available(21),'Retained source should remain available after rebuild completion.');

    Local_Retention_Service::$blocked=true;
    $blocked=$service->request(21,7,1020);
    $a(Service::REFUSED===($blocked['status']??''),'Retention fence did not refuse a rebuild request.');

    $worker=file_get_contents(dirname(__DIR__).'/includes/Worker.php');
    $a(is_string($worker)&&str_contains($worker,'matches_active_request($job)')&&str_contains($worker,'authorize_claimed_job($job, time())'),'Worker does not identify and revalidate explicit local rebuild authorization after claiming a job.');
    $a(str_contains($worker,'complete_claimed_job($job, time())'),'Worker does not finalize successful rebuild authorization.');
    $a(str_contains($worker,'fail_claimed_job($job, time(), $message)'),'Worker does not finalize failed rebuild authorization.');

    @unlink($source);
    fwrite(STDOUT,"Local delivery rebuild service tests passed.\n");
}
