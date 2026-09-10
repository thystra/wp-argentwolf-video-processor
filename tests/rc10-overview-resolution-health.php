<?php
/** RC10 Overview resolution, health, and disposition policy tests. */
declare(strict_types=1);

namespace ArgentVideo {
    $GLOBALS['awvp_overview_meta']=array();
    final class Video_Post_Type { public const POST_TYPE='argent_video'; }
    final class Video_Meta {
        public const DESTINATION='_destination'; public const PEERTUBE_PUBLICATION_EXECUTION='_execution';
        public const ATTACHMENT_ID='_attachment'; public const ORIGIN_POST_ID='_origin';
        public static function sanitize_positive_id(mixed $v):int{return is_numeric($v)&&((int)$v)>0?(int)$v:0;}
    }
    final class Backend_Registry { public const LOCAL_ID='local'; }
    final class Video_Destination {
        public static function resolve(mixed $v,bool $exists):array{unset($exists);return is_array($v)?$v:array();}
        public static function is_local(array $v):bool{return 'local'===($v['backend_id']??'');}
    }
    final class PeerTube_Publication_Execution { public static function sanitize(mixed $v):array{return is_array($v)?$v:array();} }
    final class PeerTube_Staged_Upload_State_Machine {
        public const PHASE_UPLOAD_INDETERMINATE='upload_indeterminate'; public const PHASE_FAILED='failed'; public const PHASE_COMPLETE='complete';
        public const PHASE_PROCESSING='processing'; public const PHASE_READY_VERIFIED='ready_verified';
    }
    final class PeerTube_Staged_Upload_Operation_Store {
        public function __construct(public array $ops=array()){} public function get(string $id):?array{return $this->ops[$id]??null;}
    }
    final class PeerTube_Incomplete_Work_Reconciler {
        public function status(int $id,?int $now=null,bool $initialize=false):array{unset($id,$now,$initialize);return array('status'=>'none','pending'=>false,'eligible'=>false,'resumable'=>false,'origin_at'=>0,'expires_at'=>0);}
        public function resume(int $id,int $now):bool{unset($id,$now);return true;}
    }
    final class PeerTube_Event_Repository { public function recent(int $video_id,int $limit=20):array{unset($video_id,$limit);return array();} }
    final class Serving_Viability {
        public const HEALTHY='healthy'; public const PROCESSING='processing'; public const MISSING='missing'; public const PRIVATE_OR_RESTRICTED='private_or_restricted';
        public const EMBED_DISALLOWED='embed_disallowed'; public const TEMPORARILY_UNAVAILABLE='temporarily_unavailable'; public const PROBE_INDETERMINATE='probe_indeterminate';
    }
    final class Remote_Publication_Health_Repository {
        public function __construct(public array $rows=array()){} public function for_video(int $id):array{unset($id);return $this->rows;}
    }
    final class Video_Serving_Service {
        public function serving_candidate(int $id):array{unset($id);return array('kind'=>'local','backend_id'=>'local','priority'=>0,'url'=>'https://example.test/source.mp4','remote_asset_id'=>0,'health_status'=>'healthy');}
    }
    final class Overview_Disposition_Store { public const REVIEWED='reviewed'; public const DISMISSED='dismissed'; }
    final class Settings_Hub { public const TAB_OVERVIEW='overview'; public static function tab_url(string $tab,array $args=array()):string{unset($tab,$args);return '/overview';} }
    function get_posts(array $args):array{unset($args);return array(101);}
    function get_post_meta(int $id,string $key,bool $single=true):mixed{unset($single);return $GLOBALS['awvp_overview_meta'][$id][$key]??'';}
    function metadata_exists(string $type,int $id,string $key):bool{unset($type);return array_key_exists($key,$GLOBALS['awvp_overview_meta'][$id]??array());}
    function get_the_title(int $id):string{return 20===$id?'Test 5 media':'Test 5 post';}
    function get_attached_file(int $id):string|false{unset($id);return false;}
    function get_post(int $id):object|false{unset($id);return (object)array('post_author'=>7);}
    function get_the_author_meta(string $field,int $id):string{unset($field,$id);return 'Alan';}
    function __(string $s,string $domain=''):string{unset($domain);return $s;}
}

namespace {
    require_once dirname(__DIR__).'/includes/PeerTube_Overview_Admin.php';
    use ArgentVideo\PeerTube_Overview_Admin; use ArgentVideo\PeerTube_Staged_Upload_Operation_Store; use ArgentVideo\PeerTube_Incomplete_Work_Reconciler;
    use ArgentVideo\PeerTube_Event_Repository; use ArgentVideo\Remote_Publication_Health_Repository; use ArgentVideo\Video_Serving_Service; use ArgentVideo\Video_Meta;
    $assert=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
    $op='upload_'.str_repeat('a',32);
    $GLOBALS['awvp_overview_meta'][101][Video_Meta::DESTINATION]=array('backend_id'=>'pt-primary','channel_id'=>'2');
    $GLOBALS['awvp_overview_meta'][101][Video_Meta::PEERTUBE_PUBLICATION_EXECUTION]=array('operation_id'=>$op,'remote_uuid'=>'uuid');
    $GLOBALS['awvp_overview_meta'][101][Video_Meta::ATTACHMENT_ID]=20;
    $GLOBALS['awvp_overview_meta'][101][Video_Meta::ORIGIN_POST_ID]=10;
    $GLOBALS['awvp_overview_meta'][20]['_wp_attached_file']='2026/09/test5.mp4';

    $store=new PeerTube_Staged_Upload_Operation_Store(array($op=>array('operation_id'=>$op,'phase'=>'ready_verified','record_revision'=>8,'source'=>array('bytes'=>100),'confirmed_bytes'=>100)));
    $overview=new PeerTube_Overview_Admin($store,new PeerTube_Incomplete_Work_Reconciler(),new PeerTube_Event_Repository(),new Remote_Publication_Health_Repository(),new Video_Serving_Service());
    $assert(array()===$overview->rows(1000),'A fully verified/settled ready_verified publication remained stale in Needs Attention.');

    $health=new Remote_Publication_Health_Repository(array(55=>array('remote_asset_id'=>55,'video_post_id'=>101,'backend_id'=>'pt-primary','status'=>'missing','eligible'=>0,'failure_since'=>'2026-09-10 20:00:00','http_status'=>404,'message'=>'The published URL returned 404.')));
    $overview=new PeerTube_Overview_Admin($store,new PeerTube_Incomplete_Work_Reconciler(),new PeerTube_Event_Repository(),$health,new Video_Serving_Service());
    $rows=$overview->rows(1000);
    $assert(1===count($rows),'A missing remote publication was not surfaced in Needs Attention.');
    $assert(true===($rows[0]['needs_attention']??false),'Remote health issue was not marked as needing attention.');
    $assert(str_contains((string)$rows[0]['status_label'],'missing'),'Remote missing status was not human-readable.');
    $assert(str_contains((string)$rows[0]['serving_label'],'WordPress original'),'Overview did not report the active local fallback.');
    $assert(64===strlen((string)$rows[0]['issue_fingerprint']),'Overview issue fingerprint is not stable SHA-256 presentation state.');

    $source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Overview_Admin.php');
    $assert(!str_contains($source,"esc_html_e('Review diagnostics'"),'Overview still emits the stale generic Review diagnostics action.');
    $assert(str_contains($source,"__('Mark reviewed'")&&str_contains($source,"__('Remove from list'")&&str_contains($source,'Reviewed items'),'Overview review/remove/collapsed-reviewed controls are missing.');
    fwrite(STDOUT,"RC10 Overview resolution/health tests passed.\n");
}
