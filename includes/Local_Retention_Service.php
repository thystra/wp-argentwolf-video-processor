<?php
/** File: includes/Local_Retention_Service.php */
declare(strict_types=1);
namespace ArgentVideo;

use Throwable;

/** R46.9 explicit local retention scheduler and detached cleanup executor. */
final class Local_Retention_Service
{
    public const TASK_TYPE='peertube_local_retention_cleanup';
    public const PAYLOAD_VERSION=2;
    public const LEGACY_PAYLOAD_VERSION=1;
    public const APPLIED='applied';
    public const PRESENT='present';
    public const REFUSED='refused';
    public const INDETERMINATE='indeterminate';
    public const STATUS_COMPLETE='complete';
    public const STATUS_REQUEUED='requeued';
    public const STATUS_FAILED='failed';
    private const PRIORITY=130;
    private const MAX_ATTEMPTS=5;
    private const MAX_ATTACHMENT_REFERENCES=20;

    public function __construct(
        private readonly Task_Repository $tasks,
        private readonly Video_Serving_Resolver $serving,
        private readonly Job_Repository $jobs,
        private readonly ?Archive_Of_Record_Policy_Store $archive_policy,
        private readonly Video_Reference_Index $references
    ){}

    /**
     * Production administrator path: derive source-retention authority from the
     * site-wide archive-of-record policy. A per-application grace override may
     * snapshot 0 (Never) or 1-365 days without changing the site default.
     *
     * @return array{status:string,task_id:int,eligible_at:int}
     */
    public function configure_for_site_policy(int $video_id,string $mode,int $user_id,int $now,?int $grace_override=null):array
    {
        if(null===$this->archive_policy)return self::schedule_result(self::REFUSED);
        if(Local_Retention_Policy::MODE_KEEP===$mode){
            $grace=0;
        }else{
            $grace=null===$grace_override?$this->archive_policy->grace_days():$grace_override;
            if($grace<Local_Retention_Policy::MIN_GRACE_DAYS||$grace>Local_Retention_Policy::MAX_GRACE_DAYS)return self::schedule_result(self::REFUSED);
        }
        if(in_array($mode,array(Local_Retention_Policy::MODE_DELETE_ALL,Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY),true)){
            if(!$this->archive_policy->source_deletion_allowed())return self::schedule_result(self::REFUSED);
            $stored=Video_Meta::sanitize_master_authority(get_post_meta($video_id,Video_Meta::MASTER_AUTHORITY,true));
            if(Local_Retention_Policy::MODE_DELETE_ALL===$mode){
                $master='backend_source';
            }else{
                $master=in_array($stored,array('backend_source','external_archive'),true)?$stored:'none';
            }
        }else{
            $master=Video_Meta::sanitize_master_authority(get_post_meta($video_id,Video_Meta::MASTER_AUTHORITY,true));
            if(!in_array($master,array('wordpress_source','backend_source','external_archive','none'),true))$master='wordpress_source';
            if($this->archive_policy->wordpress_is_archive())$master='wordpress_source';
        }
        return $this->configure($video_id,$mode,$grace,$master,$user_id,$now);
    }

    /** @return array{status:string,task_id:int,eligible_at:int} */
    public function configure(int $video_id,string $mode,int $grace_days,string $master_authority,int $user_id,int $now):array
    {
        if($video_id<1||$user_id<1||$now<1||!in_array($master_authority,array('wordpress_source','backend_source','external_archive','none'),true))return self::schedule_result(self::REFUSED);
        $video=get_post($video_id);
        if(!is_object($video)||Video_Post_Type::POST_TYPE!==($video->post_type??null)||'trash'===($video->post_status??null))return self::schedule_result(self::REFUSED);
        $source_state=Video_Meta::sanitize_source_state(get_post_meta($video_id,Video_Meta::SOURCE_STATE,true));
        if('removed'===$source_state)return self::schedule_result(self::REFUSED);
        $prior_execution=metadata_exists('post',$video_id,Video_Meta::LOCAL_RETENTION_EXECUTION)
            ?Local_Retention_Execution::sanitize(get_post_meta($video_id,Video_Meta::LOCAL_RETENTION_EXECUTION,true)):array();
        if('complete'===Video_Meta::sanitize_cleanup_state(get_post_meta($video_id,Video_Meta::CLEANUP_STATE,true))
            &&array()!==$prior_execution&&in_array((string)($prior_execution['mode']??''),array(Local_Retention_Policy::MODE_DELETE_ALL,Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY),true))return self::schedule_result(self::REFUSED);
        $policy=Local_Retention_Policy::create($mode,$grace_days,$user_id,$now);
        if(array()===$policy)return self::schedule_result(self::REFUSED);
        if(Local_Retention_Policy::deletes_source($policy)&&null!==$this->archive_policy&&!$this->archive_policy->source_deletion_allowed())return self::schedule_result(self::REFUSED);
        if(Local_Retention_Policy::deletes_source($policy)){
            $attachment_id=Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ATTACHMENT_ID,true));
            if($attachment_id<1||!$this->source_reference_safe($attachment_id))return self::schedule_result(self::REFUSED);
        }
        if(!$this->master_allows_policy($policy,$master_authority))return self::schedule_result(self::REFUSED);

        update_post_meta($video_id,Video_Meta::MASTER_AUTHORITY,$master_authority);
        update_post_meta($video_id,Video_Meta::LOCAL_RETENTION_POLICY,$policy);
        if($master_authority!==Video_Meta::sanitize_master_authority(get_post_meta($video_id,Video_Meta::MASTER_AUTHORITY,true))
            ||$policy!==Local_Retention_Policy::sanitize(get_post_meta($video_id,Video_Meta::LOCAL_RETENTION_POLICY,true)))return self::schedule_result(self::INDETERMINATE);

        if(Local_Retention_Policy::MODE_KEEP===$policy['mode']||!Local_Retention_Policy::automatic_cleanup_enabled($policy)){
            $state=Local_Retention_Policy::MODE_KEEP===$policy['mode']?'none':'held';
            update_post_meta($video_id,Video_Meta::CLEANUP_STATE,$state);
            return $state===Video_Meta::sanitize_cleanup_state(get_post_meta($video_id,Video_Meta::CLEANUP_STATE,true))
                ?self::schedule_result(self::APPLIED):self::schedule_result(self::INDETERMINATE);
        }
        return $this->schedule($video_id,$now);
    }

    /** @return array{status:string,task_id:int,eligible_at:int} */
    public function schedule(int $video_id,int $now):array
    {
        return $this->schedule_internal($video_id,$now,false,0);
    }

    /**
     * Explicit administrator cleanup. This bypasses only the retention wait;
     * every archive, ownership, serving-proof, processing, source-identity and
     * filesystem safety gate remains enforced by scheduling and the worker.
     *
     * @return array{status:string,task_id:int,eligible_at:int}
     */
    public function cleanup_now(int $video_id,int $user_id,int $now):array
    {
        if($user_id<1)return self::schedule_result(self::REFUSED);
        return $this->schedule_internal($video_id,$now,true,$user_id);
    }

    /**
     * Explicit administrator request to remove every local copy for one Video.
     * This does not depend on the server default. It first requires a currently
     * viable verified remote publication, then snapshots a zero-day delete-all
     * policy and queues the normal detached cleanup path. The worker re-checks
     * the same safety boundaries before deleting bytes.
     *
     * @return array{status:string,task_id:int,eligible_at:int}
     */
    public function remove_local_copies_now(int $video_id,int $user_id,int $now):array
    {
        if($video_id<1||$user_id<1||$now<1||null===$this->archive_policy||!$this->archive_policy->source_deletion_allowed())return self::schedule_result(self::REFUSED);
        $video=get_post($video_id);
        if(!is_object($video)||Video_Post_Type::POST_TYPE!==($video->post_type??null)||'trash'===($video->post_status??null))return self::schedule_result(self::REFUSED);
        $state=Video_Meta::sanitize_source_state(get_post_meta($video_id,Video_Meta::SOURCE_STATE,true));
        if(!in_array($state,array('present','verified_remote'),true))return self::schedule_result(self::REFUSED);
        $attachment_id=Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ATTACHMENT_ID,true));
        if($attachment_id<1||!self::video_context_valid($video_id,$attachment_id)||!self::attachment_exclusive_to_video($attachment_id,$video_id)||!$this->source_reference_safe($attachment_id))return self::schedule_result(self::REFUSED);
        if($this->local_job_active($attachment_id))return self::schedule_result(self::REFUSED);
        $existing=metadata_exists('post',$video_id,Video_Meta::LOCAL_RETENTION_EXECUTION)
            ?Local_Retention_Execution::sanitize(get_post_meta($video_id,Video_Meta::LOCAL_RETENTION_EXECUTION,true)):array();
        if(array()!==$existing&&Local_Retention_Execution::STATUS_RUNNING===($existing['status']??null))return self::schedule_result(self::REFUSED);
        $authority=Video_Serving_Authority::sanitize(get_post_meta($video_id,Video_Meta::SERVING_AUTHORITY,true));
        if(array()===$authority||!$this->required_remote_publications_verified($video_id))return self::schedule_result(self::REFUSED);
        if(array()===WordPress_Source_File::capture($attachment_id))return self::schedule_result(self::REFUSED);

        $configured=$this->configure_for_site_policy(
            $video_id,
            Local_Retention_Policy::MODE_DELETE_ALL,
            $user_id,
            $now,
            0
        );
        if(!in_array($configured['status']??null,array(self::APPLIED,self::PRESENT),true))return self::schedule_result((string)($configured['status']??self::INDETERMINATE));
        return $this->cleanup_now($video_id,$user_id,$now);
    }

    /** @return array{status:string,task_id:int,eligible_at:int} */
    private function schedule_internal(int $video_id,int $now,bool $manual,int $actor_id):array
    {
        if($video_id<1||$now<1||($manual&&$actor_id<1))return self::schedule_result(self::REFUSED);
        $policy=Local_Retention_Policy::sanitize(get_post_meta($video_id,Video_Meta::LOCAL_RETENTION_POLICY,true));
        if(array()===$policy||!Local_Retention_Policy::destructive($policy)
            ||(!$manual&&!Local_Retention_Policy::automatic_cleanup_enabled($policy)))return self::schedule_result(self::REFUSED);
        $master=Video_Meta::sanitize_master_authority(get_post_meta($video_id,Video_Meta::MASTER_AUTHORITY,true));
        if(Local_Retention_Policy::deletes_source($policy)&&null!==$this->archive_policy&&!$this->archive_policy->source_deletion_allowed())return self::schedule_result(self::REFUSED);
        if(!$this->master_allows_policy($policy,$master))return self::schedule_result(self::REFUSED);
        $state=Video_Meta::sanitize_source_state(get_post_meta($video_id,Video_Meta::SOURCE_STATE,true));
        if(!in_array($state,array('present','verified_remote'),true))return self::schedule_result(self::REFUSED);
        $attachment_id=Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ATTACHMENT_ID,true));
        if($attachment_id<1||!self::video_context_valid($video_id,$attachment_id)||!self::attachment_exclusive_to_video($attachment_id,$video_id))return self::schedule_result(self::REFUSED);
        if(Local_Retention_Policy::deletes_source($policy)&&!$this->source_reference_safe($attachment_id))return self::schedule_result(self::REFUSED);
        if($this->local_job_active($attachment_id))return self::schedule_result(self::REFUSED);

        $source=Local_Retention_Policy::deletes_source($policy)?WordPress_Source_File::capture($attachment_id):array();
        if(Local_Retention_Policy::deletes_source($policy)&&array()===$source)return self::schedule_result(self::REFUSED);
        $proof_kind=Local_Retention_Policy::keeps_local_delivery($policy)?Local_Retention_Execution::PROOF_LOCAL_HLS:Local_Retention_Execution::PROOF_REMOTE;
        $authority=array();$delivery=array();$proof_sha='';$baseline=(int)$policy['confirmed_at'];
        if(Local_Retention_Execution::PROOF_LOCAL_HLS===$proof_kind){
            $delivery=Local_Delivery_Evidence::capture($attachment_id);
            if(array()===$delivery)return self::schedule_result(self::REFUSED);
            $proof_sha=Local_Delivery_Evidence::sha256($delivery);
        }else{
            $authority=Video_Serving_Authority::sanitize(get_post_meta($video_id,Video_Meta::SERVING_AUTHORITY,true));
            if(array()===$authority||!$this->required_remote_publications_verified($video_id))return self::schedule_result(self::REFUSED);
            $proof_sha=Local_Retention_Execution::authority_sha256($authority);
            $baseline=max($baseline,(int)$authority['verified_at']);
        }
        if(''===$proof_sha)return self::schedule_result(self::REFUSED);

        $grace=(int)$policy['grace_days']*86400;
        if($baseline>PHP_INT_MAX-$grace)return self::schedule_result(self::REFUSED);
        $eligible=$manual?$now:$baseline+$grace;
        $existing=metadata_exists('post',$video_id,Video_Meta::LOCAL_RETENTION_EXECUTION)
            ?Local_Retention_Execution::sanitize(get_post_meta($video_id,Video_Meta::LOCAL_RETENTION_EXECUTION,true)):array();
        if($manual&&array()!==$existing&&Local_Retention_Execution::STATUS_RUNNING===$existing['status'])return self::schedule_result(self::REFUSED);
        if(!$manual&&array()!==$existing&&in_array($existing['status'],array(Local_Retention_Execution::STATUS_QUEUED,Local_Retention_Execution::STATUS_RUNNING),true)
            &&hash_equals((string)$existing['policy_sha256'],Local_Retention_Policy::sha256($policy))
            &&hash_equals(Local_Retention_Execution::proof_sha256($existing),$proof_sha))return self::schedule_result(self::PRESENT,(int)$existing['task_id'],(int)$existing['eligible_at']);
        $attempt=array()!==$existing?(int)$existing['attempt']+1:1;
        $execution=Local_Retention_Execution::PROOF_LOCAL_HLS===$proof_kind
            ?Local_Retention_Execution::create_local($video_id,$attachment_id,$policy,$delivery,$source,$attempt,$eligible,$now)
            :Local_Retention_Execution::create_remote($video_id,$attachment_id,$policy,$authority,$source,$attempt,$eligible,$now);
        if(array()===$execution)return self::schedule_result(self::REFUSED);
        update_post_meta($video_id,Video_Meta::LOCAL_RETENTION_EXECUTION,$execution);
        update_post_meta($video_id,Video_Meta::CLEANUP_STATE,$manual?'queued':($eligible<=$now?'eligible':'pending'));
        if($execution!==Local_Retention_Execution::sanitize(get_post_meta($video_id,Video_Meta::LOCAL_RETENTION_EXECUTION,true)))return self::schedule_result(self::INDETERMINATE);
        $exec_sha=Local_Retention_Execution::immutable_sha256($execution);
        $payload=array(
            'version'=>self::PAYLOAD_VERSION,
            'policy_sha256'=>$execution['policy_sha256'],
            'execution_sha256'=>$exec_sha,
            'manual_cleanup'=>$manual,
            'requested_by'=>$manual?$actor_id:0,
            'requested_at'=>$manual?$now:0,
        );
        $key=hash('sha256','awvp-task:v1:'.self::TASK_TYPE.':'.$video_id.':'.$exec_sha);
        $queued=$this->tasks->enqueue(
            self::TASK_TYPE,
            $video_id,
            Local_Retention_Execution::task_remote_asset_id($execution),
            Local_Retention_Execution::task_backend_id($execution),
            $key,$payload,$eligible,$now,self::PRIORITY,self::MAX_ATTEMPTS
        );
        if(!in_array($queued['status']??null,array(Task_Repository::APPLIED,Task_Repository::PRESENT),true))return self::schedule_result(self::INDETERMINATE,0,$eligible);
        $with=Local_Retention_Execution::with_task($execution,(int)$queued['task_id']);
        if(array()!==$with){update_post_meta($video_id,Video_Meta::LOCAL_RETENTION_EXECUTION,$with);$execution=$with;}
        return self::schedule_result(Task_Repository::PRESENT===($queued['status']??null)?self::PRESENT:self::APPLIED,(int)$queued['task_id'],$eligible);
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    public function advance_claimed(array $task,int $now):array
    {
        $id=self::positive($task['id']??null);$video=self::positive($task['video_post_id']??null);$type=is_string($task['task_type']??null)?$task['task_type']:'';$lock=is_string($task['lock_token']??null)?$task['lock_token']:'';$payload=self::payload($task['payload_json']??null);
        if($id<1||$video<1||$now<1||self::TASK_TYPE!==$type||1!==preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$lock)||null===$payload)return self::worker_result(self::STATUS_FAILED,$id,$type,Task_Repository::CONFLICT,0,'invalid_task');
        $policy=Local_Retention_Policy::sanitize(get_post_meta($video,Video_Meta::LOCAL_RETENTION_POLICY,true));
        $execution=Local_Retention_Execution::sanitize(get_post_meta($video,Video_Meta::LOCAL_RETENTION_EXECUTION,true));
        if(array()===$policy||array()===$execution||!hash_equals($payload['policy_sha256'],Local_Retention_Policy::sha256($policy))||!hash_equals($payload['execution_sha256'],Local_Retention_Execution::immutable_sha256($execution))){
            return self::worker_result(self::STATUS_COMPLETE,$id,$type,$this->tasks->complete($id,$lock,$now),0,'stale');
        }
        if(!self::video_context_valid($video,(int)$execution['attachment_id'])||!self::attachment_exclusive_to_video((int)$execution['attachment_id'],$video))return $this->block($id,$lock,$execution,$now,'AWVP Video or attachment ownership changed before cleanup.');
        if(Local_Retention_Policy::deletes_source($policy)&&!$this->source_reference_safe((int)$execution['attachment_id']))return $this->block($id,$lock,$execution,$now,'Another WordPress post still directly references this local attachment; local bytes were kept.');
        if($now<(int)$execution['eligible_at'])return self::worker_result(self::STATUS_REQUEUED,$id,$type,$this->tasks->reschedule($id,$lock,(int)$execution['eligible_at'],'Retention grace period has not elapsed.',$now),(int)$execution['eligible_at'],'waiting');
        $master=Video_Meta::sanitize_master_authority(get_post_meta($video,Video_Meta::MASTER_AUTHORITY,true));
        if(Local_Retention_Policy::deletes_source($policy)&&null!==$this->archive_policy&&!$this->archive_policy->source_deletion_allowed())return $this->block($id,$lock,$execution,$now,'WordPress is now the archive of record; the original source was kept.');
        if(!$this->master_allows_policy($policy,$master))return $this->block($id,$lock,$execution,$now,'Current archive/source policy no longer permits the requested source deletion.');
        if(!$this->proof_matches($video,$execution))return $this->block($id,$lock,$execution,$now,'Required serving evidence changed before cleanup.');
        if($this->local_job_active((int)$execution['attachment_id']))return $this->block($id,$lock,$execution,$now,'A local processing job became active before cleanup.');
        if(Local_Retention_Policy::deletes_source($policy)&&Local_Retention_Execution::STATUS_RUNNING!==$execution['status']&&!WordPress_Source_File::matches((int)$execution['attachment_id'],$execution['source']))return $this->block($id,$lock,$execution,$now,'WordPress source identity changed before cleanup.');
        if(Local_Retention_Execution::STATUS_COMPLETE===$execution['status'])return self::worker_result(self::STATUS_COMPLETE,$id,$type,$this->tasks->complete($id,$lock,$now),0,'already_complete');
        if(in_array($execution['status'],array(Local_Retention_Execution::STATUS_BLOCKED,Local_Retention_Execution::STATUS_FAILED),true))return self::worker_result(self::STATUS_COMPLETE,$id,$type,$this->tasks->complete($id,$lock,$now),0,'terminal_keep');
        $running=Local_Retention_Execution::transition($execution,Local_Retention_Execution::STATUS_RUNNING,$now);
        if(array()===$running||!$this->save_execution($video,$running,'running'))return self::worker_result(self::STATUS_FAILED,$id,$type,$this->tasks->fail($id,$lock,'Cleanup journal could not enter running state.',$now),0,'journal_indeterminate');
        if($this->local_job_active((int)$running['attachment_id']))return $this->block($id,$lock,$running,$now,'A local processing job raced with cleanup and local bytes were kept.');
        if(!self::video_context_valid($video,(int)$running['attachment_id'])||!self::attachment_exclusive_to_video((int)$running['attachment_id'],$video))return $this->block($id,$lock,$running,$now,'AWVP Video or attachment ownership changed after the cleanup fence.');
        if(Local_Retention_Policy::deletes_source($policy)&&!$this->source_reference_safe((int)$running['attachment_id']))return $this->block($id,$lock,$running,$now,'Another WordPress post began directly referencing this local attachment after the cleanup fence; local bytes were kept.');
        $current_policy=$this->current_policy_for_execution($video,$running,$now,(bool)$payload['manual_cleanup']);
        if(array()===$current_policy)return $this->block($id,$lock,$running,$now,'Retention policy or archive authority changed after the cleanup fence; local bytes were kept.');
        $policy=$current_policy;
        try{
            if(Local_Retention_Policy::deletes_managed($policy)){
                $directory=Storage::attachment_directory((int)$running['attachment_id']);
                if(is_dir($directory))Storage::remove_tree($directory);
                if(metadata_exists('post',(int)$running['attachment_id'],'_argent_video_outputs'))delete_post_meta((int)$running['attachment_id'],'_argent_video_outputs');
                if(metadata_exists('post',(int)$running['attachment_id'],'_argent_video_outputs'))throw new \RuntimeException('Managed output metadata could not be cleared.');
            }
            if(Local_Retention_Policy::deletes_source($policy)){
                $source_policy=$this->current_policy_for_execution($video,$running,$now,(bool)$payload['manual_cleanup']);
                if(array()===$source_policy||!Local_Retention_Policy::deletes_source($source_policy))return $this->block($id,$lock,$running,$now,'Source-deletion policy or archive authority changed immediately before physical source deletion.');
                if(!$this->proof_matches($video,$running))return $this->block($id,$lock,$running,$now,'Required serving evidence changed immediately before physical source deletion.');
                $source_absent=Local_Retention_Execution::STATUS_RUNNING===$execution['status']&&$this->source_identity_absent((int)$running['attachment_id'],$running['source']);
                if(!WordPress_Source_File::matches((int)$running['attachment_id'],$running['source'])&&!$source_absent)return $this->block($id,$lock,$running,$now,'WordPress source identity changed immediately before physical source deletion.');
                if(Local_Retention_Policy::MODE_DELETE_ALL===$policy['mode']){
                    if(!$this->retire_source_attachment($video,$running,$now,$source_absent))throw new \RuntimeException('WordPress source attachment retirement could not be positively verified.');
                }else{
                    if(!$source_absent&&!WordPress_Source_File::delete((int)$running['attachment_id'],$running['source']))throw new \RuntimeException('Physical WordPress source deletion could not be positively verified.');
                    update_post_meta($video,Video_Meta::SOURCE_STATE,'removed');
                    if('removed'!==Video_Meta::sanitize_source_state(get_post_meta($video,Video_Meta::SOURCE_STATE,true)))throw new \RuntimeException('Source-state completion could not be persisted.');
                }
            }
        }catch(Throwable $e){
            if(Local_Retention_Policy::deletes_source($policy)&&$this->source_identity_absent((int)$running['attachment_id'],$running['source'])){
                update_post_meta($video,Video_Meta::CLEANUP_STATE,'running');
                return self::worker_result(self::STATUS_REQUEUED,$id,$type,$this->tasks->reschedule($id,$lock,$now+60,'Physical source is absent under a running cleanup journal; retrying local audit convergence.',$now),$now+60,'audit_retry');
            }
            $failed=Local_Retention_Execution::transition($running,Local_Retention_Execution::STATUS_FAILED,$now,$e->getMessage());
            if(array()!==$failed)$this->save_execution($video,$failed,'failed');
            update_post_meta($video,Video_Meta::CLEANUP_STATE,'failed');
            return self::worker_result(self::STATUS_FAILED,$id,$type,$this->tasks->fail($id,$lock,'Local retention cleanup failed closed.',$now),0,'cleanup_failed');
        }
        $complete=Local_Retention_Execution::transition($running,Local_Retention_Execution::STATUS_COMPLETE,$now);
        if(array()===$complete||!$this->save_execution($video,$complete,'complete'))return self::worker_result(self::STATUS_REQUEUED,$id,$type,$this->tasks->reschedule($id,$lock,$now+60,'Cleanup bytes are converged; retrying durable audit completion.',$now),$now+60,'audit_retry');
        update_post_meta($video,Video_Meta::CLEANUP_STATE,'complete');
        return self::worker_result(self::STATUS_COMPLETE,$id,$type,$this->tasks->complete($id,$lock,$now),0,'cleanup_complete');
    }

    public static function attachment_rebuild_blocked(int $attachment_id):bool
    {
        $ids=self::attachment_video_ids($attachment_id);if(null===$ids)return true;
        foreach($ids as $raw){
            $video_id=Video_Meta::sanitize_positive_id($raw);if($video_id<1)continue;
            $state=Video_Meta::sanitize_source_state(get_post_meta($video_id,Video_Meta::SOURCE_STATE,true));
            $cleanup=Video_Meta::sanitize_cleanup_state(get_post_meta($video_id,Video_Meta::CLEANUP_STATE,true));
            $policy=Local_Retention_Policy::sanitize(get_post_meta($video_id,Video_Meta::LOCAL_RETENTION_POLICY,true));
            if('removed'===$state||in_array($cleanup,array('pending','queued','eligible','running'),true)||('complete'===$cleanup&&array()!==$policy&&Local_Retention_Policy::destructive($policy)))return true;
        }
        return false;
    }

    public static function attachment_local_processing_blocked(int $attachment_id):bool
    {
        $ids=self::attachment_video_ids($attachment_id);if(null===$ids)return true;
        foreach($ids as $raw){
            $video_id=Video_Meta::sanitize_positive_id($raw);if($video_id<1)continue;
            $state=Video_Meta::sanitize_source_state(get_post_meta($video_id,Video_Meta::SOURCE_STATE,true));
            $cleanup=Video_Meta::sanitize_cleanup_state(get_post_meta($video_id,Video_Meta::CLEANUP_STATE,true));
            $policy=Local_Retention_Policy::sanitize(get_post_meta($video_id,Video_Meta::LOCAL_RETENTION_POLICY,true));
            if('removed'===$state||in_array($cleanup,array('queued','running'),true)||('complete'===$cleanup&&array()!==$policy&&Local_Retention_Policy::destructive($policy)))return true;
            $destination_exists=metadata_exists('post',$video_id,Video_Meta::DESTINATION);
            $destination=Video_Destination::resolve(get_post_meta($video_id,Video_Meta::DESTINATION,true),$destination_exists);
            if(array()===$destination||!Video_Destination::is_local($destination))return true;
        }
        return false;
    }

    /** @return list<int>|null */
    private static function attachment_video_ids(int $attachment_id):?array
    {
        if($attachment_id<1)return null;
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded local-processing fence intentionally queries the private attachment-id meta.
        $ids=get_posts(array(
            'post_type'=>Video_Post_Type::POST_TYPE,
            'post_status'=>array('publish','future','draft','pending','private','trash'),
            'numberposts'=>self::MAX_ATTACHMENT_REFERENCES+1,
            'fields'=>'ids',
            'meta_key'=>Video_Meta::ATTACHMENT_ID,
            'meta_value'=>$attachment_id,
            'meta_compare'=>'=',
        ));
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        if(!is_array($ids)||count($ids)>self::MAX_ATTACHMENT_REFERENCES)return null;
        return array_values(array_map('intval',$ids));
    }

    /**
     * Reconcile attachments left behind by RC13.7 after a completed delete-all
     * cleanup. Runs from the existing detached recovery cron and never from an
     * administrator GET request.
     */
    public function reconcile_completed_retirements(): void
    {
        $now=time();
        // This is upgrade debt reconciliation, not a permanent inventory scan. Query
        // only source-removed Videos that still carry the obsolete attachment
        // binding, so successfully retired rows disappear from later runs.
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- Narrow recovery query over private AWVP Video metadata.
        $ids=get_posts(array(
            'post_type'=>Video_Post_Type::POST_TYPE,
            'post_status'=>array('publish','future','draft','pending','private'),
            'posts_per_page'=>-1,
            'fields'=>'ids',
            'orderby'=>'ID',
            'order'=>'ASC',
            'no_found_rows'=>true,
            'meta_query'=>array(
                'relation'=>'AND',
                array('key'=>Video_Meta::SOURCE_STATE,'value'=>'removed','compare'=>'='),
                array('key'=>Video_Meta::ATTACHMENT_ID,'compare'=>'EXISTS'),
            ),
        ));
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_query
        if(!is_array($ids))return;
        foreach($ids as $raw){
            $video_id=Video_Meta::sanitize_positive_id($raw);if($video_id<1)continue;
            $attachment_id=Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ATTACHMENT_ID,true));
            if($attachment_id<1)continue;
            $execution=Local_Retention_Execution::sanitize(get_post_meta($video_id,Video_Meta::LOCAL_RETENTION_EXECUTION,true));
            if(array()===$execution||Local_Retention_Policy::MODE_DELETE_ALL!==($execution['mode']??null)||Local_Retention_Execution::STATUS_COMPLETE!==($execution['status']??null))continue;
            if((int)$execution['attachment_id']!==$attachment_id||!$this->source_reference_safe($attachment_id)||!$this->proof_matches($video_id,$execution))continue;
            $this->retire_source_attachment($video_id,$execution,$now,true);
        }
    }

    private function source_reference_safe(int $attachment_id):bool
    {
        if($attachment_id<1||$this->references->truncated())return false;
        return array()===$this->references->attachment_posts_for($attachment_id);
    }

    private function source_identity_absent(int $attachment_id,array $source):bool
    {
        return WordPress_Source_File::absent($attachment_id,$source)||WordPress_Source_File::identity_absent($source);
    }

    private function retire_source_attachment(int $video_id,array $execution,int $now,bool $source_absent):bool
    {
        $execution=Local_Retention_Execution::sanitize($execution);
        if($video_id<1||$now<1||array()===$execution||Local_Retention_Policy::MODE_DELETE_ALL!==($execution['mode']??null))return false;
        $attachment_id=(int)$execution['attachment_id'];$source=$execution['source'];
        if($attachment_id<1||!$this->source_reference_safe($attachment_id))return false;
        $tombstone=Source_Retirement_Record::sanitize(get_post_meta($video_id,Video_Meta::SOURCE_TOMBSTONE,true));
        $attachment=get_post($attachment_id);
        if(is_object($attachment)&&'attachment'===($attachment->post_type??null)){
            if(array()===$tombstone){
                // Persist attachment/source provenance before invoking the
                // destructive WordPress attachment lifecycle. For a migrated
                // RC13.7 record the source is already absent, so its completed
                // execution time is an accurate source-removal time. A fresh
                // delete-all records removed_at only after WordPress confirms
                // attachment deletion.
                $removed_at=$source_absent?(int)($execution['completed_at']??0):0;
                if($source_absent&&$removed_at<1)$removed_at=$now;
                $tombstone=Source_Retirement_Record::capture($attachment_id,$source,(int)$execution['task_id'],$removed_at);
                if(array()===$tombstone)return false;
                update_post_meta($video_id,Video_Meta::SOURCE_TOMBSTONE,$tombstone);
                if($tombstone!==Source_Retirement_Record::sanitize(get_post_meta($video_id,Video_Meta::SOURCE_TOMBSTONE,true)))return false;
            }
            if(!$source_absent&&!WordPress_Source_File::matches($attachment_id,$source))return false;

            // Delete-all retires the Media Library object through WordPress'
            // attachment lifecycle. WordPress owns deletion of the attached
            // source and attachment metadata; AWVP never unlinks the source or
            // deletes attachment rows directly. Source-prune/keep-HLS uses the
            // narrower WordPress_Source_File::delete() boundary instead because
            // that mode intentionally keeps the attachment record.
            $deleted=wp_delete_attachment($attachment_id,true);
            if(false===$deleted||null!==get_post($attachment_id))return false;
            if(!$this->source_identity_absent($attachment_id,$source))return false;
            if((int)($tombstone['removed_at']??0)<1){
                $tombstone=Source_Retirement_Record::with_removed_at($tombstone,$now);
                if(array()===$tombstone)return false;
                update_post_meta($video_id,Video_Meta::SOURCE_TOMBSTONE,$tombstone);
                if($tombstone!==Source_Retirement_Record::sanitize(get_post_meta($video_id,Video_Meta::SOURCE_TOMBSTONE,true)))return false;
            }
        }elseif(array()===$tombstone){
            return false;
        }
        if(!WordPress_Source_File::identity_absent($source))return false;
        update_post_meta($video_id,Video_Meta::SOURCE_STATE,'removed');
        delete_post_meta($video_id,Video_Meta::ATTACHMENT_ID);
        return 'removed'===Video_Meta::sanitize_source_state(get_post_meta($video_id,Video_Meta::SOURCE_STATE,true))
            &&0===Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ATTACHMENT_ID,true))
            &&array()!==Source_Retirement_Record::sanitize(get_post_meta($video_id,Video_Meta::SOURCE_TOMBSTONE,true));
    }

    /** @return array<string,mixed> */
    private function current_policy_for_execution(int $video_id,array $execution,int $now,bool $manual_cleanup=false):array
    {
        $execution=Local_Retention_Execution::sanitize($execution);
        if($video_id<1||$now<1||array()===$execution||$now<(int)$execution['eligible_at'])return array();
        $policy=Local_Retention_Policy::sanitize(get_post_meta($video_id,Video_Meta::LOCAL_RETENTION_POLICY,true));
        if(array()===$policy||!Local_Retention_Policy::destructive($policy)
            ||(!$manual_cleanup&&!Local_Retention_Policy::automatic_cleanup_enabled($policy))
            ||!hash_equals((string)$execution['policy_sha256'],Local_Retention_Policy::sha256($policy)))return array();
        $master=Video_Meta::sanitize_master_authority(get_post_meta($video_id,Video_Meta::MASTER_AUTHORITY,true));
        if(!$this->master_allows_policy($policy,$master))return array();
        if(Local_Retention_Policy::deletes_source($policy)&&null!==$this->archive_policy&&!$this->archive_policy->source_deletion_allowed())return array();
        return $policy;
    }

    private function proof_matches(int $video_id,array $execution):bool
    {
        $execution=Local_Retention_Execution::sanitize($execution);
        if(array()===$execution)return false;
        if(Local_Retention_Execution::PROOF_LOCAL_HLS===Local_Retention_Execution::proof_kind($execution)){
            $delivery=Local_Retention_Execution::local_delivery($execution);
            return array()!==$delivery&&Local_Delivery_Evidence::matches((int)$execution['attachment_id'],$delivery);
        }
        $authority=Video_Serving_Authority::sanitize(get_post_meta($video_id,Video_Meta::SERVING_AUTHORITY,true));
        return array()!==$authority
            &&hash_equals(Local_Retention_Execution::proof_sha256($execution),Local_Retention_Execution::authority_sha256($authority))
            &&$this->required_remote_publications_verified($video_id);
    }

    private function master_allows_policy(array $policy,string $master):bool
    {
        $policy=Local_Retention_Policy::sanitize($policy);
        if(array()===$policy||!Local_Retention_Policy::deletes_source($policy))return true;
        if(Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY===$policy['mode'])return in_array($master,array('backend_source','external_archive','none'),true);
        return in_array($master,array('backend_source','external_archive'),true);
    }

    private static function video_context_valid(int $video_id,int $attachment_id=0):bool{$post=$video_id>0?get_post($video_id):null;if(!is_object($post)||Video_Post_Type::POST_TYPE!==($post->post_type??null)||'trash'===($post->post_status??null))return false;return $attachment_id<1||$attachment_id===Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ATTACHMENT_ID,true));}
    private static function attachment_exclusive_to_video(int $attachment_id,int $video_id):bool
    {
        if($attachment_id<1||$video_id<1)return false;
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded duplicate-ownership fence intentionally queries the private attachment-id meta.
        $ids=get_posts(array('post_type'=>Video_Post_Type::POST_TYPE,'post_status'=>array('publish','future','draft','pending','private','trash'),'numberposts'=>2,'fields'=>'ids','meta_key'=>Video_Meta::ATTACHMENT_ID,'meta_value'=>$attachment_id,'meta_compare'=>'='));
        if(!is_array($ids))return false;$found=array();foreach($ids as $raw){$id=Video_Meta::sanitize_positive_id($raw);if($id>0)$found[$id]=true;}return 1===count($found)&&isset($found[$video_id]);
    }
    private function local_job_active(int $attachment_id):bool{$job=$attachment_id>0?$this->jobs->find_by_attachment($attachment_id):null;return is_array($job)&&in_array((string)($job['status']??''),array('queued','processing'),true);}
    private function required_remote_publications_verified(int $video_id):bool
    {
        // RC13.2 currently has one required remote publication per Video. Keep
        // this gate explicit so future multi-server publication can expand the
        // required set without weakening the retention deletion invariant.
        return $video_id>0&&''!==$this->serving->peertube_embed_url($video_id);
    }
    private function block(int $task_id,string $lock,array $execution,int $now,string $reason):array{$blocked=Local_Retention_Execution::transition($execution,Local_Retention_Execution::STATUS_BLOCKED,$now,$reason);if(array()!==$blocked)$this->save_execution((int)$execution['video_id'],$blocked,'blocked');update_post_meta((int)$execution['video_id'],Video_Meta::CLEANUP_STATE,'blocked');return self::worker_result(self::STATUS_COMPLETE,$task_id,self::TASK_TYPE,$this->tasks->complete($task_id,$lock,$now),0,'blocked_keep');}
    private function save_execution(int $video,array $record,string $cleanup):bool{update_post_meta($video,Video_Meta::LOCAL_RETENTION_EXECUTION,$record);update_post_meta($video,Video_Meta::CLEANUP_STATE,$cleanup);return $record===Local_Retention_Execution::sanitize(get_post_meta($video,Video_Meta::LOCAL_RETENTION_EXECUTION,true));}
    /** @return array{version:int,policy_sha256:string,execution_sha256:string,manual_cleanup:bool,requested_by:int,requested_at:int}|null */
    private static function payload(mixed $json):?array
    {
        if(!is_string($json)||''===$json||strlen($json)>16384)return null;
        try{$v=json_decode($json,true,6,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
        if(!is_array($v)||!is_int($v['version']??null))return null;
        if(self::LEGACY_PAYLOAD_VERSION===$v['version']){
            if(array('version','policy_sha256','execution_sha256')!==array_keys($v))return null;
            $v=array(
                'version'=>self::LEGACY_PAYLOAD_VERSION,
                'policy_sha256'=>$v['policy_sha256'],
                'execution_sha256'=>$v['execution_sha256'],
                'manual_cleanup'=>false,
                'requested_by'=>0,
                'requested_at'=>0,
            );
        }elseif(self::PAYLOAD_VERSION===$v['version']){
            if(array('version','policy_sha256','execution_sha256','manual_cleanup','requested_by','requested_at')!==array_keys($v)
                ||!is_bool($v['manual_cleanup']??null)||!is_int($v['requested_by']??null)||!is_int($v['requested_at']??null)
                ||$v['requested_by']<0||$v['requested_at']<0
                ||($v['manual_cleanup']&&($v['requested_by']<1||$v['requested_at']<1))
                ||(!$v['manual_cleanup']&&(0!==$v['requested_by']||0!==$v['requested_at'])))return null;
        }else{return null;}
        foreach(array('policy_sha256','execution_sha256') as $k){if(!is_string($v[$k]??null)||1!==preg_match('/^[a-f0-9]{64}$/D',$v[$k]))return null;}
        return $v;
    }
    private static function positive(mixed $v):int{if(is_int($v))return$v>0?$v:0;if(!is_string($v)||1!==preg_match('/^[1-9][0-9]*$/D',$v))return 0;$n=(int)$v;return$n>0&&(string)$n===$v?$n:0;}
    /** @return array{status:string,task_id:int,eligible_at:int} */ private static function schedule_result(string $s,int $id=0,int $eligible=0):array{return array('status'=>$s,'task_id'=>$id,'eligible_at'=>$eligible);}
    /** @return array<string,mixed> */ private static function worker_result(string $s,int $id,string $type,string $repo,int $after,string $service):array{return array('status'=>$s,'task_id'=>$id,'task_type'=>$type,'service_status'=>$service,'repository_status'=>$repo,'run_after'=>$after);}
}
// EOF
