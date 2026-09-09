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
    final class Video_Destination {
        public static function resolve(mixed $v,bool $exists):array{unset($exists);return is_array($v)?$v:array();}
        public static function is_local(array $v):bool{return 'local'===($v['backend_id']??'');}
    }
    final class PeerTube_Publication_Execution { public static function sanitize(mixed $v):array{return is_array($v)?$v:array();} }
    final class PeerTube_Staged_Upload_State_Machine {
        public const PHASE_UPLOAD_INDETERMINATE='upload_indeterminate'; public const PHASE_FAILED='failed';
        public const PHASE_COMPLETE='complete'; public const PHASE_PROCESSING='processing'; public const PHASE_READY_VERIFIED='ready_verified';
    }
    final class PeerTube_Staged_Upload_Operation_Store {
        public function __construct(private array $ops=array()){}
        public function get(string $id):?array{return $this->ops[$id]??null;}
    }
    final class PeerTube_Incomplete_Work_Reconciler {
        public function status(int $id,?int $now=null,bool $initialize=false):array{unset($id,$now,$initialize);return array('pending'=>false,'eligible'=>false,'resumable'=>false);}
        public function resume(int $id,int $now):bool{unset($id,$now);return true;}
    }
    final class Settings_Hub { public const TAB_OVERVIEW='overview'; public static function tab_url(string $tab,array $args=array()):string{unset($tab,$args);return '/overview';} }
    function get_posts(array $args):array{unset($args);return array(101);}
    function get_post_meta(int $id,string $key,bool $single=true):mixed{unset($single);return $GLOBALS['awvp_rc9_overview_meta'][$id][$key]??'';}
    function metadata_exists(string $type,int $id,string $key):bool{unset($type);return array_key_exists($key,$GLOBALS['awvp_rc9_overview_meta'][$id]??array());}
    function get_the_title(int $id):string{return 20===$id?'Field video':'Farm update';}
    function get_attached_file(int $id):string|false{return 20===$id?'/tmp/awvp-overview-source.mp4':false;}
    function get_post(int $id):object|false{return $GLOBALS['awvp_rc9_overview_posts'][$id]??false;}
    function get_the_author_meta(string $field,int $id):string{unset($field);return 7===$id?'Alan':'';}
    function size_format(int|float $bytes,int $decimals=0):string{unset($decimals);return (string)$bytes.' B';}
    function __(string $s,string $domain=''):string{unset($domain);return $s;}
}

namespace {
    require_once dirname(__DIR__).'/includes/PeerTube_Overview_Admin.php';
    use ArgentVideo\PeerTube_Overview_Admin; use ArgentVideo\PeerTube_Staged_Upload_Operation_Store; use ArgentVideo\PeerTube_Incomplete_Work_Reconciler; use ArgentVideo\Video_Meta;
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
    $overview=new PeerTube_Overview_Admin($store,new PeerTube_Incomplete_Work_Reconciler());
    $rows=$overview->rows(1000);
    $assert(1===count($rows),'Overview did not surface the indeterminate PeerTube upload.');
    $row=$rows[0];
    $assert('Field video'===$row['media_title']&&'family-trip.mp4'===$row['filename']&&'Farm update'===$row['post_title']&&'Alan'===$row['author'],'Overview primary identity is not human-recognizable Media Library/post data.');
    $assert(str_contains((string)$row['progress'],'Uploaded 500 B / 1000 B (50%)'),'Overview upload byte progress drifted.');
    $assert('Upload outcome needs attention'===$row['status_label'],'Overview did not classify upload_indeterminate as needs attention.');
    $source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Overview_Admin.php');
    $assert(str_contains($source,'<details')&&str_contains($source,"'operation_id='")&&str_contains($source,"'remote_uuid='"),'Internal operation/remote IDs are not relegated to expandable diagnostics.');
    $assert(str_contains($source,"__('Resume'")&&str_contains($source,'$row[\'resumable\']'),'Overview does not expose Resume only through bounded recovery eligibility.');
    @unlink('/tmp/awvp-overview-source.mp4');
    fwrite(STDOUT,"RC9 Overview/Status policy tests passed.\n");
}
