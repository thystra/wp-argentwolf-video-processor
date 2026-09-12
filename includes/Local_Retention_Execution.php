<?php
/** File: includes/Local_Retention_Execution.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Durable, non-secret local cleanup journal. */
final class Local_Retention_Execution
{
    public const VERSION = 2;
    public const LEGACY_VERSION = 1;
    public const PROOF_REMOTE = 'remote';
    public const PROOF_LOCAL_HLS = 'local_hls';
    public const STATUS_QUEUED='queued';
    public const STATUS_RUNNING='running';
    public const STATUS_COMPLETE='complete';
    public const STATUS_BLOCKED='blocked';
    public const STATUS_FAILED='failed';

    /** Backward-compatible remote-proof constructor. @return array<string,mixed> */
    public static function create(int $video_id,int $attachment_id,array $policy,array $authority,array $source,int $attempt,int $eligible_at,int $now):array
    {
        return self::create_remote($video_id,$attachment_id,$policy,$authority,$source,$attempt,$eligible_at,$now);
    }

    /** @return array<string,mixed> */
    public static function create_remote(int $video_id,int $attachment_id,array $policy,array $authority,array $source,int $attempt,int $eligible_at,int $now):array
    {
        $policy=Local_Retention_Policy::sanitize($policy);
        $authority=Video_Serving_Authority::sanitize($authority);
        $source=WordPress_Source_File::sanitize_identity($source);
        if($video_id<1||$attachment_id<1||array()===$policy||!Local_Retention_Policy::destructive($policy)||array()===$authority
            ||$attempt<1||$eligible_at<1||$now<1||(Local_Retention_Policy::deletes_source($policy)&&array()===$source))return array();
        return self::sanitize(array(
            'version'=>self::VERSION,
            'attempt'=>$attempt,
            'video_id'=>$video_id,
            'attachment_id'=>$attachment_id,
            'policy_sha256'=>Local_Retention_Policy::sha256($policy),
            'mode'=>$policy['mode'],
            'proof_kind'=>self::PROOF_REMOTE,
            'proof_sha256'=>self::authority_sha256($authority),
            'backend_id'=>(string)$authority['backend_id'],
            'serving_generation'=>(int)$authority['generation'],
            'plan_sha256'=>(string)$authority['plan_sha256'],
            'manifest_sha256'=>(string)$authority['manifest_sha256'],
            'remote_asset_id'=>(int)$authority['remote_asset_id'],
            'remote_uuid'=>(string)$authority['remote_uuid'],
            'local_delivery'=>array(),
            'source'=>$source,
            'eligible_at'=>$eligible_at,
            'status'=>self::STATUS_QUEUED,
            'task_id'=>0,
            'prepared_at'=>$now,
            'completed_at'=>0,
            'last_error'=>'',
        ));
    }

    /** @return array<string,mixed> */
    public static function create_local(int $video_id,int $attachment_id,array $policy,array $delivery,array $source,int $attempt,int $eligible_at,int $now):array
    {
        $policy=Local_Retention_Policy::sanitize($policy);
        $delivery=Local_Delivery_Evidence::sanitize($delivery);
        $source=WordPress_Source_File::sanitize_identity($source);
        if($video_id<1||$attachment_id<1||Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY!==($policy['mode']??null)
            ||array()===$delivery||$attachment_id!==(int)$delivery['attachment_id']||array()===$source||$attempt<1||$eligible_at<1||$now<1)return array();
        return self::sanitize(array(
            'version'=>self::VERSION,
            'attempt'=>$attempt,
            'video_id'=>$video_id,
            'attachment_id'=>$attachment_id,
            'policy_sha256'=>Local_Retention_Policy::sha256($policy),
            'mode'=>$policy['mode'],
            'proof_kind'=>self::PROOF_LOCAL_HLS,
            'proof_sha256'=>Local_Delivery_Evidence::sha256($delivery),
            'backend_id'=>Backend_Registry::LOCAL_ID,
            'serving_generation'=>0,
            'plan_sha256'=>'',
            'manifest_sha256'=>'',
            'remote_asset_id'=>0,
            'remote_uuid'=>'',
            'local_delivery'=>$delivery,
            'source'=>$source,
            'eligible_at'=>$eligible_at,
            'status'=>self::STATUS_QUEUED,
            'task_id'=>0,
            'prepared_at'=>$now,
            'completed_at'=>0,
            'last_error'=>'',
        ));
    }

    /** @return array<string,mixed> */
    public static function sanitize(mixed $v):array
    {
        if(!is_array($v)||!is_int($v['version']??null))return array();
        if(self::LEGACY_VERSION===$v['version'])return self::sanitize_legacy($v);
        if(self::VERSION!==$v['version'])return array();
        $keys=array('version','attempt','video_id','attachment_id','policy_sha256','mode','proof_kind','proof_sha256','backend_id','serving_generation','plan_sha256','manifest_sha256','remote_asset_id','remote_uuid','local_delivery','source','eligible_at','status','task_id','prepared_at','completed_at','last_error');
        if($keys!==array_keys($v))return array();
        foreach(array('attempt','video_id','attachment_id','eligible_at','prepared_at') as $k){if(!is_int($v[$k])||$v[$k]<1)return array();}
        foreach(array('serving_generation','remote_asset_id','task_id','completed_at') as $k){if(!is_int($v[$k])||$v[$k]<0)return array();}
        foreach(array('policy_sha256','proof_sha256') as $k){if(!is_string($v[$k])||1!==preg_match('/^[a-f0-9]{64}$/D',$v[$k]))return array();}
        if(!is_string($v['mode'])||!in_array($v['mode'],array(Local_Retention_Policy::MODE_DELETE_MANAGED,Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY,Local_Retention_Policy::MODE_DELETE_ALL),true))return array();
        if(!is_string($v['proof_kind'])||!in_array($v['proof_kind'],array(self::PROOF_REMOTE,self::PROOF_LOCAL_HLS),true))return array();
        if(!is_string($v['backend_id'])||''===Backend_Identity::sanitize($v['backend_id']))return array();
        if(!is_string($v['plan_sha256'])||!is_string($v['manifest_sha256'])||!is_string($v['remote_uuid']))return array();
        if(!is_string($v['status'])||!in_array($v['status'],array(self::STATUS_QUEUED,self::STATUS_RUNNING,self::STATUS_COMPLETE,self::STATUS_BLOCKED,self::STATUS_FAILED),true))return array();
        if(!is_string($v['last_error'])||strlen($v['last_error'])>1000)return array();
        $source=WordPress_Source_File::sanitize_identity($v['source']);
        $source_required=in_array($v['mode'],array(Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY,Local_Retention_Policy::MODE_DELETE_ALL),true);
        if($source_required&&array()===$source)return array();
        if(!$source_required&&array()!==$source)return array();
        $delivery=Local_Delivery_Evidence::sanitize($v['local_delivery']);
        if(self::PROOF_REMOTE===$v['proof_kind']){
            if(Backend_Registry::LOCAL_ID===$v['backend_id']||$v['serving_generation']<1||$v['remote_asset_id']<1
                ||1!==preg_match('/^[a-f0-9]{64}$/D',$v['plan_sha256'])||1!==preg_match('/^[a-f0-9]{64}$/D',$v['manifest_sha256'])
                ||1!==preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$v['remote_uuid'])||array()!==$delivery)return array();
        }else{
            if(Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY!==$v['mode']||Backend_Registry::LOCAL_ID!==$v['backend_id']
                ||0!==$v['serving_generation']||0!==$v['remote_asset_id']||''!==$v['plan_sha256']||''!==$v['manifest_sha256']||''!==$v['remote_uuid']
                ||array()===$delivery||(int)$delivery['attachment_id']!==$v['attachment_id']||!hash_equals($v['proof_sha256'],Local_Delivery_Evidence::sha256($delivery)))return array();
        }
        $v['source']=$source;$v['local_delivery']=$delivery;return $v;
    }

    public static function immutable_sha256(array $r):string
    {
        $r=self::sanitize($r);if(array()===$r)return'';
        if(self::LEGACY_VERSION===$r['version']){
            $data=array_intersect_key($r,array_flip(array('version','attempt','video_id','attachment_id','policy_sha256','mode','authority_sha256','serving_generation','plan_sha256','manifest_sha256','remote_asset_id','remote_uuid','source','eligible_at','prepared_at')));
            $json=wp_json_encode($data,JSON_UNESCAPED_SLASHES);return is_string($json)?hash('sha256','awvp-local-retention-execution:v1:'.$json):'';
        }
        $data=array_intersect_key($r,array_flip(array('version','attempt','video_id','attachment_id','policy_sha256','mode','proof_kind','proof_sha256','backend_id','serving_generation','plan_sha256','manifest_sha256','remote_asset_id','remote_uuid','local_delivery','source','eligible_at','prepared_at')));
        $json=wp_json_encode($data,JSON_UNESCAPED_SLASHES);return is_string($json)?hash('sha256','awvp-local-retention-execution:v2:'.$json):'';
    }

    /** @return array<string,mixed> */
    public static function with_task(array $r,int $task_id):array{$r=self::sanitize($r);if(array()===$r||$task_id<1)return array();$r['task_id']=$task_id;return self::sanitize($r);}

    /** @return array<string,mixed> */
    public static function transition(array $r,string $status,int $now,string $error=''):array
    {
        $r=self::sanitize($r);if(array()===$r||$now<1||!in_array($status,array(self::STATUS_RUNNING,self::STATUS_COMPLETE,self::STATUS_BLOCKED,self::STATUS_FAILED),true))return array();
        $r['status']=$status;$r['last_error']=substr(sanitize_text_field($error),0,1000);$r['completed_at']=in_array($status,array(self::STATUS_COMPLETE,self::STATUS_BLOCKED,self::STATUS_FAILED),true)?$now:0;return self::sanitize($r);
    }

    public static function authority_sha256(array $a):string{$a=Video_Serving_Authority::sanitize($a);if(array()===$a)return'';$j=wp_json_encode($a,JSON_UNESCAPED_SLASHES);return is_string($j)?hash('sha256','awvp-serving-authority:v1:'.$j):'';}
    public static function proof_kind(array $r):string{$r=self::sanitize($r);if(array()===$r)return'';return self::LEGACY_VERSION===$r['version']?self::PROOF_REMOTE:(string)$r['proof_kind'];}
    public static function proof_sha256(array $r):string{$r=self::sanitize($r);if(array()===$r)return'';return self::LEGACY_VERSION===$r['version']?(string)$r['authority_sha256']:(string)$r['proof_sha256'];}
    public static function task_backend_id(array $r):?string{$r=self::sanitize($r);if(array()===$r)return null;return self::LEGACY_VERSION===$r['version']?null:(string)$r['backend_id'];}
    public static function task_remote_asset_id(array $r):?int{$r=self::sanitize($r);if(array()===$r)return null;$id=(int)$r['remote_asset_id'];return $id>0?$id:null;}
    public static function local_delivery(array $r):array{$r=self::sanitize($r);return array()!==$r&&self::VERSION===$r['version']&&self::PROOF_LOCAL_HLS===$r['proof_kind']?(array)$r['local_delivery']:array();}

    /** @return array<string,mixed> */
    private static function sanitize_legacy(array $v):array
    {
        $keys=array('version','attempt','video_id','attachment_id','policy_sha256','mode','authority_sha256','serving_generation','plan_sha256','manifest_sha256','remote_asset_id','remote_uuid','source','eligible_at','status','task_id','prepared_at','completed_at','last_error');
        if($keys!==array_keys($v))return array();
        foreach(array('attempt','video_id','attachment_id','serving_generation','remote_asset_id','eligible_at','prepared_at') as $k){if(!is_int($v[$k])||$v[$k]<1)return array();}
        if(!is_int($v['task_id'])||$v['task_id']<0||!is_int($v['completed_at'])||$v['completed_at']<0)return array();
        foreach(array('policy_sha256','authority_sha256','plan_sha256','manifest_sha256') as $k){if(!is_string($v[$k])||1!==preg_match('/^[a-f0-9]{64}$/D',$v[$k]))return array();}
        if(!is_string($v['mode'])||!in_array($v['mode'],array(Local_Retention_Policy::MODE_DELETE_MANAGED,Local_Retention_Policy::MODE_DELETE_ALL),true))return array();
        if(!is_string($v['remote_uuid'])||1!==preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/D',$v['remote_uuid']))return array();
        if(!is_string($v['status'])||!in_array($v['status'],array(self::STATUS_QUEUED,self::STATUS_RUNNING,self::STATUS_COMPLETE,self::STATUS_BLOCKED,self::STATUS_FAILED),true))return array();
        if(!is_string($v['last_error'])||strlen($v['last_error'])>1000)return array();
        $source=WordPress_Source_File::sanitize_identity($v['source']);
        if(Local_Retention_Policy::MODE_DELETE_ALL===$v['mode']&&array()===$source)return array();
        if(Local_Retention_Policy::MODE_DELETE_MANAGED===$v['mode']&&array()!==$source)return array();
        $v['source']=$source;return $v;
    }
}
// EOF
