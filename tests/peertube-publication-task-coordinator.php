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
        public function get_fresh(string $id): ?array { return $this->get($id); }
    }
    final class Managed_Backend_Secret_Store {
        public function __construct(public ?array $secret){}
        public function read(string $ref,string $backend): ?array { return $this->secret; }
    }
    final class PeerTube_Publication_Catalog_Store {
        public function __construct(public ?array $catalog){}
        public function get_for_context(string $backend,string $origin,int $generation): ?array { return $this->catalog; }
        public function get_for_context_fresh(string $backend,string $origin,int $generation): ?array { return $this->get_for_context($backend,$origin,$generation); }
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
    final class Video_Serving_Authority {
        public static function privacy_name(string $id): string { return match($id){'1'=>'public','2'=>'unlisted','3'=>'private','4'=>'internal',default=>''}; }
    }
    final class PeerTube_Serving_Cutover_Service {
        public const APPLIED='applied'; public const PRESENT='present'; public const LOCAL='local'; public const REFUSED='refused'; public const INDETERMINATE='indeterminate';
        public array $calls=array(); public string $status=self::PRESENT;
        public function reconcile(int $video,int $now): string { $this->calls[]=array($video,$now); return $this->status; }
    }
    final class PeerTube_Publication_Authority_Repair {
        public array $calls=array();
        public function repair(string $backend_id,int $now): array { $this->calls[]=array($backend_id,$now); return array('status'=>'complete'); }
    }
    final class Serving_Viability {
        public const HEALTHY='healthy'; public const PROCESSING='processing'; public const MISSING='missing';
    }
    final class Remote_Publication_Health_Service {
        public array $calls=array();
        public array $next=array('recorded'=>true,'viability_status'=>Serving_Viability::HEALTHY,'reason_code'=>'','message'=>'','http_status'=>200);
        public function probe_and_record(array $asset,int $now,bool $initial_verified=false,array $processing_context=array()): array {
            $this->calls[]=array('asset'=>$asset,'now'=>$now,'initial_verified'=>$initial_verified,'processing_context'=>$processing_context);
            return $this->next;
        }
    }
    final class Task_Repository {
        public const APPLIED='applied'; public const PRESENT='present'; public const CONFLICT='conflict'; public const INDETERMINATE='indeterminate';
        public array $transitions=array(); public array $enqueues=array(); public string $enqueue_status=self::APPLIED;
        public function complete(int $id,string $lock,int $now): string {$this->transitions[]=array('complete',$id,$lock,$now);return self::APPLIED;}
        public function fail(int $id,string $lock,string $message,int $now): string {$this->transitions[]=array('fail',$id,$lock,$message,$now);return self::APPLIED;}
        public function reschedule(int $id,string $lock,int $run_after,string $message,int $now): string {$this->transitions[]=array('reschedule',$id,$lock,$run_after,$message,$now);return self::APPLIED;}
        public function defer(int $id,string $lock,int $run_after,string $message,int $now): string {$this->transitions[]=array('defer',$id,$lock,$run_after,$message,$now);return self::APPLIED;}
        public function enqueue(string $type,int $video_id,?int $asset,string $backend,string $key,array $payload,int $run_after,int $now,int $priority,int $max_attempts): array {
            $this->enqueues[]=compact('type','video_id','asset','backend','key','payload','run_after','now','priority','max_attempts');
            return array('status'=>$this->enqueue_status,'task_id'=>900+count($this->enqueues));
        }
    }
    final class PeerTube_Staged_Upload_State_Machine {
        public const PHASE_FAILED='failed'; public const PHASE_UPLOAD_INDETERMINATE='upload_indeterminate'; public const PHASE_OPERATOR_ABANDONED='operator_abandoned'; public const PHASE_READY_VERIFIED='ready_verified'; public const PHASE_READY='ready';
        public static function valid(mixed $r): bool { return is_array($r)&&isset($r['phase'],$r['remote_asset_id'],$r['remote_identity'])&&is_array($r['remote_identity']); }
    }
    final class PeerTube_Staged_Upload_Operation_Store {
        public function __construct(public array $records=array()){}
        public function get(string $id): ?array { return $this->records[$id]??null; }
    }
    final class PeerTube_Staged_Upload_Service {
        public const STATUS_ADVANCED='advanced'; public array $calls=array(); public array $next=array('status'=>'advanced','operation_id'=>'upload_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        public function begin(int $video,string $backend,string $path,string $name,int $actor,int $now,?string $destination=null,?string $content_type=null): array {$this->calls[]=compact('video','backend','path','name','actor','now','destination','content_type');return $this->next;}
    }
    final class PeerTube_Upload_Task_Coordinator {
        public array $calls=array(); public string $status=Task_Repository::APPLIED;
        public function enqueue_upload(string $op,int $now): array {$this->calls[]=array($op,$now);return array('status'=>$this->status,'task_id'=>700);}
    }
    final class PeerTube_Publication_Staging_Service {
        public array $calls=array(); public array $next=array('status'=>'ready','path'=>'/managed/source.mp4','attachment_id'=>20,'content_type'=>'video/mp4');
        public function stage(int $video): array {$this->calls[]=$video;return $this->next;}
    }
    interface PeerTube_Publication_Asset_Store {
        public function find(int $id): ?array;
        public function record_publication_observation(int $id,int $video,string $backend,string $uuid,string $channel,string $privacy,int $now): string;
    }
    final class PeerTube_Remote_Asset_Store { public const APPLIED='applied'; public const PRESENT='present'; }
    final class FakePublicationAssetStore implements PeerTube_Publication_Asset_Store {
        public array $calls=array(); public array $rows=array(); public string $status='applied';
        public function find(int $id): ?array { return $this->rows[$id]??null; }
        public function record_publication_observation(int $id,int $video,string $backend,string $uuid,string $channel,string $privacy,int $now): string {$this->calls[]=compact('id','video','backend','uuid','channel','privacy','now');return $this->status;}
    }
    interface PeerTube_Publication_Mutation_Api {
        public function update_publication(string $access_token,string $video_uuid,array $manifest,string $privacy_id,?array $thumbnail=null): array;
        public function update_privacy(string $access_token,string $video_uuid,string $privacy_id): array;
        public function video_status(string $access_token,string $video_uuid): array;
        public function origin(): string;
    }
    final class FakePublicationApi implements PeerTube_Publication_Mutation_Api {
        public array $publication_calls=array(); public array $privacy_calls=array(); public array $status_calls=array(); public string $remote_privacy='3'; public string $channel='41'; public string $uuid='123e4567-e89b-42d3-a456-426614174000'; public bool $mutate_ok=true; public bool $throw_publication=false; public array $failure_error=array('status'=>'transport_error','http_status'=>0,'code'=>'transport_error'); public $after_publication=null; public ?array $remote_publication=null;
        public function origin(): string {return 'https://video.example.org';}
        public function update_publication(string $token,string $uuid,array $manifest,string $privacy,?array $thumbnail=null): array {$this->publication_calls[]=compact('token','uuid','manifest','privacy','thumbnail'); if($this->throw_publication)throw new \InvalidArgumentException('Synthetic local validation refusal.'); if(is_callable($this->after_publication))($this->after_publication)(); if($this->mutate_ok){$this->remote_privacy=$privacy;$this->remote_publication=self::publication($manifest);} return array('ok'=>$this->mutate_ok,'data'=>$this->mutate_ok?array('updated'=>true):null,'error'=>$this->mutate_ok?null:$this->failure_error);}
        public function update_privacy(string $token,string $uuid,string $privacy): array {$this->privacy_calls[]=compact('token','uuid','privacy'); if($this->mutate_ok)$this->remote_privacy=$privacy; return array('ok'=>$this->mutate_ok,'data'=>array('updated'=>true),'error'=>null);}
        public function video_status(string $token,string $uuid): array {$this->status_calls[]=compact('token','uuid'); $data=array('id'=>55,'uuid'=>$this->uuid,'state_id'=>1,'privacy_id'=>$this->remote_privacy,'channel_id'=>$this->channel,'embed_path'=>'/videos/embed/'.$this->uuid,'is_live'=>false); if(is_array($this->remote_publication))$data['publication']=$this->remote_publication; return array('ok'=>true,'data'=>$data,'error'=>null);}
        public static function publication(array $manifest): array {return array('title'=>$manifest['title'],'description_markdown'=>$manifest['description_markdown'],'tags'=>$manifest['tags'],'support_markdown'=>$manifest['support_markdown'],'licence_id'=>$manifest['licence_id'],'category_id'=>$manifest['category_id'],'language'=>$manifest['language'],'download_enabled'=>$manifest['download_enabled'],'originally_published_at'=>$manifest['originally_published_at'],'comments_policy'=>$manifest['comments_policy'],'moderation'=>array('sensitive'=>$manifest['moderation']['sensitive'],'reason'=>$manifest['moderation']['reason'],'violent'=>$manifest['moderation']['violent'],'sexually_explicit'=>$manifest['moderation']['sexually_explicit']));}
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
    $factory=static function(?array $catalogOverride=null,bool $withCutover=false,bool $withRepair=false,bool $withPublicHealth=false) use($descriptor,$secret,$catalog): array {
        $tasks=new Task_Repository(); $operations=new PeerTube_Staged_Upload_Operation_Store(); $upload=new PeerTube_Staged_Upload_Service(); $uploadTasks=new PeerTube_Upload_Task_Coordinator(); $staging=new PeerTube_Publication_Staging_Service(); $assets=new FakePublicationAssetStore(); $api=new FakePublicationApi(); $cutover=$withCutover?new \ArgentVideo\PeerTube_Serving_Cutover_Service():null; $repair=$withRepair?new \ArgentVideo\PeerTube_Publication_Authority_Repair():null; $publicHealth=$withPublicHealth?new \ArgentVideo\Remote_Publication_Health_Service():null;
        $coord=new Coordinator($tasks,$operations,$upload,$uploadTasks,$staging,$assets,new Backend_Registry($descriptor),new Managed_Backend_Secret_Store($secret),new PeerTube_Publication_Catalog_Store(func_num_args()? $catalogOverride:$catalog),new Video_Publishing_Defaults_Store(array()),static fn(string $origin)=>$api,$cutover,null,$repair,$publicHealth);
        return compact('coord','tasks','operations','upload','uploadTasks','staging','assets','api','cutover','repair','publicHealth');
    };

    // Sync: durable private upload handoff; post author is used, never invented user 1.
    $reset($makeLife(1,'draft','3',true,false),'draft'); $x=$factory(); $r=$x['coord']->advance_claimed($task(1,Coordinator::TASK_SYNC),$now);
    $assert(Coordinator::STATUS_COMPLETE===$r['status']&&'private_upload_handoff'===$r['service_status'],'Sync did not complete private upload handoff.');
    $assert(1===count($x['staging']->calls)&&1===count($x['upload']->calls)&&7===$x['upload']->calls[0]['actor']&&'41'===$x['upload']->calls[0]['destination']&&'video/mp4'===$x['upload']->calls[0]['content_type'],'Sync did not use staged source/reviewed channel/actual post author.');
    $execution=$GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]??null; $assert(is_array($execution)&&'upload_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'===($execution['operation_id']??''),'Sync did not persist upload identity.');
    $assert(1===count($x['uploadTasks']->calls)&&1===count($x['tasks']->enqueues)&&Coordinator::TASK_FINALIZE===$x['tasks']->enqueues[0]['type']&&720===$x['tasks']->enqueues[0]['max_attempts'],'Sync did not durably enqueue upload/finalize with long verification horizon.');

    // RC10 worker boundary: authority repair is invoked before fresh backend/catalog reads.
    $reset($makeLife(1,'draft','3',true,false),'draft'); $x=$factory($catalog,false,true); $r=$x['coord']->advance_claimed($task(11,Coordinator::TASK_SYNC),$now);
    $assert(Coordinator::STATUS_COMPLETE===$r['status']&&array(array('pt-primary',$now))===$x['repair']->calls,'Publication task boundary did not invoke bounded credential/catalog authority repair exactly once.');

    // Stale generation and lock contention do no remote/staging work.
    $reset($makeLife(2,'draft','3',true,false),'draft'); $x=$factory(); $stale=$x['coord']->advance_claimed($task(2,Coordinator::TASK_SYNC,1),$now); $assert(Coordinator::STATUS_COMPLETE===$stale['status']&&'stale'===$stale['service_status']&&0===count($x['staging']->calls),'Stale sync generation performed work.');
    $reset($makeLife(1,'draft','3',true,false),'draft'); $lockName='argent_video_processor_publication_execution_lock_'.hash('sha256',(string)$video); $GLOBALS['awvp_pub_options'][$lockName]=array('version'=>1,'token'=>str_repeat('a',32),'created_at'=>$now); $x=$factory(); $busy=$x['coord']->advance_claimed($task(3,Coordinator::TASK_SYNC),$now); $assert(Coordinator::STATUS_REQUEUED===$busy['status']&&0===count($x['staging']->calls),'Live per-video lock did not serialize publication execution.');

    // Transient provider authority reschedules instead of terminally failing.
    $reset($makeLife(1,'draft','3',true,false),'draft'); $x=$factory(null); $transient=$x['coord']->advance_claimed($task(4,Coordinator::TASK_SYNC),$now); $assert(Coordinator::STATUS_REQUEUED===$transient['status']&&'defer'===($x['tasks']->transitions[0][0]??''),'Transient missing catalog consumed an execution attempt instead of deferring.');

    // Finalize a ready private upload while actually published: final privacy is applied, verified, and recorded.
    $reset($makeLife(1,'publish','1',true,true),'publish'); $manifest=Manifest::build($plan,$catalog,array()); $exec=Execution::with_operation(Execution::create($manifest,1900),'upload_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1910); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec;
    $ready=array('phase'=>'ready_verified','remote_asset_id'=>55,'remote_identity'=>array('uuid'=>$uuid)); $x=$factory(); $x['operations']->records[$exec['operation_id']]=$ready; $x['api']->remote_privacy='3';
    $done=$x['coord']->advance_claimed($task(5,Coordinator::TASK_FINALIZE),$now); $assert(Coordinator::STATUS_COMPLETE===$done['status']&&'verified'===$done['service_status'],'Published finalization did not verify.');
    $assert(1===count($x['api']->publication_calls)&&'1'===$x['api']->publication_calls[0]['privacy']&&0===count($x['api']->privacy_calls),'Published finalization used unexpected mutation path.');
    $assert(1===count($x['assets']->calls)&&'1'===$x['assets']->calls[0]['privacy'],'Verified final privacy was not recorded locally.');
    $saved=Execution::sanitize($GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]??null); $assert(Manifest::sha256($manifest)===($saved['applied_manifest_sha256']??''),'Applied manifest was not durably recorded.');

    // Crash-recovery boundary: if PeerTube already exposes the complete desired
    // state, finalize converges local evidence without replaying the PUT.
    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec; $x=$factory(); $x['operations']->records[$exec['operation_id']]=$ready; $x['api']->remote_privacy='1'; $x['api']->remote_publication=FakePublicationApi::publication($manifest);
    $existing=$x['coord']->advance_claimed($task(55,Coordinator::TASK_FINALIZE),$now); $assert(Coordinator::STATUS_COMPLETE===$existing['status']&&'verified_existing'===$existing['service_status'],'Already-applied remote publication did not converge without replay.');
    $assert(0===count($x['api']->publication_calls)&&1<=count($x['api']->status_calls),'Already-applied publication replayed the consequential PUT.');

    // RC10: PeerTube may return tags in a different order. Verification compares
    // their semantic set while retaining strict equality for the other fields.
    $tagPlan=$plan; $tagPlan['tags']=array('videotest','kids','gymnastics'); $tagPlan=Plan::sanitize($tagPlan);
    $tagHash=Lifecycle::plan_sha256($tagPlan);
    $tagLife=$makeLife(1,'publish','1',true,true); $tagLife['plan_sha256']=$tagHash;
    $GLOBALS['awvp_pub_meta']=array($video=>array(Video_Meta::PEERTUBE_PUBLICATION_PLAN=>$tagPlan,Video_Meta::DESTINATION=>array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41'),Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE=>$tagLife));
    $GLOBALS['awvp_pub_posts']=array($video=>(object)array('ID'=>$video,'post_author'=>9,'post_status'=>'publish'),$anchor=>(object)array('ID'=>$anchor,'post_author'=>7,'post_status'=>'publish')); $GLOBALS['awvp_pub_options']=array();
    $tagManifest=Manifest::build($tagPlan,$catalog,array()); $tagExec=Execution::with_operation(Execution::create($tagManifest,1900),'upload_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',1910); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$tagExec;
    $tagTask=array('id'=>56,'task_type'=>Coordinator::TASK_FINALIZE,'video_post_id'=>$video,'lock_token'=>'00000000-0000-4000-8000-000000000056','payload_json'=>json_encode(array('version'=>1,'generation'=>1,'plan_sha256'=>$tagHash),JSON_THROW_ON_ERROR));
    $x=$factory(); $x['operations']->records[$tagExec['operation_id']]=$ready; $x['api']->remote_privacy='1'; $remote=FakePublicationApi::publication($tagManifest); $remote['tags']=array('gymnastics','kids','videotest'); $x['api']->remote_publication=$remote;
    $tagExisting=$x['coord']->advance_claimed($tagTask,$now);
    $assert(Coordinator::STATUS_COMPLETE===$tagExisting['status']&&'verified_existing'===$tagExisting['service_status']&&0===count($x['api']->publication_calls),'Unordered PeerTube tags caused a false publication mismatch/replay.');

    // RC10 live-fix: API metadata verification is not the final serving gate.
    // A verified publication must also pass an unauthenticated public/embed URL
    // probe before serving cutover. A failed public probe defers locally without
    // replaying the consequential publication mutation.
    $reset($makeLife(1,'publish','1',true,true),'publish'); $publicExec=Execution::with_remote($exec,55,$uuid,1920); $publicExec=Execution::mark_applied($publicExec,$manifest,1930); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$publicExec;
    $x=$factory($catalog,true,false,true); $x['assets']->rows[55]=array('id'=>55,'video_post_id'=>$video,'backend_id'=>'pt-primary','channel_id'=>'41','remote_id'=>$uuid,'role'=>'secondary','state'=>'ready','desired_privacy'=>'public','actual_privacy'=>'public','remote_processing_state'=>'1:published','embed_url'=>'https://video.example.org/videos/embed/abcDEF_123','last_verified_at'=>'2026-09-10 19:00:00');
    $x['publicHealth']->next=array('recorded'=>true,'viability_status'=>\ArgentVideo\Serving_Viability::MISSING,'reason_code'=>'peertube.health.public_missing','message'=>'The public serving URL is missing.','http_status'=>404);
    $publicBlocked=$x['coord']->advance_claimed($task(57,Coordinator::TASK_FINALIZE),$now);
    $assert(Coordinator::STATUS_REQUEUED===$publicBlocked['status']&&0===count($x['api']->publication_calls)&&0===count($x['cutover']->calls)&&1===count($x['publicHealth']->calls),'Public URL failure did not block serving cutover without replaying provider mutation.');
    $assert(true===($x['publicHealth']->calls[0]['initial_verified']??false),'Initial final serving qualification did not identify itself as an initial verified public probe.');

    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$publicExec;
    $x=$factory($catalog,true,false,true); $x['assets']->rows[55]=array('id'=>55,'video_post_id'=>$video,'backend_id'=>'pt-primary','channel_id'=>'41','remote_id'=>$uuid,'role'=>'secondary','state'=>'ready','desired_privacy'=>'public','actual_privacy'=>'public','remote_processing_state'=>'1:published','embed_url'=>'https://video.example.org/videos/embed/abcDEF_123','last_verified_at'=>'2026-09-10 19:00:00');
    $publicDone=$x['coord']->advance_claimed($task(58,Coordinator::TASK_FINALIZE),$now);
    $assert(Coordinator::STATUS_COMPLETE===$publicDone['status']&&1===count($x['publicHealth']->calls)&&1===count($x['cutover']->calls)&&0===count($x['api']->publication_calls),'Healthy public URL did not authorize local serving cutover.');

    // If WordPress loses publish authority during the PUT, correct immediately to Private and verify it.
    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec; $x=$factory(); $x['operations']->records[$exec['operation_id']]=$ready; $x['api']->remote_privacy='3';
    $x['api']->after_publication=static function() use($video,$anchor,$makeLife): void { $GLOBALS['awvp_pub_posts'][$anchor]->post_status='draft'; $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]=$makeLife(2,'draft','3',true,false); };
    $corrected=$x['coord']->advance_claimed($task(6,Coordinator::TASK_FINALIZE),$now); $assert(Coordinator::STATUS_COMPLETE===$corrected['status']&&'corrected_private'===$corrected['service_status'],'Post-PUT WordPress authority loss was not corrected private.');
    $assert(1===count($x['api']->publication_calls)&&1===count($x['api']->privacy_calls)&&'3'===$x['api']->privacy_calls[0]['privacy'],'Emergency correction did not use one privacy-only mutation.');
    $assert('3'===$x['assets']->calls[0]['privacy'],'Private correction was not recorded locally.');

    // Finalizer waits for upload readiness and refuses uncertain mutation replay.
    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec; $x=$factory(); $x['operations']->records[$exec['operation_id']]=array('phase'=>'ready','remote_asset_id'=>0,'remote_identity'=>array('uuid'=>'')); $wait=$x['coord']->advance_claimed($task(7,Coordinator::TASK_FINALIZE),$now); $assert(Coordinator::STATUS_REQUEUED===$wait['status']&&0===count($x['api']->publication_calls),'Finalizer mutated before upload readiness.');
    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec; $x=$factory(); $x['operations']->records[$exec['operation_id']]=array('phase'=>'upload_indeterminate','remote_asset_id'=>0,'remote_identity'=>array('uuid'=>'')); $blocked=$x['coord']->advance_claimed($task(71,Coordinator::TASK_FINALIZE),$now); $assert(Coordinator::STATUS_FAILED===$blocked['status']&&'upload_intervention_required'===$blocked['service_status']&&0===count($x['api']->publication_calls),'Finalizer polled or mutated across an explicit upload intervention boundary.');
    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec; $x=$factory(); $x['operations']->records[$exec['operation_id']]=array('phase'=>'operator_abandoned','remote_asset_id'=>0,'remote_identity'=>array('uuid'=>'')); $retired=$x['coord']->advance_claimed($task(72,Coordinator::TASK_FINALIZE),$now); $assert(Coordinator::STATUS_COMPLETE===$retired['status']&&'upload_operator_abandoned'===$retired['service_status']&&0===count($x['api']->publication_calls),'Operator-retired upload left finalization polling or performed a remote mutation.');
    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec; $x=$factory(); $x['operations']->records[$exec['operation_id']]=$ready; $x['api']->mutate_ok=false; $uncertain=$x['coord']->advance_claimed($task(8,Coordinator::TASK_FINALIZE),$now); $assert(Coordinator::STATUS_FAILED===$uncertain['status']&&'mutation_indeterminate'===$uncertain['service_status']&&1===count($x['api']->publication_calls),'Indeterminate publication mutation was automatically replayed or misclassified.');

    // RC10 definite-vs-indeterminate mutation taxonomy. Local validation exceptions
    // are known not-sent; a 4xx response is a definite provider rejection; a 5xx
    // or transport boundary remains non-replayable because acceptance is not proven.
    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec; $x=$factory(); $x['operations']->records[$exec['operation_id']]=$ready; $x['api']->throw_publication=true;
    $localRefusal=$x['coord']->advance_claimed($task(81,Coordinator::TASK_FINALIZE),$now);
    $assert(Coordinator::STATUS_FAILED===$localRefusal['status']&&'mutation_not_sent'===$localRefusal['service_status'],'Local publication validation exception was misclassified as an indeterminate send.');

    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec; $x=$factory(); $x['operations']->records[$exec['operation_id']]=$ready; $x['api']->mutate_ok=false; $x['api']->failure_error=array('status'=>'invalid_response','http_status'=>422,'code'=>'');
    $rejected=$x['coord']->advance_claimed($task(82,Coordinator::TASK_FINALIZE),$now);
    $rejectedMessage=(string)($x['tasks']->transitions[0][3]??'');
    $assert(Coordinator::STATUS_FAILED===$rejected['status']&&'mutation_rejected'===$rejected['service_status']&&str_contains($rejectedMessage,'HTTP 422'),'Definite HTTP 422 provider rejection was not preserved with status evidence.');

    $reset($makeLife(1,'publish','1',true,true),'publish'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$exec; $x=$factory(); $x['operations']->records[$exec['operation_id']]=$ready; $x['api']->mutate_ok=false; $x['api']->failure_error=array('status'=>'remote_error','http_status'=>503,'code'=>'');
    $serverError=$x['coord']->advance_claimed($task(83,Coordinator::TASK_FINALIZE),$now);
    $serverMessage=(string)($x['tasks']->transitions[0][3]??'');
    $assert(Coordinator::STATUS_FAILED===$serverError['status']&&'mutation_indeterminate'===$serverError['service_status']&&str_contains($serverMessage,'HTTP 503'),'HTTP 5xx outcome lost provider status or crossed the no-blind-replay boundary.');

    // R46.6: already-verified public publication may converge local cutover without provider/API authority.
    $reset($makeLife(1,'publish','1',true,true),'publish'); $applied=Execution::with_remote($exec,55,$uuid,1920); $applied=Execution::mark_applied($applied,$manifest,1930); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$applied; $x=$factory($catalog,true); $x['assets']->rows[55]=array('id'=>55,'backend_id'=>'pt-primary','channel_id'=>'41','remote_id'=>$uuid,'state'=>'ready','desired_privacy'=>'public','actual_privacy'=>'public','remote_processing_state'=>'1:published','last_verified_at'=>'2026-09-07 13:00:00');
    $retry=$x['coord']->advance_claimed($task(9,Coordinator::TASK_FINALIZE),$now); $assert(Coordinator::STATUS_COMPLETE===$retry['status']&&str_starts_with($retry['service_status'],'cutover_retry:')&&0===count($x['api']->publication_calls)&&1===count($x['cutover']->calls),'Verified cutover retry unnecessarily required/replayed provider mutation.');

    // A later private generation must not let cutover-local short-circuit the required remote privacy correction.
    $reset($makeLife(2,'draft','3',true,false),'draft'); $GLOBALS['awvp_pub_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=$applied; $x=$factory($catalog,true); $x['operations']->records[$applied['operation_id']]=$ready; $x['assets']->rows[55]=array('id'=>55,'backend_id'=>'pt-primary','channel_id'=>'41','remote_id'=>$uuid,'state'=>'ready','desired_privacy'=>'public','actual_privacy'=>'public','remote_processing_state'=>'1:published','last_verified_at'=>'2026-09-07 13:00:00'); $x['api']->remote_privacy='1'; $privateTask=$task(10,Coordinator::TASK_FINALIZE,2);
    $privateDone=$x['coord']->advance_claimed($privateTask,$now); $assert(Coordinator::STATUS_COMPLETE===$privateDone['status']&&1===count($x['api']->publication_calls)&&'3'===$x['api']->publication_calls[0]['privacy']&&1===count($x['cutover']->calls),'Private superseding generation was incorrectly short-circuited by local cutover cleanup.');

    $source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Publication_Task_Coordinator.php');
    foreach(array('wp_update_post(','wp_publish_post(','transition_post_status(','Renderer') as $needle){$assert(!str_contains($source,$needle),'Publication coordinator acquired forbidden inline WordPress publish/render authority: '.$needle);}
    $assert(str_contains($source,'PeerTube_Serving_Cutover_Service'),'R46.6 finalizer does not hand verified publication evidence to the local cutover service.');
    fwrite(STDOUT,"R46.5b publication task coordinator tests passed.\n");
}
