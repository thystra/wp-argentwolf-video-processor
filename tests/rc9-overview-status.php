<?php
/** Dependency-free RC9 Overview/Status policy tests. */
declare(strict_types=1);

namespace ArgentVideo {
    $GLOBALS['awvp_rc9_overview_meta']=array();
    $GLOBALS['awvp_rc9_overview_posts']=array();
    final class Video_Post_Type { public const POST_TYPE='argent_video'; }
    final class Video_Meta {
        public const DESTINATION='_destination'; public const PEERTUBE_PUBLICATION_EXECUTION='_execution';
        public const ATTACHMENT_ID='_attachment'; public const ORIGIN_POST_ID='_origin';
        public static function sanitize_positive_id(mixed $v):int{return is_int($v)&&$v>0?$v:0;}
    }
    final class Backend_Registry { public const LOCAL_ID='local'; }
    final class Serving_Viability {
        public const HEALTHY='healthy'; public const PROCESSING='processing'; public const MISSING='missing';
        public const PRIVATE_OR_RESTRICTED='private_or_restricted'; public const EMBED_DISALLOWED='embed_disallowed';
        public const TEMPORARILY_UNAVAILABLE='temporarily_unavailable'; public const PROBE_INDETERMINATE='probe_indeterminate';
    }
    final class Video_Destination {
        public static function resolve(mixed $v,bool $exists):array{unset($exists);return is_array($v)?$v:array();}
        public static function is_local(array $v):bool{return 'local'===($v['backend_id']??'');}
    }
    final class PeerTube_Publication_Execution { public static function sanitize(mixed $v):array{return is_array($v)?$v:array();} }
    final class PeerTube_Staged_Upload_State_Machine {
        public const PHASE_UPLOAD_INDETERMINATE='upload_indeterminate'; public const PHASE_OPERATOR_ABANDONED='operator_abandoned'; public const PHASE_FAILED='failed';
        public const PHASE_COMPLETE='complete'; public const PHASE_PROCESSING='processing'; public const PHASE_READY_VERIFIED='ready_verified'; public const REQUEST_INIT='init';
    }
    final class PeerTube_Staged_Upload_Operation_Store {
        public function __construct(private array $ops=array()){}
        public function get(string $id):?array{return $this->ops[$id]??null;}
    }
    final class PeerTube_Incomplete_Work_Reconciler {
        public array $statuses=array();
        public function status(int $id,?int $now=null,bool $initialize=false):array{unset($now,$initialize);return $this->statuses[$id]??array('status'=>'none','pending'=>false,'eligible'=>false,'resumable'=>false);}
        public function resume(int $id,int $now):bool{unset($id,$now);return true;}
    }
    final class PeerTube_Event_Repository {
        public function recent(int $video_id,int $limit=20):array{unset($limit);return 101===$video_id?array(array('id'=>1,'video_post_id'=>101,'task_id'=>88,'operation_id'=>'upload_'.str_repeat('a',32),'remote_asset_id'=>55,'backend_id'=>'pt-primary','pipeline_step'=>4,'event_code'=>'transport_timeout','severity'=>'error','http_status'=>429,'message'=>'Transfer interrupted after 500 bytes.','automatic_action'=>'Automatic replay stopped.','operator_action'=>'Review the upload outcome.','context'=>array(),'created_at'=>'2026-09-10 16:00:00')):array();}
    }
    final class Settings_Hub { public const TAB_OVERVIEW='overview'; public static function tab_url(string $tab,array $args=array()):string{unset($tab,$args);return '/overview';} }
    function get_posts(array $args):array{unset($args);return array(101);}
    function get_post_meta(int $id,string $key,bool $single=true):mixed{unset($single);return $GLOBALS['awvp_rc9_overview_meta'][$id][$key]??'';}
    function metadata_exists(string $type,int $id,string $key):bool{unset($type);return array_key_exists($key,$GLOBALS['awvp_rc9_overview_meta'][$id]??array());}
    function get_the_title(int $id):string{return 20===$id?'Field video':'Farm update';}
    function get_attached_file(int $id):string|false{return 20===$id?'/tmp/awvp-overview-source.mp4':false;}
    function get_post(int $id):object|false{return $GLOBALS['awvp_rc9_overview_posts'][$id]??false;}
    function get_the_author_meta(string $field,int $id):string{unset($field);return 7===$id?'Alan':'';}
    function wp_filesize(string $path):int{return (int)filesize($path);}
    function size_format(int|float $bytes,int $decimals=0):string{unset($decimals);return (string)$bytes.' B';}
    function __(string $s,string $domain=''):string{unset($domain);return $s;}
}

namespace {
    require_once dirname(__DIR__).'/includes/PeerTube_Overview_Admin.php';
    use ArgentVideo\PeerTube_Overview_Admin; use ArgentVideo\PeerTube_Staged_Upload_Operation_Store; use ArgentVideo\PeerTube_Incomplete_Work_Reconciler; use ArgentVideo\PeerTube_Event_Repository; use ArgentVideo\Video_Meta;
    $assert=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
    file_put_contents('/tmp/awvp-overview-source.mp4',str_repeat('x',1234));
    $op='upload_'.str_repeat('a',32); $uuid='123e4567-e89b-42d3-a456-426614174000';
    $GLOBALS['awvp_rc9_overview_meta'][101][Video_Meta::DESTINATION]=array('backend_id'=>'pt-primary','channel_id'=>'41');
    $GLOBALS['awvp_rc9_overview_meta'][101][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=array('operation_id'=>$op,'remote_uuid'=>$uuid);
    $GLOBALS['awvp_rc9_overview_meta'][101][Video_Meta::ATTACHMENT_ID]=20;
    $GLOBALS['awvp_rc9_overview_meta'][101][Video_Meta::ORIGIN_POST_ID]=10;
    $GLOBALS['awvp_rc9_overview_meta'][20]['_wp_attached_file']='2026/09/family-trip.mp4';
    $GLOBALS['awvp_rc9_overview_posts'][10]=(object)array('post_author'=>7);
    $store=new PeerTube_Staged_Upload_Operation_Store(array($op=>array('operation_id'=>$op,'phase'=>'upload_indeterminate','source'=>array('bytes'=>1000),'confirmed_bytes'=>500)));
    $recovery=new PeerTube_Incomplete_Work_Reconciler();
    $overview=new PeerTube_Overview_Admin($store,$recovery,new PeerTube_Event_Repository());
    $rows=$overview->rows(1000);
    $assert(1===count($rows),'Overview did not surface the indeterminate PeerTube upload.');
    $row=$rows[0];
    $assert('Field video'===$row['media_title']&&'family-trip.mp4'===$row['filename']&&'Farm update'===$row['post_title']&&'Alan'===$row['author'],'Overview primary identity is not human-recognizable Media Library/post data.');
    $assert(str_contains((string)$row['progress'],'Uploaded 500 B / 1000 B (50%)'),'Overview upload byte progress drifted.');
    $assert('Upload outcome needs attention'===$row['status_label'],'Overview did not classify upload_indeterminate as needs attention.');
    $assert(4===($row['latest_event']['pipeline_step']??0)&&429===($row['latest_event']['http_status']??0),'Overview did not expose the latest seven-step/HTTP operator event.');

    // RC12 finalizer no-mutation recovery is a first-class attention row even
    // when the upload journal is already terminal ready_verified.
    $store2=new PeerTube_Staged_Upload_Operation_Store(array($op=>array('operation_id'=>$op,'phase'=>'ready_verified','source'=>array('bytes'=>1000),'confirmed_bytes'=>1000)));
    $recovery2=new PeerTube_Incomplete_Work_Reconciler();
    $recovery2->statuses[101]=array('status'=>'finalizer_retry','pending'=>true,'eligible'=>false,'resumable'=>true);
    $overview2=new PeerTube_Overview_Admin($store2,$recovery2,new PeerTube_Event_Repository());
    $rows2=$overview2->rows(1000);
    $assert(1===count($rows2)&&true===($rows2[0]['resumable']??false),'RC12 safe failed finalizer did not remain visible after upload reached ready_verified.');
    $assert('Publication finalization is ready to retry'===($rows2[0]['status_label']??''),'RC12 safe failed finalizer did not receive a recognizable status label.');
    $source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Overview_Admin.php');
    $assert(str_contains($source,'<details')&&str_contains($source,"Details & log")&&str_contains($source,'Step %1$d of 7')&&str_contains($source,"'operation_id='")&&str_contains($source,"'remote_uuid='"),'Operator event history/technical IDs are not relegated to Details & log.');
    $assert(str_contains($source,"__('Resume'")&&str_contains($source,"__('Retry publication'")&&str_contains($source,'$row[\'resumable\']'),'Overview does not expose Resume/Retry publication only through bounded recovery eligibility.');
    $assert(str_contains($source,'sanitize_text_field(wp_unslash($_POST[\'video_id\']))'),'Overview Resume video ID is not sanitized with a WordPress-recognized sanitizer.');
    $assert(str_contains($source,'wp_filesize($file)')&&0===preg_match('/(?<!wp_)filesize\(\$file\)/',$source),'Overview media sizing bypasses the WordPress filesystem wrapper.');
    @unlink('/tmp/awvp-overview-source.mp4');
    fwrite(STDOUT,"RC9 Overview/Status policy tests passed.\n");
}
