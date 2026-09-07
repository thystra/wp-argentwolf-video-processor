<?php
/** Dependency-free orchestration matrix for R46.5b publication tasks. */
declare(strict_types=1);

namespace {
    $GLOBALS['awvp_pub_meta']=array();
    $GLOBALS['awvp_pub_posts']=array();
    $GLOBALS['awvp_pub_options']=array();
    function sanitize_text_field(mixed $v): string { return is_string($v)?$v:''; }
    function wp_parse_url(string $url): array|false { $v=parse_url($url); return is_array($v)?$v:false; }
    function wp_json_encode(mixed $v,int $flags=0,int $depth=512): string|false { return json_encode($v,$flags,$depth); }
    function get_post_meta(int $id,string $k,bool $single=true): mixed { return $GLOBALS['awvp_pub_meta'][$id][$k] ?? ''; }
    function metadata_exists(string $type,int $id,string $k): bool { return array_key_exists($k,$GLOBALS['awvp_pub_meta'][$id]??array()); }
    function update_post_meta(int $id,string $k,mixed $v): bool { $GLOBALS['awvp_pub_meta'][$id][$k]=$v; return true; }
    function get_post(int $id): mixed { return $GLOBALS['awvp_pub_posts'][$id]??null; }
    function get_current_user_id(): int { return (int)($GLOBALS['awvp_pub_current_user']??0); }
    function get_post_field(string $field,int $id): mixed { $p=$GLOBALS['awvp_pub_posts'][$id]??null; return is_object($p)?($p->{$field}??''):''; }
    function get_option(string $k,mixed $d=false): mixed { return $GLOBALS['awvp_pub_options'][$k]??$d; }
    function add_option(string $k,mixed $v,string $deprecated='',bool $autoload=true): bool { if(array_key_exists($k,$GLOBALS['awvp_pub_options']))return false;$GLOBALS['awvp_pub_options'][$k]=$v;return true; }
    function delete_option(string $k): bool { if(!array_key_exists($k,$GLOBALS['awvp_pub_options']))return false;unset($GLOBALS['awvp_pub_options'][$k]);return true; }
}

namespace ArgentVideo {
    final class PeerTube_Publication_Synchronizer { public const TASK_TYPE='peertube_publication_sync'; }
    final class Backend_Registry {
        public const LOCAL_ID='local'; public const PEERTUBE_TYPE='peertube';
        public function __construct(public array $descriptor){}
        public function get(string $id): ?array { return $id===($this->descriptor['id']??null)?$this->descriptor:null; }
    }
    final class Managed_Backend_Secret_Store {
        public function __construct(public ?array $secret){}
        public function read(string $ref,string $backend): ?array { return $this->secret; }
    }
    final class PeerTube_Publication_Catalog_Store {
        public function __construct(public ?array $catalog){}
        public function get_for_context(string $backend,string $origin,int $generation): ?array { return $this->catalog; }
    }
    final class Video_Publishing_Defaults_Store {
        public function __construct(public ?array $settings=array()){}
        public function get(): ?array { return $this->settings; }
    }
    final class PeerTube_Publication_Thumbnail {
        public const MAX_BYTES=10485760;
        public static function capture(int $id): ?array { return $GLOBALS['awvp_pub_thumbnail']??null; }
    }
    final class Video_Meta {
        public const DESTINATION='_argent_video_destination';
        public const PEERTUBE_PUBLICATION_PLAN='_argent_video_peertube_publication_plan';
        public const PEERTUBE_PUBLICATION_LIFECYCLE='_argent_video_peertube_publication_lifecycle';
        public const PEERTUBE_PUBLICATION_EXECUTION='_argent_video_peertube_publication_execution';
    }
    final class Task_Repository {
        public const APPLIED='applied'; public const PRESENT='present'; public const CONFLICT='conflict'; public const INDETERMINATE='indeterminate';
        public array $transitions=array(); public array $enqueues=array(); public string $enqueue_status=self::APPLIED;
        public function complete(int $id,string $lock,int $now): string {$this->transitions[]=array('complete',$id,$lock,$now);return self::APPLIED;}
        public function fail(int $id,string $lock,string $message,int $now): string {$this->transitions[]=array('fail',$id,$lock,$message,$now);return self::APPLIED;}
        public function reschedule(int $id,string $lock,int $run_after,string $message,int $now): string {$this->transitions[]=array('reschedule',$id,$lock,$run_after,$message,$now);return self::APPLIED;}
        public function enqueue(string $type,int $video_id,?int $asset,string $backend,string $key,array $payload,int $run_after,int $now,int $priority,int $max_attempts): array {
            $this->enqueues[]=compact('type','video_id','asset','backend','key','payload','run_after','now','priority','max_attempts');
            return array('status'=>$this->enqueue_status,'task_id'=>900+count($this->enqueues));
        }
    }
    final class PeerTube_Staged_Upload_State_Machine {
        public const PHASE_FAILED='failed'; public const PHASE_READY_VERIFIED='ready_verified'; public const PHASE_READY='ready';
        public static function valid(mixed $r): bool { return is_array($r)&&isset($r['phase'],$r['remote_asset_id'],$r['remote_identity'])&&is_array($r['remote_identity']); }
    }
    final class PeerTube_Staged_Upload_Operation_Store {
        public function __construct(public array $records=array()){}
        public function get(string $id): ?array { return $this->records[$id]??null; }
    }
    final class PeerTube_Staged_Upload_Service {
        public const STATUS_ADVANCED='advanced'; public array $calls=array(); public array $next=array('status'=>'advanced','operation_id'=>'upload_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        public function begin(int $video,string $backend,string $path,string $name,int $actor,int $now,?string $destination=null): array {$this->calls[]=compact('video','backend','path','name','actor','now','destination');return $this->next;}
    }
    final class PeerTube_Upload_Task_Coordinator {
        public array $calls=array(); public string $status=Task_Repository::APPLIED;
        public function enqueue_upload(string $op,int $now): array {$this->calls[]=array($op,$now);return array('status'=>$this->status,'task_id'=>700);}
    }
    final class PeerTube_Publication_Staging_Service {
        public array $calls=array(); public array $next=array('status'=>'ready','path'=>'/managed/source.mp4','attachment_id'=>20);
        public function stage(int $video): array {$this->calls[]=$video;return $this->next;}
    }
    interface PeerTube_Publication_Asset_Store {
        public function find(int $id): ?array;
        public function record_publication_observation(int $id,int $video,string $backend,string $uuid,string $channel,string $privacy,int $now): string;
    }
    final class PeerTube_Remote_Asset_Store { public const APPLIED='applied'; public const PRESENT='present'; }
    final class FakePublicationAssetStore implements PeerTube_Publication_Asset_Store {
        public array $calls=array(); public string $status='applied';
        public function find(int $id): ?array { return null; }
        public function record_publication_observation(int $id,int $video,string $backend,string $uuid,string $channel,string $privacy,int $now): string {$this->calls[]=compact('id','video','backend','uuid','channel','privacy','now');return $this->status;}
    }
    interface PeerTube_Publication_Mutation_Api {
        public function update_publication(string $access_token,string $video_uuid,array $manifest,string $privacy_id,?array $thumbnail=null): array;
        public function update_privacy(string $access_token,string $video_uuid,string $privacy_id): array;
        public function video_status(string $access_token,string $video_uuid): array;
        public function origin(): string;
    }
    final class FakePublicationApi implements PeerTube_Publication_Mutation_Api {
        public array $publication_calls=array(); public array $privacy_calls=array(); public array $status_calls=array(); public string $remote_privacy='3'; public string $channel='41'; public string $uuid='123e4567-e89b-42d3-a456-426614174000'; public bool $mutate_ok=true; public $after_publication=null;
        public function origin(): string {return 'https://video.example.org';}
        public function update_publication(string $token,string $uuid,array $manifest,string $privacy,?array $thumbnail=null): array {$this->publication_calls[]=compact('token','uuid','manifest','privacy','thumbnail'); if(is_callable($this->after_publication))($this->after_publication)(); if($this->mutate_ok)$this->remote_privacy=$privacy; return array('ok'=>$this->mutate_ok,'data'=>array('updated'=>true),'error'=>null);}
        public function update_privacy(string $token,string $uuid,string $privacy): array {$this->privacy_calls[]=compact('token','uuid','privacy'); if($this->mutate_ok)$this->remote_privacy=$privacy; return array('ok'=>$this->mutate_ok,'data'=>array('updated'=>true),'error'=>null);}
        public function video_status(string $token,string $uuid): array {$this->status_calls[]=compact('token','uuid'); return array('ok'=>true,'data'=>array('id'=>55,'uuid'=>$this->uuid,'state_id'=>1,'privacy_id'=>$this->remote_privacy,'channel_id'=>$this->channel,'embed_path'=>'/videos/embed/'.$this->uuid,'is_live'=>false),'error'=>null);}
    }
}

namespace {
    require_once dirname(__DIR__).'/includes/Backend_Identity.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Origin.php';
    require_once dirname(__DIR__).'/includes/Video_Destination.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Publication_Plan.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Publication_Lifecycle.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Publication_Catalog.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Publication_Manifest.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Publication_Execution.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Publication_Task_Coordinator.php';

    use ArgentVideo\Backend_Registry; use ArgentVideo\Managed_Backend_Secret_Store; use ArgentVideo\PeerTube_Publication_Catalog_Store;
    use ArgentVideo\Video_Publishing_Defaults_Store; use ArgentVideo\Task_Repository; use ArgentVideo\PeerTube_Staged_Upload_Operation_Store;
    use ArgentVideo\PeerTube_Staged_Upload_Service; use ArgentVideo\PeerTube_Upload_Task_Coordinator; use ArgentVideo\PeerTube_Publication_Staging_Service;
    use ArgentVideo\FakePublicationAssetStore; use ArgentVideo\FakePublicationApi; use ArgentVideo\PeerTube_Publication_Task_Coordinator as Coordinator;
    use ArgentVideo\PeerTube_Publication_Plan as Plan; use ArgentVideo\PeerTube_Publication_Lifecycle as Lifecycle; use ArgentVideo\PeerTube_Publication_Manifest as Manifest;
    use ArgentVideo\PeerTube_Publication_Execution as Execution; use ArgentVideo\Video_Meta;

    $assert=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
    $now=2000; $video=100; $anchor=10; $uuid='123e4567-e89b-42d3-a456-426614174000';
    $plan=array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41','title'=>'Reviewed title','description_markdown'=>'Description','tags'=>array('alpha'),'support'=>array('mode'=>'none','preset_id'=>'','markdown'=>''),'final_privacy_id'=>'1','licence_id'=>'2','category_id'=>'3','language'=>'en','thumbnail_attachment_id'=>0,'download_enabled'=>true,'originally_published_at'=>'','comments_policy'=>'enabled','moderation'=>array('reviewed'=>true,'sensitive'=>false,'reason'=>'','violent'=>false,'sexually_explicit'=>false),'embed'=>array('restricted'=>false,'domains'=>array()),'review'=>array('title'=>true,'channel'=>true,'tags'=>true,'privacy'=>true,'moderation'=>true),'dispatch_policy'=>Plan::DISPATCH_SEND_NOW,'release_policy'=>Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,'anchor_post_id'=>$anchor);
    $plan=Plan::sanitize($plan); $assert(array()!==$plan,'Plan fixture invalid.'); $planHash=Lifecycle::plan_sha256($plan);
    $catalog=array('version'=>1,'backend_id'=>'pt-primary','origin'=>'https://video.example.org','secret_generation'=>7,'server_version'=>'8.3.0','refreshed_at'=>1000,'stale'=>false,'stale_since'=>null,'stale_reason'=>'','channels'=>array(array('id'=>'41','name'=>'main','display_name'=>'Main','authority'=>'owned')),'privacies'=>array('1'=>'Public','2'=>'Unlisted','3'=>'Private','4'=>'Internal'),'licences'=>array('2'=>'CC BY'),'categories'=>array('3'=>'People'),'languages'=>array('en'=>'English'),'capabilities'=>array('sensitive_content'=>true,'sensitive_flags'=>true,'password_privacy'=>false));
    $descriptor=array('id'=>'pt-primary','type'=>'peertube','label'=>'PeerTube','state'=>'active','default_destination'=>'41','secret_ref'=>'managed:pt-primary','config_version'=>1,'config'=>array('origin'=>'https://video.example.org'));
    $secret=array('access_token'=>'access-token','refresh_token'=>'refresh-token','access_expires_at'=>999999,'refresh_expires_at'=>999999,'generation'=>7);
    $makeLife=static function(int $generation,string $status,string $target,bool $upload,bool $reveal) use($planHash,$anchor): array { return array('version'=>1,'generation'=>$generation,'backend_id'=>'pt-primary','anchor_post_id'=>$anchor,'plan_sha256'=>$planHash,'dispatch_policy'=>Plan::DISPATCH_SEND_NOW,'wordpress_status'=>$status,'upload_authorized'=>$upload,'reveal_authorized'=>$reveal,'target_privacy_id'=>$target,'task_pending'=>true,'updated_at'=>1900+$generation); };
    $task=static function(int $id,string $type,int $generation=1) use($video,$planHash): array { return array('id'=>$id,'task_type'=>$type,'video_post_id'=>$video,'lock_token'=>sprintf('00000000-0000-4000-8000-%012d',$id),'payload_json'=>json_encode(array('version'=>1,'generation'=>$generation,'plan_sha256'=>$planHash),JSON_THROW_ON_ERROR)); };
    $reset=static function(array $life,string $postStatus='draft') use($video,$anchor,$plan): void { $GLOBALS['awvp_pub_meta']=array($video=>array(Video_Meta::PEERTUBE_PUBLICATION_PLAN=>$plan,Video_Meta::DESTINATION=>array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41'),Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE=>$life)); $GLOBALS['awvp_pub_posts']=array($video=>(object)array('ID'=>$video,'post_author'=>9,'post_status'=>'publish'),$anchor=>(object)array('ID'=>$anchor,'post_author'=>7,'post_status'=>$postStatus)); $GLOBALS['awvp_pub_options']=array(); $GLOBALS['awvp_pub_current_user']=0; };
    $factory=static function(?array $catalogOverride=null) use($descriptor,$secret,$catalog): array {
        $tasks=new Task_Repository(); $operations=new PeerTube_Staged_Upload_Operation_Store(); $upload=new PeerTube_Staged_Upload_Service(); $uploadTasks=new PeerTube_Upload_Task_Coordinator(); $staging=new PeerTube_Publication_Staging_Service(); $assets=new FakePublicationAssetStore(); $api=new FakePublicationApi();
        $coord=new Coordinator($tasks,$operations,$upload,$uploadTasks,$staging,$assets,new Backend_Registry($descriptor),new Managed_Backend_Secret_Store($secret),new PeerTube_Publication_Catalog_Store(func_num_args()? $catalogOverride:$catalog),new Video_Publishing_Defaults_Store(array()),static fn(string $origin)=>$api);
        return compact('coord','tasks','operations','upload','uploadTasks','staging','assets','api');
    };

    // Sync: durable private upload handoff; post author is used, never invented user 1.
    $reset($makeLife(1,'draft','3',true,false),'draft'); $x=$factory(); $r=$x['coord']->advance_claimed($task(1,Coordinator::TASK_SYNC),$now);
    $assert(Coordinator::STATUS_COMPLETE===$r['status']&&'private_upload_handoff'===$r['service_status'],'Sync did not complete private upload handoff.');
    $assert(1===count($x['staging']->calls)&&1===count($x['upload']->calls)&&7===$x['upload']->calls[0]['actor']&&'41'===$x['upload']->calls[0]['destination'],'Sync did not use staged source/reviewed channel/actual post author.');
    $execution=$GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]??null; $assert(is_array($execution)&&'upload_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'===($execution['operation_id']??''),'Sync did not persist upload identity.');
    $assert(1===count($x['uploadTasks']->calls)&&1===count($x['tasks']->enqueues)&&Coordinator::TASK_FINALIZE===$x['tasks']->enqueues[0]['type']&&720===$x['tasks']->enqueues[0]['max_attempts'],'Sync did not durably enqueue upload/finalize with long verification horizon.');

    // Stale generation and lock contention do no remote/staging work.
    $reset($makeLife(2,'draft','3',true,false),'draft'); $x=$factory(); $stale=$x['coord']->advance_claimed($task(2,Coordinator::TASK_SYNC,1),$now); $assert(Coordinator::STATUS_COMPLETE===$stale['status']&&'stale'===$stale['service_status']&&0===count($x['staging']->calls),'Stale sync generation performed work.');
    $reset($makeLife(1,'draft','3',true,false),'draft'); $lockName='argent_video_processor_publication_execution_lock_'.hash('sha256',(string)$video); $GLOBALS['awvp_pub_options'][$lockName]=array('version'=>1,'token'=>str_repeat('a',32),'created_at'=>$now); $x=$factory(); $busy=$x['coord']->advance_claimed($task(3,Coordinator::TASK_SYNC),$now); $assert(Coordinator::STATUS_REQUEUED===$busy['status']&&0===count($x['staging']->calls),'Live per-video lock did not serialize publication execution.');

    // Transient provider authority reschedules instead of terminally failing.
    $reset($makeLife(1,'draft','3',true,false),'draft'); $x=$factory(null); $transient=$x['coord']->advance_claimed($task(4,Coordinator::TASK_SYNC),$now); $assert(Coordinator::STATUS_REQUEUED===$transient['status'],'Transient missing catalog did not reschedule.');

    // Finalize a ready private upload while actually published: final privacy is applied, verified, and recorded.
    $reset($makeLife(1,'publish','1',true,true),'publish'); $manifest=Manifest::build($plan,$catalog,array()); $exec=Execution::with_operation(Execution::create($manifest,1900),'upload_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1910); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec;
    $ready=array('phase'=>'ready_verified','remote_asset_id'=>55,'remote_identity'=>array('uuid'=>$uuid)); $x=$factory(); $x['operations']->records[$exec['operation_id']]=$ready; $x['api']->remote_privacy='3';
    $done=$x['coord']->advance_claimed($task(5,Coordinator::TASK_FINALIZE),$now); $assert(Coordinator::STATUS_COMPLETE===$done['status']&&'verified'===$done['service_status'],'Published finalization did not verify.');
    $assert(1===count($x['api']->publication_calls)&&'1'===$x['api']->publication_calls[0]['privacy']&&0===count($x['api']->privacy_calls),'Published finalization used unexpected mutation path.');
    $assert(1===count($x['assets']->calls)&&'1'===$x['assets']->calls[0]['privacy'],'Verified final privacy was not recorded locally.');
    $saved=Execution::sanitize($GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]??null); $assert(Manifest::sha256($manifest)===($saved['applied_manifest_sha256']??''),'Applied manifest was not durably recorded.');

    // If WordPress loses publish authority during the PUT, correct immediately to Private and verify it.
    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec; $x=$factory(); $x['operations']->records[$exec['operation_id']]=$ready; $x['api']->remote_privacy='3';
    $x['api']->after_publication=static function() use($video,$anchor,$makeLife): void { $GLOBALS['awvp_pub_posts'][$anchor]->post_status='draft'; $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]=$makeLife(2,'draft','3',true,false); };
    $corrected=$x['coord']->advance_claimed($task(6,Coordinator::TASK_FINALIZE),$now); $assert(Coordinator::STATUS_COMPLETE===$corrected['status']&&'corrected_private'===$corrected['service_status'],'Post-PUT WordPress authority loss was not corrected private.');
    $assert(1===count($x['api']->publication_calls)&&1===count($x['api']->privacy_calls)&&'3'===$x['api']->privacy_calls[0]['privacy'],'Emergency correction did not use one privacy-only mutation.');
    $assert('3'===$x['assets']->calls[0]['privacy'],'Private correction was not recorded locally.');

    // Finalizer waits for upload readiness and refuses uncertain mutation replay.
    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec; $x=$factory(); $x['operations']->records[$exec['operation_id']]=array('phase'=>'ready','remote_asset_id'=>0,'remote_identity'=>array('uuid'=>'')); $wait=$x['coord']->advance_claimed($task(7,Coordinator::TASK_FINALIZE),$now); $assert(Coordinator::STATUS_REQUEUED===$wait['status']&&0===count($x['api']->publication_calls),'Finalizer mutated before upload readiness.');
    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec; $x=$factory(); $x['operations']->records[$exec['operation_id']]=$ready; $x['api']->mutate_ok=false; $uncertain=$x['coord']->advance_claimed($task(8,Coordinator::TASK_FINALIZE),$now); $assert(Coordinator::STATUS_FAILED===$uncertain['status']&&'mutation_indeterminate'===$uncertain['service_status']&&1===count($x['api']->publication_calls),'Indeterminate publication mutation was automatically replayed or misclassified.');

    $source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Publication_Task_Coordinator.php'); foreach(array('wp_update_post(','wp_publish_post(','transition_post_status(','Renderer','SERVING','cutover') as $needle){$assert(!str_contains($source,$needle),'R46.5b coordinator acquired forbidden WordPress publish/serving authority: '.$needle);}
    fwrite(STDOUT,"R46.5b publication task coordinator tests passed.\n");
}
