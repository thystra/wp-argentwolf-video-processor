<?php
/** File: includes/Local_Delivery_Rebuild_Service.php */
declare(strict_types=1);
namespace ArgentVideo;

use Throwable;

/** Explicit local-HLS rebuild path that preserves remote publication routing/history. */
final class Local_Delivery_Rebuild_Service
{
    public const APPLIED='applied';
    public const PRESENT='present';
    public const REFUSED='refused';
    public const INDETERMINATE='indeterminate';

    public function __construct(
        private readonly Job_Repository $jobs,
        private readonly Worker_Launcher $launcher,
        private readonly ?PeerTube_Event_Repository $events = null
    ) {}

    public function source_available(int $video_id): bool
    {
        $attachment_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true));
        return $attachment_id > 0 && array() !== WordPress_Source_File::capture($attachment_id);
    }

    /** @return array{status:string,job_id:int,message:string} */
    public function request(int $video_id, int $actor_id, int $now): array
    {
        if ($video_id < 1 || $actor_id < 1 || $now < 1) return self::result(self::REFUSED,0,'Invalid local delivery rebuild request.');
        $video = get_post($video_id);
        if (! is_object($video) || Video_Post_Type::POST_TYPE !== ($video->post_type ?? null) || 'trash' === ($video->post_status ?? null)) return self::result(self::REFUSED,0,'AWVP Video is unavailable.');
        $attachment_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true));
        $source = WordPress_Source_File::capture($attachment_id);
        if ($attachment_id < 1 || array() === $source) return self::result(self::REFUSED,0,'The retained WordPress source is unavailable.');
        if (Local_Retention_Service::attachment_rebuild_blocked($attachment_id)) return self::result(self::REFUSED,0,'Current retention or cleanup state blocks local delivery rebuilding.');

        $existing_request = Local_Delivery_Rebuild_Request::sanitize(get_post_meta($video_id, Video_Meta::LOCAL_DELIVERY_REBUILD_REQUEST, true));
        if (array() !== $existing_request && in_array($existing_request['status'], array(Local_Delivery_Rebuild_Request::PREPARED,Local_Delivery_Rebuild_Request::QUEUED,Local_Delivery_Rebuild_Request::PROCESSING), true)) {
            return self::result(self::PRESENT,(int)$existing_request['job_id'],'Local delivery rebuilding is already prepared, queued, or running.');
        }
        $existing_job = $this->jobs->find_by_attachment($attachment_id);
        if (is_array($existing_job) && 'processing' === (string) ($existing_job['status'] ?? '')) return self::result(self::REFUSED,(int)($existing_job['id']??0),'A local processing job is already running for this source.');

        $path = get_attached_file($attachment_id, true);
        if (! is_string($path) || '' === $path || ! is_file($path)) return self::result(self::REFUSED,0,'The retained WordPress source file is unavailable.');
        $signature = hash('sha256', wp_normalize_path($path) . '|' . filesize($path) . '|' . filemtime($path));
        $profile = Settings::current_job_profile();
        if (! Settings::job_has_hls($profile)) $profile .= '+hls';
        try { $request_id = bin2hex(random_bytes(16)); } catch (Throwable) { return self::result(self::INDETERMINATE,0,'Could not create a durable local rebuild identity.'); }

        // Persist the exact source/profile authorization before making any local job claimable.
        // A worker that races the post-enqueue QUEUED update can safely adopt PREPARED
        // only when the claimed job matches this frozen source signature/profile.
        $record = Local_Delivery_Rebuild_Request::sanitize(array(
            'version'=>1,'request_id'=>$request_id,'video_id'=>$video_id,'attachment_id'=>$attachment_id,'source_identity'=>$source,
            'source_signature'=>$signature,'profile'=>$profile,'job_id'=>0,'status'=>Local_Delivery_Rebuild_Request::PREPARED,
            'requested_by'=>$actor_id,'requested_at'=>$now,'updated_at'=>$now,'completed_at'=>0,'error_message'=>'',
        ));
        if (array() === $record || ! $this->save($video_id, $record)) return self::result(self::INDETERMINATE,0,'The local rebuild authorization could not be durably prepared.');

        $job_id = $this->jobs->enqueue($attachment_id, $path, $signature, $profile, true);
        if ($job_id < 1) {
            $this->fail_prepared($video_id, $record, $now, 'The local rebuild job could not be queued.');
            return self::result(self::INDETERMINATE,0,'The local rebuild job could not be queued.');
        }
        $queued_job = $this->jobs->find($job_id);
        if (! is_array($queued_job) || $attachment_id !== (int) ($queued_job['attachment_id'] ?? 0)
            || $signature !== (string) ($queued_job['source_signature'] ?? '') || $profile !== (string) ($queued_job['profile'] ?? '')
            || ! in_array((string) ($queued_job['status'] ?? ''), array('queued','processing','complete','failed'), true)) {
            $this->fail_prepared($video_id, $record, $now, 'The local rebuild job could not be verified after queueing.');
            return self::result(self::INDETERMINATE,$job_id,'The local rebuild job could not be verified after queueing.');
        }

        $current = $this->load($video_id);
        if (! is_array($current) || $request_id !== (string) ($current['request_id'] ?? '')) {
            return self::result(self::INDETERMINATE,$job_id,'The local rebuild job was queued, but its durable authorization changed unexpectedly.');
        }
        if (Local_Delivery_Rebuild_Request::PREPARED === $current['status']) {
            $current['job_id']=$job_id;$current['status']=Local_Delivery_Rebuild_Request::QUEUED;$current['updated_at']=$now;
            $current=Local_Delivery_Rebuild_Request::sanitize($current);
            if (array() === $current || ! $this->save($video_id,$current)) return self::result(self::INDETERMINATE,$job_id,'The local rebuild job was queued, but its durable authorization could not be finalized.');
        } elseif (! (Local_Delivery_Rebuild_Request::PROCESSING === $current['status'] && $job_id === (int) $current['job_id'])) {
            return self::result(self::INDETERMINATE,$job_id,'The local rebuild job was queued, but its authorization no longer matches that job.');
        }

        update_post_meta($attachment_id, '_argent_video_job_id', $job_id);
        update_post_meta($attachment_id, '_argent_video_status', Local_Delivery_Rebuild_Request::PROCESSING === $current['status'] ? 'processing' : 'queued');
        update_post_meta($attachment_id, '_argent_video_source_signature', $signature);
        delete_post_meta($attachment_id, '_argent_video_last_error');
        $this->record($current,'local_delivery_rebuild_queued','info','Local AWVP delivery rebuilding was queued from the retained WordPress source.',$now,'Rebuild local delivery');
        $this->launcher->dispatch();
        return self::result(self::APPLIED,$job_id,'Local AWVP delivery rebuilding was queued.');
    }

    /** @param array<string,mixed> $job */
    public function matches_active_request(array $job): bool
    {
        $attachment_id=(int)($job['attachment_id']??0);$job_id=(int)($job['id']??0);
        $video_id=$this->video_for_attachment($attachment_id);$record=$video_id>0?$this->load($video_id):null;
        return is_array($record)
            &&$attachment_id===(int)$record['attachment_id']
            &&in_array($record['status'],array(Local_Delivery_Rebuild_Request::PREPARED,Local_Delivery_Rebuild_Request::QUEUED,Local_Delivery_Rebuild_Request::PROCESSING),true)
            &&(Local_Delivery_Rebuild_Request::PREPARED===$record['status']||$job_id===(int)$record['job_id'])
            &&(string)($job['source_signature']??'')===$record['source_signature']
            &&(string)($job['profile']??'')===$record['profile'];
    }

    /** @param array<string,mixed> $job */
    public function authorize_claimed_job(array $job, int $now): bool
    {
        $attachment_id=(int)($job['attachment_id']??0);$job_id=(int)($job['id']??0);
        $video_id=$this->video_for_attachment($attachment_id);$record=$video_id>0?$this->load($video_id):null;
        if(!is_array($record)||$attachment_id!==(int)$record['attachment_id']
            ||!in_array($record['status'],array(Local_Delivery_Rebuild_Request::PREPARED,Local_Delivery_Rebuild_Request::QUEUED,Local_Delivery_Rebuild_Request::PROCESSING),true)
            ||(Local_Delivery_Rebuild_Request::PREPARED!==$record['status']&&$job_id!==(int)$record['job_id'])
            ||(string)($job['source_signature']??'')!==$record['source_signature']||(string)($job['profile']??'')!==$record['profile']
            ||!WordPress_Source_File::matches($attachment_id,$record['source_identity'])||Local_Retention_Service::attachment_rebuild_blocked($attachment_id))return false;
        if(Local_Delivery_Rebuild_Request::PROCESSING!==$record['status']){
            $record['job_id']=$job_id;$record['status']=Local_Delivery_Rebuild_Request::PROCESSING;$record['updated_at']=$now;$record=Local_Delivery_Rebuild_Request::sanitize($record);
            if(array()===$record||!$this->save($video_id,$record))return false;
            $this->record($record,'local_delivery_rebuild_started','info','The background worker started rebuilding local AWVP delivery files.',$now,'');
        }
        return true;
    }

    /** @param array<string,mixed> $job */
    public function complete_claimed_job(array $job, int $now): void
    {
        $this->finish($job,$now,true,'');
    }

    /** @param array<string,mixed> $job */
    public function fail_claimed_job(array $job, int $now, string $message): void
    {
        $this->finish($job,$now,false,$message);
    }

    /** @param array<string,mixed> $job */
    private function finish(array $job,int $now,bool $complete,string $message):void
    {
        $video_id=$this->video_for_attachment((int)($job['attachment_id']??0));$record=$video_id>0?$this->load($video_id):null;
        if(!is_array($record)||(int)($job['attachment_id']??0)!==(int)$record['attachment_id']
            ||(Local_Delivery_Rebuild_Request::PREPARED!==$record['status']&&(int)($job['id']??0)!==(int)$record['job_id'])
            ||(string)($job['source_signature']??'')!==$record['source_signature']||(string)($job['profile']??'')!==$record['profile'])return;
        $record['job_id']=(int)($job['id']??0);
        $record['status']=$complete?Local_Delivery_Rebuild_Request::COMPLETE:Local_Delivery_Rebuild_Request::FAILED;
        $record['updated_at']=$now;$record['completed_at']=$now;$record['error_message']=$complete?'':$message;$record=Local_Delivery_Rebuild_Request::sanitize($record);
        if(array()===$record)return;$this->save($video_id,$record);
        $this->record($record,$complete?'local_delivery_rebuild_complete':'local_delivery_rebuild_failed',$complete?'info':'error',$complete?'Local AWVP delivery rebuilding completed.':'Local AWVP delivery rebuilding failed.',$now,'');
    }

    /** @param array<string,mixed> $record */
    private function fail_prepared(int $video_id,array $record,int $now,string $message):void
    {
        $record['status']=Local_Delivery_Rebuild_Request::FAILED;$record['updated_at']=$now;$record['completed_at']=$now;$record['error_message']=$message;
        $record=Local_Delivery_Rebuild_Request::sanitize($record);if(array()!==$record)$this->save($video_id,$record);
    }

    private function video_for_attachment(int $attachment_id):int
    {
        if($attachment_id<1||!metadata_exists('post',$attachment_id,Video_Block_Editor_Service::ATTACHMENT_ASSET_META))return 0;
        return Video_Meta::sanitize_positive_id(get_post_meta($attachment_id,Video_Block_Editor_Service::ATTACHMENT_ASSET_META,true));
    }
    /** @return array<string,mixed>|null */
    private function load(int $video_id):?array{$r=Local_Delivery_Rebuild_Request::sanitize(get_post_meta($video_id,Video_Meta::LOCAL_DELIVERY_REBUILD_REQUEST,true));return array()===$r?null:$r;}
    /** @param array<string,mixed> $record */
    private function save(int $video_id,array $record):bool{update_post_meta($video_id,Video_Meta::LOCAL_DELIVERY_REBUILD_REQUEST,$record);return $record===Local_Delivery_Rebuild_Request::sanitize(get_post_meta($video_id,Video_Meta::LOCAL_DELIVERY_REBUILD_REQUEST,true));}
    /** @param array<string,mixed> $record */
    private function record(array $record,string $code,string $severity,string $message,int $now,string $operator_action):void
    {
        if(null===$this->events)return;$this->events->record((int)$record['video_id'],7,$code,$severity,$message,$now,0,'',0,Backend_Registry::LOCAL_ID,0,'',$operator_action,array('serving_health'=>'local_rebuild','operator_user_id'=>(int)$record['requested_by']));
    }
    /** @return array{status:string,job_id:int,message:string} */
    private static function result(string $status,int $job_id,string $message):array{return array('status'=>$status,'job_id'=>$job_id,'message'=>$message);}
}
// EOF
