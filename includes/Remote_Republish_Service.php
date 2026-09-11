<?php
/** File: includes/Remote_Republish_Service.php */
declare(strict_types=1);
namespace ArgentVideo;

use Throwable;

/** Restart-safe operator republish orchestration using the retained WP source. */
final class Remote_Republish_Service
{
    public const APPLIED='applied';
    public const PRESENT='present';
    public const REFUSED='refused';
    public const INDETERMINATE='indeterminate';
    private const MAX_RECOVERY=20;

    public function __construct(
        private readonly Backend_Registry $registry,
        private readonly Video_Publishing_Defaults_Store $defaults,
        private readonly PeerTube_Publication_Catalog_Store $catalogs,
        private readonly PeerTube_Publication_Synchronizer $synchronizer,
        private readonly ?PeerTube_Event_Repository $events=null,
        private readonly ?PeerTube_Staged_Upload_Operation_Store $operations=null
    ){}


    public function source_available(int $video_id): bool
    {
        $attachment_id=Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ATTACHMENT_ID,true));
        return $attachment_id>0&&array()!==WordPress_Source_File::capture($attachment_id);
    }

    /** @return list<array{id:string,label:string}> */
    public function targets(): array
    {
        $out=array();
        foreach($this->registry->all() as $id=>$descriptor){
            if(!is_string($id)||!is_array($descriptor)||!$this->active_peertube($descriptor,$id))continue;
            $out[]=array('id'=>$id,'label'=>$this->label($descriptor,$id));
        }
        usort($out,static fn(array $a,array $b):int=>strnatcasecmp($a['label'],$b['label']));
        return $out;
    }

    /** @return array{status:string,task_id:int,generation:int,message:string} */
    public function request(int $video_id,string $target_backend_id,int $actor_id,int $now): array
    {
        if($video_id<1||$actor_id<1||$now<1)return self::result(self::REFUSED,0,0,'Invalid republish request.');
        $existing=$this->load_request($video_id);
        if(is_array($existing)&&Remote_Republish_Request::PENDING===($existing['status']??'')){
            if($target_backend_id!==($existing['target_backend_id']??''))return self::result(self::REFUSED,0,(int)$existing['target_generation'],'A republish request is already pending for another backend.');
            return $this->resume($video_id,$now);
        }
        $video=get_post($video_id);
        if(!is_object($video)||Video_Post_Type::POST_TYPE!==($video->post_type??null))return self::result(self::REFUSED,0,0,'AWVP Video is unavailable.');
        $attachment_id=Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ATTACHMENT_ID,true));
        $source=WordPress_Source_File::capture($attachment_id);
        if($attachment_id<1||array()===$source)return self::result(self::REFUSED,0,0,'The original WordPress Media Library video is not available for republishing.');
        if($this->has_unresolved_indeterminate_upload($video_id))return self::result(self::REFUSED,0,0,'The prior upload outcome is still uncertain. Resolve that upload before starting a new publication.');
        $current_plan=PeerTube_Publication_Plan::sanitize(get_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_PLAN,true));
        $lifecycle=PeerTube_Publication_Lifecycle::sanitize(get_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE,true));
        if(array()===$current_plan||!PeerTube_Publication_Plan::ready_for_dispatch($current_plan)||array()===$lifecycle)return self::result(self::REFUSED,0,0,'The current reviewed publication state is incomplete.');
        if((int)$lifecycle['generation']>=PHP_INT_MAX)return self::result(self::REFUSED,0,0,'Publication generation is exhausted.');
        $target_backend_id=Backend_Identity::sanitize($target_backend_id);
        $prepared=$this->prepare_target($current_plan,$target_backend_id);
        if(null===$prepared)return self::result(self::REFUSED,0,0,'The selected backend does not currently have usable publication defaults/catalog authority.');
        try{$request_id=bin2hex(random_bytes(16));}catch(Throwable){return self::result(self::INDETERMINATE,0,0,'Could not create durable republish identity.');}
        $record=Remote_Republish_Request::sanitize(array(
            'version'=>Remote_Republish_Request::VERSION,'request_id'=>$request_id,'target_backend_id'=>$target_backend_id,
            'target_generation'=>(int)$lifecycle['generation']+1,'target_destination'=>$prepared['destination'],'target_plan'=>$prepared['plan'],
            'source_identity'=>$source,'requested_by'=>$actor_id,'requested_at'=>$now,'status'=>Remote_Republish_Request::PENDING,'task_id'=>0,'updated_at'=>$now,
        ));
        if(array()===$record)return self::result(self::REFUSED,0,0,'Republish intent could not be validated.');
        update_post_meta($video_id,Video_Meta::REMOTE_REPUBLISH_REQUEST,$record);
        if($record!==$this->load_request($video_id))return self::result(self::INDETERMINATE,0,(int)$record['target_generation'],'Republish intent could not be durably confirmed.');
        return $this->resume($video_id,$now);
    }

    /** @return array{status:string,task_id:int,generation:int,message:string} */
    public function resume(int $video_id,int $now): array
    {
        $request=$this->load_request($video_id);
        if(!is_array($request))return self::result(self::REFUSED,0,0,'No valid republish request is available.');
        if(Remote_Republish_Request::DISPATCHED===($request['status']??''))return self::result(self::PRESENT,(int)$request['task_id'],(int)$request['target_generation'],'Republish is already queued.');
        $attachment_id=Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ATTACHMENT_ID,true));
        if($attachment_id<1||!WordPress_Source_File::matches($attachment_id,$request['source_identity']))return self::result(self::REFUSED,0,(int)$request['target_generation'],'The retained source changed or is no longer available; republish was not queued.');
        if(!$this->target_still_valid($request['target_plan']))return self::result(self::REFUSED,0,(int)$request['target_generation'],'The selected backend publication choices are no longer current.');
        $anchor_id=Video_Meta::sanitize_positive_id($request['target_plan']['anchor_post_id']??0);
        $anchor=get_post($anchor_id);
        if(!is_object($anchor))return self::result(self::REFUSED,0,(int)$request['target_generation'],'The publication anchor post is unavailable.');

        update_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_PLAN,$request['target_plan']);
        update_post_meta($video_id,Video_Meta::DESTINATION,$request['target_destination']);
        if($request['target_plan']!==PeerTube_Publication_Plan::sanitize(get_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_PLAN,true))
            ||$request['target_destination']!==Video_Destination::sanitize(get_post_meta($video_id,Video_Meta::DESTINATION,true)))
            return self::result(self::INDETERMINATE,0,(int)$request['target_generation'],'Republish publication intent could not be durably confirmed.');

        $lifecycle=PeerTube_Publication_Lifecycle::sanitize(get_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE,true));
        $current_generation=array()===$lifecycle?0:(int)$lifecycle['generation'];
        if($current_generation<(int)$request['target_generation']){
            delete_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION);
            if(metadata_exists('post',$video_id,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION))return self::result(self::INDETERMINATE,0,(int)$request['target_generation'],'Could not reset current execution for a new upload operation.');
        }
        $sync=$this->synchronizer->sync_republish_video($video_id,(string)($anchor->post_status??''),$now,(int)$request['target_generation']);
        $status=is_string($sync['status']??null)?$sync['status']:Task_Repository::INDETERMINATE;
        $task_id=Video_Meta::sanitize_positive_id($sync['task_id']??0);
        $generation=(int)($sync['generation']??0);
        if(in_array($status,array(Task_Repository::APPLIED,Task_Repository::PRESENT),true)&&$task_id>0&&$generation===(int)$request['target_generation']){
            $request['status']=Remote_Republish_Request::DISPATCHED;$request['task_id']=$task_id;$request['updated_at']=$now;
            $request=Remote_Republish_Request::sanitize($request);
            update_post_meta($video_id,Video_Meta::REMOTE_REPUBLISH_REQUEST,$request);
            if($request!==$this->load_request($video_id))return self::result(self::INDETERMINATE,$task_id,$generation,'Republish task was queued, but its request journal could not be finalized.');
            $this->record_event($video_id,$task_id,$request,$now);
            return self::result(self::APPLIED,$task_id,$generation,'Republish queued from the retained WordPress source.');
        }
        return self::result(self::INDETERMINATE,$task_id,$generation,'Republish intent is durable and will be retried by automatic recovery.');
    }

    public function recover(): void
    {
        // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Restart recovery intentionally locates only videos carrying the private durable republish marker and returns at most MAX_RECOVERY IDs.
        $ids=get_posts(array('post_type'=>Video_Post_Type::POST_TYPE,'post_status'=>'any','fields'=>'ids','posts_per_page'=>self::MAX_RECOVERY,'orderby'=>'modified','order'=>'ASC','meta_key'=>Video_Meta::REMOTE_REPUBLISH_REQUEST));
        if(!is_array($ids))return;
        $now=time();
        foreach($ids as $id){$video_id=Video_Meta::sanitize_positive_id($id);$r=$video_id>0?$this->load_request($video_id):null;if(is_array($r)&&Remote_Republish_Request::PENDING===($r['status']??''))$this->resume($video_id,$now);}
    }

    /** @return array<string,mixed>|null */
    private function prepare_target(array $current,string $backend_id): ?array
    {
        $descriptor=$this->registry->get($backend_id);
        if(!is_array($descriptor)||!$this->active_peertube($descriptor,$backend_id))return null;
        $catalog=$this->catalogs->get_fresh($backend_id);
        $catalog=is_array($catalog)?PeerTube_Publication_Catalog::sanitize($catalog):array();
        $origin=PeerTube_Origin::sanitize($descriptor['config']['origin']??null);
        if(array()===$catalog||true===$catalog['stale']||$origin!==($catalog['origin']??null))return null;
        $settings=$this->defaults->get();if(!is_array($settings))return null;
        if($backend_id===($current['backend_id']??'')){
            $plan=$current;
        }else{
            $effective=Video_Publishing_Defaults::effective_for_backend($settings,$descriptor);
            if(array()===$effective)return null;
            $plan=$current;
            $plan['backend_id']=$backend_id;$plan['channel_id']=(string)$effective['channel_id'];
            $plan['final_privacy_id']=(string)$effective['final_privacy_id'];$plan['licence_id']=(string)$effective['licence_id'];
            $plan['category_id']=(string)$effective['category_id'];$plan['language']=(string)$effective['language'];
            $plan['review']['channel']=true;$plan['review']['privacy']=true;
        }
        $plan=PeerTube_Publication_Plan::sanitize($plan);
        if(array()===$plan||!PeerTube_Publication_Plan::ready_for_dispatch($plan)||array()===PeerTube_Publication_Manifest::build($plan,$catalog,$settings))return null;
        $destination=Video_Destination::sanitize(array('version'=>Video_Destination::VERSION,'backend_id'=>$backend_id,'channel_id'=>(string)$plan['channel_id']));
        return array()===$destination?null:array('plan'=>$plan,'destination'=>$destination);
    }

    private function target_still_valid(array $plan): bool
    {
        $backend_id=(string)($plan['backend_id']??'');$descriptor=$this->registry->get_fresh($backend_id);
        if(!is_array($descriptor)||!$this->active_peertube($descriptor,$backend_id))return false;
        $catalog=$this->catalogs->get_fresh($backend_id);$catalog=is_array($catalog)?PeerTube_Publication_Catalog::sanitize($catalog):array();
        $settings=$this->defaults->get();
        return array()!==$catalog&&false===$catalog['stale']&&PeerTube_Origin::sanitize($descriptor['config']['origin']??null)===($catalog['origin']??null)
            &&is_array($settings)&&array()!==PeerTube_Publication_Manifest::build($plan,$catalog,$settings);
    }

    private function has_unresolved_indeterminate_upload(int $video_id): bool
    {
        if(null===$this->operations)return false;
        $execution=PeerTube_Publication_Execution::sanitize(get_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION,true));
        $operation_id=is_string($execution['operation_id']??null)?$execution['operation_id']:'';
        if(''===$operation_id)return false;
        $operation=$this->operations->get($operation_id);
        return is_array($operation)
            && PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE===($operation['phase']??null);
    }

    private function active_peertube(array $d,string $id): bool{return $id===Backend_Identity::sanitize($d['id']??null)&&Backend_Registry::PEERTUBE_TYPE===($d['type']??null)&&'active'===($d['state']??null);}
    private function label(array $d,string $id): string{$v=$d['label']??null;return is_string($v)&&''!==trim($v)?trim($v):$id;}
    /** @return array<string,mixed>|null */
    private function load_request(int $video_id): ?array
    {
        if(!metadata_exists('post',$video_id,Video_Meta::REMOTE_REPUBLISH_REQUEST))return null;
        $v=Remote_Republish_Request::sanitize(get_post_meta($video_id,Video_Meta::REMOTE_REPUBLISH_REQUEST,true));return array()===$v?null:$v;
    }
    private function record_event(int $video_id,int $task_id,array $request,int $now): void
    {
        if(null===$this->events)return;
        $this->events->record($video_id,1,'republish_queued','info','Republish queued from retained WordPress source.',$now,$task_id,'',0,(string)$request['target_backend_id'],0,'Automatic publication worker will create a new remote publication.','',array('generation'=>(int)$request['target_generation'],'request_id'=>(string)$request['request_id']));
    }
    /** @return array{status:string,task_id:int,generation:int,message:string} */
    private static function result(string $status,int $task_id,int $generation,string $message): array{return compact('status','task_id','generation','message');}
}
// EOF
