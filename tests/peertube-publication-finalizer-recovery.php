<?php
/** Dependency-free RC12 safe finalizer retry tests. */
declare(strict_types=1);

namespace ArgentVideo {
    $GLOBALS['awvp_rc12_meta']=array();

    final class Video_Meta {
        public const PEERTUBE_PUBLICATION_LIFECYCLE='_lifecycle';
        public const PEERTUBE_PUBLICATION_EXECUTION='_execution';
    }
    final class PeerTube_Publication_Lifecycle {
        public static function sanitize(mixed $value): array { return is_array($value)?$value:array(); }
    }
    final class PeerTube_Publication_Execution {
        public static function sanitize(mixed $value): array { return is_array($value)?$value:array(); }
    }
    final class PeerTube_Staged_Upload_State_Machine {
        public const PHASE_READY_VERIFIED='ready_verified';
        public static function valid(mixed $value): bool { return is_array($value)&&isset($value['phase'],$value['remote_asset_id'],$value['remote_identity']); }
    }
    final class PeerTube_Publication_Task_Coordinator {
        public const TASK_FINALIZE='peertube_publication_finalize';
        public const PAYLOAD_VERSION=1;
        public static function finalize_idempotency_key(int $video_id,int $generation,string $sha): string {
            return $video_id>0&&$generation>0&&1===preg_match('/^[a-f0-9]{64}$/D',$sha)
                ? hash('sha256','awvp-task:v1:'.self::TASK_FINALIZE.':'.$video_id.':'.$generation.':'.$sha):'';
        }
    }
    final class Task_Repository {
        public const STATUS_FAILED='failed';
        public const APPLIED='applied';
        public const PRESENT='present';
        public const INDETERMINATE='indeterminate';
        public array $task=array(); public array $retry_calls=array(); public string $retry_result=self::APPLIED;
        public function find_by_idempotency_key(string $key): ?array { return ($this->task['idempotency_key']??'')===$key?$this->task:null; }
        public function retry_failed_exact(int $id,string $type,string $key,int $now): string { $this->retry_calls[]=compact('id','type','key','now'); return $this->retry_result; }
    }
    final class PeerTube_Staged_Upload_Operation_Store {
        public array $operation=array();
        public function get(string $id): ?array { return ($this->operation['operation_id']??'')===$id?$this->operation:null; }
    }
    final class PeerTube_Event_Repository {
        public ?array $event=null;
        public function latest_for_task(int $task_id): ?array { return is_array($this->event)&&($this->event['task_id']??0)===$task_id?$this->event:null; }
    }
    function get_post_meta(int $id,string $key,bool $single=true): mixed { unset($single); return $GLOBALS['awvp_rc12_meta'][$id][$key]??''; }
}

namespace {
    require_once dirname(__DIR__).'/includes/PeerTube_Publication_Finalizer_Recovery.php';
    use ArgentVideo\PeerTube_Publication_Finalizer_Recovery as Recovery;
    use ArgentVideo\PeerTube_Publication_Task_Coordinator as Coordinator;
    use ArgentVideo\PeerTube_Staged_Upload_Operation_Store as Operations;
    use ArgentVideo\PeerTube_Event_Repository as Events;
    use ArgentVideo\Task_Repository as Tasks;
    use ArgentVideo\Video_Meta;

    $assert=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}};
    $video=7999;$sha=str_repeat('a',64);$key=Coordinator::finalize_idempotency_key($video,2,$sha);
    $GLOBALS['awvp_rc12_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]=array(
        'generation'=>2,'plan_sha256'=>$sha,'backend_id'=>'argentwolf-pt','anchor_post_id'=>7997,
        'wordpress_status'=>'publish','upload_authorized'=>true,'reveal_authorized'=>true,'target_privacy_id'=>'1','task_pending'=>false,
    );
    $GLOBALS['awvp_rc12_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=array(
        'backend_id'=>'argentwolf-pt','operation_id'=>'upload_'.str_repeat('b',32),'remote_asset_id'=>3,
        'remote_uuid'=>'8dbc39db-b691-4cd0-b6a9-30e6b3a0da90','applied_manifest_sha256'=>'',
        'manifest'=>array('plan_sha256'=>$sha),
    );
    $tasks=new Tasks();
    $tasks->task=array(
        'id'=>29,'task_type'=>Coordinator::TASK_FINALIZE,'video_post_id'=>$video,'backend_id'=>'argentwolf-pt',
        'idempotency_key'=>$key,'status'=>Tasks::STATUS_FAILED,'attempts'=>1,'max_attempts'=>720,
        'payload_json'=>json_encode(array('version'=>1,'generation'=>2,'plan_sha256'=>$sha),JSON_UNESCAPED_SLASHES),
    );
    $ops=new Operations();
    $ops->operation=array(
        'operation_id'=>'upload_'.str_repeat('b',32),'phase'=>'ready_verified','remote_asset_id'=>3,
        'remote_identity'=>array('uuid'=>'8dbc39db-b691-4cd0-b6a9-30e6b3a0da90'),
    );
    $events=new Events();$events->event=array('task_id'=>29,'event_code'=>'mutation_not_sent');
    $recovery=new Recovery($tasks,$ops,$events);
    $state=$recovery->status($video);
    $assert(Recovery::RESUMABLE===($state['status']??'')&&29===($state['task_id']??0)&&7997===($state['anchor_post_id']??0),'Safe mutation-not-sent finalizer was not recognized as resumable.');
    $assert(Tasks::APPLIED===$recovery->resume($video,3000),'Safe finalizer retry was not requeued.');
    $assert(1===count($tasks->retry_calls)&&29===$tasks->retry_calls[0]['id']&&Coordinator::TASK_FINALIZE===$tasks->retry_calls[0]['type'],'Retry did not preserve the exact failed task identity.');

    $events->event=array('task_id'=>29,'event_code'=>'mutation_indeterminate');
    $assert(Recovery::NONE===($recovery->status($video)['status']??''),'Indeterminate provider mutation was incorrectly marked safe to retry.');
    $events->event=array('task_id'=>29,'event_code'=>'mutation_not_sent');
    $ops->operation['phase']='processing';
    $assert(Recovery::NONE===($recovery->status($video)['status']??''),'Non-ready upload was incorrectly marked safe to retry.');
    $ops->operation['phase']='ready_verified';
    $GLOBALS['awvp_rc12_meta'][$video][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]['applied_manifest_sha256']=str_repeat('c',64);
    $assert(Recovery::NONE===($recovery->status($video)['status']??''),'Already-applied publication was incorrectly marked safe to retry.');

    $source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Publication_Finalizer_Recovery.php');
    foreach(array('update_publication(','update_privacy(','wp_remote_','PeerTube_Api_Client') as $forbidden){$assert(!str_contains($source,$forbidden),'Finalizer recovery acquired provider mutation/network authority: '.$forbidden);}

    fwrite(STDOUT,"RC12 PeerTube finalizer recovery tests passed.\n");
}
