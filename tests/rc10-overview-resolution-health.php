<?php
/** RC10 Overview resolution, health, and disposition policy tests. */
declare(strict_types=1);

namespace ArgentVideo {
    $GLOBALS['awvp_overview_meta']=array();
    final class Video_Post_Type { public const POST_TYPE='argent_video'; }
    final class Video_Meta {
        public const DESTINATION='_destination'; public const PEERTUBE_PUBLICATION_EXECUTION='_execution';
        public const ATTACHMENT_ID='_attachment'; public const ORIGIN_POST_ID='_origin'; public const SERVING_AUTHORITY='_authority';
        public static function sanitize_positive_id(mixed $v):int{return is_numeric($v)&&((int)$v)>0?(int)$v:0;}
    }
    final class Backend_Registry { public const LOCAL_ID='local'; }
    final class Backend_Identity { public static function sanitize(mixed $v):string{return is_string($v)&&1===preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D',$v)?$v:'';} }
    final class Video_Serving_Authority { public static function sanitize(mixed $v):array{return is_array($v)&&isset($v['remote_asset_id'],$v['backend_id'])?$v:array();} }
    final class Video_Destination {
        public static function resolve(mixed $v,bool $exists):array{unset($exists);return is_array($v)?$v:array();}
        public static function is_local(array $v):bool{return 'local'===($v['backend_id']??'');}
    }
    final class PeerTube_Publication_Execution { public static function sanitize(mixed $v):array{return is_array($v)?$v:array();} }
    final class PeerTube_Staged_Upload_State_Machine {
        public const REQUEST_INIT='init';
        public const PHASE_UPLOAD_INDETERMINATE='upload_indeterminate'; public const PHASE_OPERATOR_ABANDONED='operator_abandoned'; public const PHASE_FAILED='failed'; public const PHASE_COMPLETE='complete';
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

    final class Remote_Republish_Service {
        public function source_available(int $id):bool{unset($id);return true;}
        public function targets():array{return array('pt-primary'=>'PeerTube primary');}
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
    function size_format(int|float $bytes,int $decimals=0):string{unset($decimals);return (string)$bytes . ' B';}
}

namespace {
    require_once dirname(__DIR__).'/includes/PeerTube_Overview_Admin.php';
    use ArgentVideo\PeerTube_Overview_Admin; use ArgentVideo\PeerTube_Staged_Upload_Operation_Store; use ArgentVideo\PeerTube_Incomplete_Work_Reconciler;
    use ArgentVideo\PeerTube_Event_Repository; use ArgentVideo\Remote_Publication_Health_Repository; use ArgentVideo\Remote_Republish_Service; use ArgentVideo\Video_Serving_Service; use ArgentVideo\Video_Meta;
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

    $health=new Remote_Publication_Health_Repository(array(55=>array('remote_asset_id'=>55,'video_post_id'=>101,'backend_id'=>'pt-primary','status'=>'missing','eligible'=>0,'failure_since'=>'2026-09-10 20:00:00','last_healthy_at'=>'2026-09-10 19:00:00','http_status'=>404,'message'=>'The published URL returned 404.')));
    $overview=new PeerTube_Overview_Admin($store,new PeerTube_Incomplete_Work_Reconciler(),new PeerTube_Event_Repository(),$health,new Video_Serving_Service());
    $rows=$overview->rows(1000);
    $assert(1===count($rows),'A missing remote publication was not surfaced in Needs Attention.');
    $assert(true===($rows[0]['needs_attention']??false),'Remote health issue was not marked as needing attention.');
    $assert(str_contains((string)$rows[0]['status_label'],'missing'),'Remote missing status was not human-readable.');
    $assert(str_contains((string)$rows[0]['serving_label'],'WordPress original'),'Overview did not report the active local fallback.');
    $assert(64===strlen((string)$rows[0]['issue_fingerprint']),'Overview issue fingerprint is not stable SHA-256 presentation state.');

    // A health failure that has never crossed first public-serving cutover is
    // publication/finalizer state, not a broken-publication incident. Older
    // prerelease rows must therefore disappear from Needs Attention unless
    // they carry durable evidence of a prior successful public qualification.
    $precutover_health=new Remote_Publication_Health_Repository(array(56=>array(
        'remote_asset_id'=>56,'video_post_id'=>101,'backend_id'=>'pt-primary',
        'status'=>'private_or_restricted','eligible'=>0,'failure_since'=>'2026-09-10 20:00:00',
        'last_healthy_at'=>null,'http_status'=>200,'message'=>'Still private while publishing.',
    )));
    $overview=new PeerTube_Overview_Admin($store,new PeerTube_Incomplete_Work_Reconciler(),new PeerTube_Event_Repository(),$precutover_health,new Video_Serving_Service());
    $assert(array()===$overview->rows(1000),'Pre-cutover health failure was incorrectly presented as a broken remote publication.');

    $GLOBALS['awvp_overview_meta'][101][Video_Meta::SERVING_AUTHORITY]=array('remote_asset_id'=>56,'backend_id'=>'pt-primary');
    $assert(1===count($overview->rows(1001)),'Historical serving authority did not preserve post-cutover health attention during upgrade compatibility.');
    unset($GLOBALS['awvp_overview_meta'][101][Video_Meta::SERVING_AUTHORITY]);

    // An unreconcilable init-boundary upload ambiguity exposes only the
    // explicit administrator resolution workflow. It must not expose ordinary
    // Republish until the administrator has checked PeerTube and retired the
    // uncertain operation. Once retired, the journal remains visible while
    // Republish becomes available from a retained source.
    $indeterminate=array(
        'operation_id'=>$op,'phase'=>'upload_indeterminate','record_revision'=>3,
        'source'=>array('bytes'=>100),'confirmed_bytes'=>0,'request_kind'=>'init',
        'upload_session_id'=>'','remote_asset_id'=>0,'remote_identity'=>array('id'=>'','uuid'=>''),
        'last_error'=>array('code'=>'peertube.upload.indeterminate','http_status'=>201,'retry_after'=>0),
    );
    $ind_store=new PeerTube_Staged_Upload_Operation_Store(array($op=>$indeterminate));
    $republish=new Remote_Republish_Service();
    $overview=new PeerTube_Overview_Admin($ind_store,new PeerTube_Incomplete_Work_Reconciler(),new PeerTube_Event_Repository(),new Remote_Publication_Health_Repository(),new Video_Serving_Service(),null,null,null,$republish);
    $rows=$overview->rows(1000);
    $assert(1===count($rows)&&true===($rows[0]['upload_resolution_available']??false),'Init-boundary indeterminate did not expose the explicit operator resolution workflow.');
    $assert(false===($rows[0]['republish_available']??true),'Unresolved upload indeterminate exposed ordinary Republish.');
    $assert(str_contains((string)$rows[0]['status_label'],'needs attention'),'Unresolved upload status label drifted.');

    $indeterminate['phase']='operator_abandoned';
    $indeterminate['record_revision']=4;
    $indeterminate['last_error']=array('code'=>'peertube.upload.operator_abandoned','http_status'=>201,'retry_after'=>0);
    $ind_store->ops[$op]=$indeterminate;
    $rows=$overview->rows(1001);
    $assert(1===count($rows)&&false===($rows[0]['upload_resolution_available']??true),'Retired upload still exposed the resolution confirmation workflow.');
    $assert(true===($rows[0]['republish_available']??false),'Operator-retired upload did not unlock explicit Republish.');
    $assert(str_contains((string)$rows[0]['status_label'],'ready to republish'),'Operator-retired upload status is not clear to the administrator.');

    $source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Overview_Admin.php');
    $assert(!str_contains($source,"esc_html_e('Review diagnostics'"),'Overview still emits the stale generic Review diagnostics action.');
    $assert(str_contains($source,"__('Mark reviewed'")&&str_contains($source,"__('Remove from list'")&&str_contains($source,'Reviewed items'),'Overview review/remove/collapsed-reviewed controls are missing.');
    $assert(str_contains($source,"__('Retire uncertain upload'")&&str_contains($source,'confirmed_no_remote')&&str_contains($source,'I checked PeerTube and confirmed that no matching remote video exists'),'Overview uncertain-upload confirmation boundary is missing.');
    $assert(str_contains($source,'upload_indeterminate_operator_abandoned')&&str_contains($source,'no remote request was sent'),'Overview uncertain-upload audit evidence is missing.');
    fwrite(STDOUT,"RC10 Overview resolution/health tests passed.\n");
}
