<?php
/** File: includes/Remote_Republish_Request.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Durable exact intent for one operator-requested republish operation. */
final class Remote_Republish_Request
{
    public const VERSION = 1;
    public const PENDING = 'pending';
    public const DISPATCHED = 'dispatched';

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        $keys=array('version','request_id','target_backend_id','target_generation','target_destination','target_plan','source_identity','requested_by','requested_at','status','task_id','updated_at');
        if(!is_array($value)||$keys!==array_keys($value)||self::VERSION!==($value['version']??null))return array();
        $request_id=is_string($value['request_id']??null)?strtolower($value['request_id']):'';
        $backend=Backend_Identity::sanitize($value['target_backend_id']??null);
        $generation=self::positive_int($value['target_generation']??null);
        $destination=Video_Destination::sanitize($value['target_destination']??null);
        $plan=PeerTube_Publication_Plan::sanitize($value['target_plan']??null);
        $source=WordPress_Source_File::sanitize_identity($value['source_identity']??null);
        $requested_by=self::positive_int($value['requested_by']??null);
        $requested_at=self::positive_int($value['requested_at']??null);
        $status=is_string($value['status']??null)?$value['status']:'';
        $task_id=self::nonnegative_int($value['task_id']??null);
        $updated_at=self::positive_int($value['updated_at']??null);
        if(1!==preg_match('/^[a-f0-9]{32}$/D',$request_id)||''===$backend||Backend_Registry::LOCAL_ID===$backend||$generation<1
            ||array()===$destination||array()===$plan||array()===$source||$requested_by<1||$requested_at<1||$updated_at<$requested_at
            ||!in_array($status,array(self::PENDING,self::DISPATCHED),true)||null===$task_id
            ||$backend!==($destination['backend_id']??null)||$backend!==($plan['backend_id']??null)
            ||(string)($destination['channel_id']??'')!==(string)($plan['channel_id']??''))return array();
        if(self::PENDING===$status&&0!==$task_id)return array();
        if(self::DISPATCHED===$status&&$task_id<1)return array();
        return array(
            'version'=>self::VERSION,'request_id'=>$request_id,'target_backend_id'=>$backend,'target_generation'=>$generation,
            'target_destination'=>$destination,'target_plan'=>$plan,'source_identity'=>$source,'requested_by'=>$requested_by,
            'requested_at'=>$requested_at,'status'=>$status,'task_id'=>$task_id,'updated_at'=>$updated_at,
        );
    }

    private static function positive_int(mixed $v): int
    {
        if(is_int($v))return $v>0?$v:0;
        if(!is_string($v)||1!==preg_match('/^[1-9][0-9]*$/D',$v))return 0;
        $n=(int)$v;return $n>0&&(string)$n===$v?$n:0;
    }
    private static function nonnegative_int(mixed $v): ?int
    {
        if(is_int($v))return $v>=0?$v:null;
        if(!is_string($v)||1!==preg_match('/^(?:0|[1-9][0-9]*)$/D',$v))return null;
        $n=(int)$v;return $n>=0&&(string)$n===$v?$n:null;
    }
}
// EOF
