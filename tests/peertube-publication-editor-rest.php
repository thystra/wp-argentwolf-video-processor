<?php
/** Focused dependency-free R46.3c publication editor REST tests. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Video_Meta { public static function sanitize_positive_id(mixed $v): int { return is_int($v)&&$v>0?$v:(is_string($v)&&1===preg_match('/^[1-9][0-9]*$/D',$v)?(int)$v:0); } }
    final class PeerTube_Publication_Editor_Service {
        public const APPLIED='applied'; public const PRESENT='present'; public const REFUSED='refused'; public const INDETERMINATE='indeterminate';
        public ?array $state=array('video_id'=>101,'catalog'=>array('usable'=>true),'plan_status'=>'absent','draft'=>array('anchor_post_id'=>55));
        public array $save_result=array('status'=>self::APPLIED); public array $save_calls=array(); public array $state_calls=array();
        public function editor_state(int $id): ?array { $this->state_calls[]=$id; return $this->state; }
        public function save(int $id,array $plan,bool $replace=false): array { $this->save_calls[]=array($id,$plan,$replace); return $this->save_result; }
    }
}

namespace {
    $GLOBALS['awvp_pub_rest_routes']=array(); $GLOBALS['awvp_pub_caps']=array('upload_files'=>true,'edit_post'=>true); $GLOBALS['awvp_pub_cap_calls']=array();
    class WP_REST_Request { public function __construct(private array $p=array()){} public function get_param(string $n): mixed { return $this->p[$n]??null; } }
    class WP_REST_Response { public function __construct(public mixed $data){} }
    class WP_Error { public function __construct(public string $code,public string $message,public array $data=array()){} }
    function register_rest_route(string $ns,string $route,array $args): bool { $GLOBALS['awvp_pub_rest_routes'][]=array($ns,$route,$args); return true; }
    function current_user_can(string $cap,mixed ...$args): bool { $GLOBALS['awvp_pub_cap_calls'][]=array($cap,$args); return (bool)($GLOBALS['awvp_pub_caps'][$cap]??false); }
    function rest_ensure_response(mixed $v): WP_REST_Response { return new WP_REST_Response($v); }
    function __(string $s,string $d=''): string { unset($d); return $s; }
    require_once dirname(__DIR__).'/includes/PeerTube_Publication_Editor_Rest.php';
    $assert=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
    $service=new \ArgentVideo\PeerTube_Publication_Editor_Service(); $rest=new \ArgentVideo\PeerTube_Publication_Editor_Rest($service); $rest->register();
    $assert(1===count($GLOBALS['awvp_pub_rest_routes']),'Publication editor should register one route with GET/POST endpoints.');
    [$ns,$route,$endpoints]=$GLOBALS['awvp_pub_rest_routes'][0];
    $assert(\ArgentVideo\PeerTube_Publication_Editor_Rest::NAMESPACE===$ns,'Publication REST namespace drifted.');
    $assert(2===count($endpoints)&&'GET'===$endpoints[0]['methods']&&'POST'===$endpoints[1]['methods'],'Publication REST methods drifted.');
    foreach($endpoints as $endpoint){$assert(isset($endpoint['permission_callback'])&&is_callable($endpoint['permission_callback']),'Publication REST endpoint omitted permission callback.');}
    $request=new WP_REST_Request(array('video_id'=>'101'));
    $assert(true===$rest->can_edit($request),'Authorized publication read refused.');
    $GLOBALS['awvp_pub_caps']['upload_files']=false; $assert(false===$rest->can_edit($request),'Publication editor did not require upload_files.'); $GLOBALS['awvp_pub_caps']['upload_files']=true;
    $GLOBALS['awvp_pub_caps']['edit_post']=false; $assert(false===$rest->can_edit($request),'Publication editor did not require edit_post.'); $GLOBALS['awvp_pub_caps']['edit_post']=true;
    $GLOBALS['awvp_pub_cap_calls']=array();
    $assert(true===$rest->can_edit($request),'Authorized publication context refused.');
    $assert(in_array(array('edit_post',array(101)),$GLOBALS['awvp_pub_cap_calls'],true),'Publication permission omitted AWVP Video edit check.');
    $assert(in_array(array('edit_post',array(55)),$GLOBALS['awvp_pub_cap_calls'],true),'Publication permission omitted anchor-post edit check.');
    $read=$rest->read($request); $assert($read instanceof WP_REST_Response&&101===$read->data['video_id'],'Publication GET failed.');
    $saveRequest=new WP_REST_Request(array('video_id'=>'101','replace_existing'=>false,'plan'=>array('thumbnail_attachment_id'=>0,'title'=>'Farm Tour')));
    $saved=$rest->save($saveRequest); $assert($saved instanceof WP_REST_Response,'Publication POST failed.');
    $assert(array(101,array('thumbnail_attachment_id'=>0,'title'=>'Farm Tour'),false)===($service->save_calls[0]??null),'Publication POST arguments drifted.');
    $thumbRequest=new WP_REST_Request(array('video_id'=>'101','plan'=>array('thumbnail_attachment_id'=>'30')));
    $assert(true===$rest->can_edit($thumbRequest),'Authorized thumbnail plan refused.');
    $GLOBALS['awvp_pub_caps']['edit_post']=false; $assert(false===$rest->can_edit($thumbRequest),'Thumbnail edit capability was not enforced.');
    $source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Publication_Editor_Rest.php');
    foreach(array('wp_remote_','PeerTube_Api_Client','PeerTube_Task','update_post_meta','wp_insert_post','transition_post_status','wp_publish_post') as $forbidden){$assert(!str_contains($source,$forbidden),'Publication REST acquired forbidden direct authority: '.$forbidden);}
    fwrite(STDOUT,"R46 PeerTube publication editor REST tests passed.\n");
}
