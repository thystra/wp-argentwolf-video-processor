<?php
/** Dependency-free provider-backed operator remote-asset refresh tests. */
declare(strict_types=1);

namespace ArgentVideo {
    $GLOBALS['awvp_verified_remote_meta']=array();

    interface PeerTube_Publication_Asset_Store {
        public function find(int $remote_asset_id):?array;
        public function record_publication_observation(int $remote_asset_id,int $video_post_id,string $backend_id,string $remote_uuid,string $channel_id,string $privacy_id,int $now):string;
    }
    interface PeerTube_Remote_Asset_Store {
        public const APPLIED='applied'; public const PRESENT='present'; public const CONFLICT='conflict'; public const INDETERMINATE='indeterminate';
    }
    final class FakeAssets implements PeerTube_Publication_Asset_Store {
        public string $write_result=PeerTube_Remote_Asset_Store::APPLIED;
        public function __construct(public array $rows){}
        public function find(int $id):?array{return $this->rows[$id]??null;}
        public function record_publication_observation(int $id,int $video,string $backend,string $uuid,string $channel,string $privacy,int $now):string{
            if(!in_array($this->write_result,array(PeerTube_Remote_Asset_Store::APPLIED,PeerTube_Remote_Asset_Store::PRESENT),true))return $this->write_result;
            $name=match($privacy){'1'=>'public','2'=>'unlisted','3'=>'private','4'=>'internal',default=>''};
            $this->rows[$id]=array_replace($this->rows[$id],array('desired_privacy'=>$name,'actual_privacy'=>$name,'remote_processing_state'=>'1:published','last_synced_at'=>gmdate('Y-m-d H:i:s',$now),'last_verified_at'=>gmdate('Y-m-d H:i:s',$now)));
            return $this->write_result;
        }
    }
    final class Backend_Registry {
        public const LOCAL_ID='local'; public const PEERTUBE_TYPE='peertube';
        public function __construct(public array $descriptor){}
        public function get_fresh(string $id):?array{return $id===($this->descriptor['backend_id']??'')?$this->descriptor:null;}
    }
    final class Managed_Backend_Secret_Store {
        public function read(string $ref,string $backend):?array{return 'secret-ref'===$ref&&'pt-primary'===$backend?array('access_token'=>'token'):null;}
    }
    final class PeerTube_Http_Client { public function __construct(string $origin){unset($origin);} }
    final class PeerTube_Api_Client {
        public function __construct(mixed $unused=null){unset($unused);}
        public array $result=array();
        public function video_status(string $token,string $uuid):array{unset($token,$uuid);return $this->result;}
    }
    final class PeerTube_Origin { public static function sanitize(string $v):string{return str_starts_with($v,'https://')?$v:'';} }
    final class Backend_Identity { public static function sanitize(mixed $v):string{return is_string($v)?$v:'';} }
    final class Video_Meta {
        public const PEERTUBE_PUBLICATION_LIFECYCLE='_life'; public const DESTINATION='_dest'; public const PEERTUBE_PUBLICATION_PLAN='_plan'; public const PEERTUBE_PUBLICATION_EXECUTION='_exec';
    }
    final class Video_Destination { public static function sanitize(mixed $v):array{return is_array($v)?$v:array();} }
    final class PeerTube_Publication_Plan { public static function sanitize(mixed $v):array{return is_array($v)?$v:array();} }
    final class PeerTube_Publication_Lifecycle {
        public static function sanitize(mixed $v):array{return is_array($v)?$v:array();}
        public static function plan_sha256(array $plan):string{return (string)($plan['sha']??'');}
    }
    final class PeerTube_Publication_Execution { public static function sanitize(mixed $v):array{return is_array($v)?$v:array();} }
    function get_post_meta(int $id,string $key,bool $single=true):mixed{unset($single);return $GLOBALS['awvp_verified_remote_meta'][$id][$key]??'';}
    function wp_parse_url(string $url):array|false{return parse_url($url);}
}

namespace {
    require_once dirname(__DIR__).'/includes/Verified_Remote_Asset_Refresher.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Verified_Remote_Asset_Refresher.php';
    use ArgentVideo\FakeAssets;
    use ArgentVideo\Backend_Registry;
    use ArgentVideo\Managed_Backend_Secret_Store;
    use ArgentVideo\PeerTube_Api_Client;
    use ArgentVideo\PeerTube_Verified_Remote_Asset_Refresher;
    use ArgentVideo\Verified_Remote_Asset_Refresher;
    use ArgentVideo\Video_Meta;
    use ArgentVideo\PeerTube_Remote_Asset_Store;

    $a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
    $video=77;$asset_id=55;$uuid='123e4567-e89b-42d3-a456-426614174000';$embed='https://video.example.org/videos/embed/abcDEF_123';$sha=str_repeat('a',64);
    $plan=array('backend_id'=>'pt-primary','channel_id'=>'41','anchor_post_id'=>10,'sha'=>$sha);
    $life=array('backend_id'=>'pt-primary','anchor_post_id'=>10,'plan_sha256'=>$sha,'target_privacy_id'=>'1','reveal_authorized'=>true,'wordpress_status'=>'publish');
    $exec=array('backend_id'=>'pt-primary','channel_id'=>'41','anchor_post_id'=>10,'manifest'=>array('plan_sha256'=>$sha),'remote_asset_id'=>$asset_id,'remote_uuid'=>$uuid);
    $GLOBALS['awvp_verified_remote_meta'][$video]=array(Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE=>$life,Video_Meta::DESTINATION=>array('backend_id'=>'pt-primary','channel_id'=>'41'),Video_Meta::PEERTUBE_PUBLICATION_PLAN=>$plan,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION=>$exec);
    $assets=new FakeAssets(array($asset_id=>array('id'=>$asset_id,'video_post_id'=>$video,'backend_id'=>'pt-primary','channel_id'=>'41','remote_id'=>$uuid,'role'=>'secondary','state'=>'ready','desired_privacy'=>'private','actual_privacy'=>'private','remote_processing_state'=>'1:published','embed_url'=>$embed,'last_verified_at'=>'2026-09-08 22:20:15')));
    $registry=new Backend_Registry(array('backend_id'=>'pt-primary','type'=>'peertube','state'=>'active','secret_ref'=>'secret-ref','config'=>array('origin'=>'https://video.example.org')));
    $api=new PeerTube_Api_Client();
    $api->result=array('ok'=>true,'data'=>array('uuid'=>$uuid,'state_id'=>1,'privacy_id'=>1,'channel_id'=>'41','embed_path'=>'/videos/embed/abcDEF_123'),'error'=>null);
    $svc=new PeerTube_Verified_Remote_Asset_Refresher($assets,$registry,new Managed_Backend_Secret_Store(),static fn(string $origin):PeerTube_Api_Client=>$api);
    $r=$svc->refresh($video,$asset_id,2000000);
    $a(Verified_Remote_Asset_Refresher::APPLIED===$r['status'],'Fresh exact provider observation was not applied.');
    $a('public'===$assets->rows[$asset_id]['desired_privacy']&&'public'===$assets->rows[$asset_id]['actual_privacy'],'Stale private catalog facts were not refreshed to verified public state.');

    $assets->rows[$asset_id]['desired_privacy']='private';$assets->rows[$asset_id]['actual_privacy']='private';$api->result['data']['privacy_id']=2;
    $r=$svc->refresh($video,$asset_id,2000010);
    $a(Verified_Remote_Asset_Refresher::REFUSED===$r['status']&&'operator.remote_refresh.privacy_mismatch'===$r['reason_code'],'Provider privacy mismatch did not fail closed.');
    $a('private'===$assets->rows[$asset_id]['actual_privacy'],'Privacy mismatch rewrote local catalog facts.');

    $api->result['data']['privacy_id']=1;$api->result['data']['state_id']=2;
    $r=$svc->refresh($video,$asset_id,2000020);
    $a(Verified_Remote_Asset_Refresher::REFUSED===$r['status']&&'operator.remote_refresh.not_published'===$r['reason_code'],'Non-published provider state did not fail closed.');

    $api->result['data']['state_id']=1;$api->result['data']['channel_id']='99';
    $r=$svc->refresh($video,$asset_id,2000030);
    $a(Verified_Remote_Asset_Refresher::REFUSED===$r['status']&&'operator.remote_refresh.channel_mismatch'===$r['reason_code'],'Provider channel mismatch did not fail closed.');

    $api->result['data']['channel_id']='41';$assets->rows[$asset_id]['embed_url']='https://other.example.org/videos/embed/abcDEF_123';
    $r=$svc->refresh($video,$asset_id,2000035);
    $a(Verified_Remote_Asset_Refresher::REFUSED===$r['status']&&'operator.remote_refresh.embed_identity_mismatch'===$r['reason_code'],'Provider embed origin/path mismatch did not fail closed.');
    $assets->rows[$asset_id]['embed_url']=$embed;

    $assets->write_result=PeerTube_Remote_Asset_Store::CONFLICT;
    $r=$svc->refresh($video,$asset_id,2000040);
    $a(Verified_Remote_Asset_Refresher::REFUSED===$r['status']&&'operator.remote_refresh.catalog_conflict'===$r['reason_code'],'Catalog compare-and-swap conflict did not fail closed.');

    fwrite(STDOUT,"PeerTube verified remote asset refresher tests passed.\n");
}
