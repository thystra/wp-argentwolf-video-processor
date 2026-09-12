<?php
/** Dependency-free RC10 operator event repository tests. */
declare(strict_types=1);

namespace {
    if (! defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
    function wp_json_encode(mixed $value,int $flags=0,int $depth=512): string|false { return json_encode($value,$flags,$depth); }
}

namespace ArgentVideo {
    $GLOBALS['awvp_event_rows']=array();
    final class EventWpdb {
        public string $prefix='wp_';
        public int $insert_id=0;
        public function insert(string $table,array $data,array $format): int|false { if('wp_argent_video_events'!==$table||count($data)!==count($format))return false; $data['id']=++$this->insert_id; $GLOBALS['awvp_event_rows'][]=$data; return 1; }
        public function prepare(string $query,mixed ...$args): string { return json_encode(array('query'=>$query,'args'=>$args),JSON_THROW_ON_ERROR); }
        public function get_row(string $prepared,string $output): ?array { unset($output); $decoded=json_decode($prepared,true,8,JSON_THROW_ON_ERROR); $args=$decoded['args']; $task=(int)($args[1]??0); $rows=array_values(array_filter($GLOBALS['awvp_event_rows'],static fn(array $row):bool=>(int)($row['task_id']??0)===$task)); usort($rows,static fn(array $a,array $b):int=>(int)$b['id']<=>(int)$a['id']); return $rows[0]??null; }
        public function get_results(string $prepared,string $output): array { unset($output); $decoded=json_decode($prepared,true,8,JSON_THROW_ON_ERROR); $args=$decoded['args']; $query=(string)$decoded['query']; $rows=$GLOBALS['awvp_event_rows']; if(str_contains($query,'WHERE video_post_id = %d')){ $video=(int)($args[1]??0); $limit=(int)($args[2]??20); $rows=array_values(array_filter($rows,static fn(array $row):bool=>(int)$row['video_post_id']===$video)); } else { $limit=(int)($args[1]??100); } usort($rows,static fn(array $a,array $b):int=>(int)$b['id']<=>(int)$a['id']); return array_slice($rows,0,$limit); }
    }
}

namespace {
    require_once dirname(__DIR__).'/includes/Backend_Identity.php';
    require_once dirname(__DIR__).'/includes/Model_Activator.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Event_Repository.php';

    use ArgentVideo\PeerTube_Event_Repository;
    use ArgentVideo\EventWpdb;

    $GLOBALS['wpdb']=new EventWpdb();
    $assert=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}};
    $repo=new PeerTube_Event_Repository();
    $ok=$repo->record(
        101,4,'transfer_interrupted','error',
        'Transfer failed Authorization=Bearer-secret access_token=abc123 Bearer xyz987',
        2000,77,'upload_'.str_repeat('a',32),55,'pt-primary',429,
        'Retry is queued automatically.','Review the transfer details before resuming.',
        array(
            'remote_uuid'=>'5wwQY8261uRv7aPsVWhEiY',
            'service_status'=>'indeterminate',
            'error_status'=>'transport_timeout',
            'error_code'=>'curl_28',
            'confirmed_bytes'=>500,
            'source_bytes'=>1000,
            'operator_user_id'=>7,
            'access_token'=>'must-not-persist',
            'refresh_token'=>'must-not-persist',
            'authorization'=>'must-not-persist',
            'password'=>'must-not-persist',
            'request_body'=>'must-not-persist',
        )
    );
    $assert($ok,'Valid operator event did not persist.');
    $raw=$GLOBALS['awvp_event_rows'][0]??array();
    $serialized=json_encode($raw,JSON_UNESCAPED_SLASHES);
    foreach(array('must-not-persist','abc123','xyz987') as $secret){$assert(!str_contains((string)$serialized,$secret),'Secret-bearing diagnostics reached durable event storage: '.$secret);}
    $assert(429===($raw['http_status']??null)&&4===($raw['pipeline_step']??null),'HTTP status/pipeline step did not persist.');
    $assert('1970-01-01 00:33:20'===($raw['created_at']??null),'Event created_at was not written through the explicit format map.');

    $rows=$repo->recent(101,5);
    $assert(1===count($rows),'Recent event query did not return the persisted event.');
    $row=$rows[0];
    $assert('transfer_interrupted'===($row['event_code']??'')&&'pt-primary'===($row['backend_id']??''),'Recent event identity drifted.');
    $assert(500===($row['context']['confirmed_bytes']??null)&&1000===($row['context']['source_bytes']??null),'Bounded byte progress context was not preserved.');
    $assert(7===($row['context']['operator_user_id']??null),'Bounded operator actor identity was not preserved.');
    $assert(!array_key_exists('access_token',$row['context']??array()),'Sensitive context key survived the allow-list.');
    $assert($repo->record(102,6,'verified','info','Second video completed.',2010,78,'',56,'pt-secondary',200),'Second video event did not persist.');
    $global=$repo->recent_global(10);
    $assert(2===count($global)&&102===($global[0]['video_post_id']??0)&&101===($global[1]['video_post_id']??0),'Global bounded history did not return newest retained events across videos.');
    $task_row=$repo->latest_for_task(77);
    $assert(is_array($task_row)&&'transfer_interrupted'===($task_row['event_code']??''),'Exact task event lookup did not return the latest event.');
    $assert(null===$repo->latest_for_task(999),'Unknown task event lookup did not return null.');
    $assert(false===$repo->record(0,4,'bad','error','bad',2000),'Invalid video identity was accepted.');
    $assert(false===$repo->record(101,8,'bad','error','bad',2000),'Invalid pipeline step was accepted.');

    fwrite(STDOUT,"RC10 PeerTube operator event repository tests passed.\n");
}
