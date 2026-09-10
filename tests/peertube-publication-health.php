<?php
/** Dependency-free PeerTube public-serving health adapter tests. */
declare(strict_types=1);
namespace ArgentVideo {
    final class Backend_Registry { public const LOCAL_ID='local'; public const PEERTUBE_TYPE='peertube'; }
    final class Managed_Backend_Secret_Store {
        public function __construct(private array $secret=array('access_token'=>'token')){}
        public function read(string $ref,string $backend):?array{unset($ref,$backend);return $this->secret;}
    }
    final class FakeHttp {
        public function __construct(private array $response){}
        public function get_public_embed(string $url):array{unset($url);return $this->response;}
    }
    final class FakeApi {
        public function __construct(private array $response){}
        public function video_status(string $token,string $uuid):array{unset($token,$uuid);return $this->response;}
    }
    function wp_parse_url(string $url,int $component=-1):mixed{return parse_url($url,$component);}
}
namespace {
    require_once dirname(__DIR__).'/includes/Backend_Identity.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Origin.php';
    require_once dirname(__DIR__).'/includes/Serving_Viability.php';
    require_once dirname(__DIR__).'/includes/Serving_Health_Adapter.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Publication_Health_Probe.php';
    use ArgentVideo\PeerTube_Publication_Health_Probe as Probe; use ArgentVideo\Managed_Backend_Secret_Store; use ArgentVideo\FakeHttp; use ArgentVideo\FakeApi; use ArgentVideo\Serving_Viability;
    $a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
    $descriptor=array('id'=>'pt1','type'=>'peertube','state'=>'active','secret_ref'=>'secret','config'=>array('origin'=>'https://video.example.org'));
    $asset=array('backend_id'=>'pt1','remote_id'=>'123e4567-e89b-42d3-a456-426614174000','embed_url'=>'https://video.example.org/videos/embed/abcDEF_123');
    $factory=static fn(array $http,array $api):Probe=>new Probe(new Managed_Backend_Secret_Store(),static fn(string $o):FakeApi=>new FakeApi($api),static fn(string $o):FakeHttp=>new FakeHttp($http));
    $goodHttp=array('ok'=>true,'http_status'=>200,'headers'=>array('content-type'=>'text/html; charset=utf-8'),'body'=>'<html><body><div id="video-embed"></div></body></html>','error'=>null);
    $goodApi=array('ok'=>true,'data'=>array('privacy_id'=>1,'state_id'=>1,'embed_path'=>'/videos/embed/abcDEF_123'),'error'=>null);
    $r=$factory($goodHttp,$goodApi)->probe_publication($descriptor,$asset);$a(Serving_Viability::HEALTHY===$r->status(),'Healthy public/API publication did not remain viable.');
$processingApi=array('ok'=>true,'data'=>array('privacy_id'=>1,'state_id'=>2,'embed_path'=>'/videos/embed/abcDEF_123'),'error'=>null);
$r=$factory($goodHttp,$processingApi)->probe_publication($descriptor,$asset);$a(Serving_Viability::PROCESSING===$r->status(),'Provider processing state did not prevent premature public qualification.');
$processingHttp=$goodHttp;$processingHttp['body']='<html><body>This video is being transcoded.</body></html>';
$r=$factory($processingHttp,$goodApi)->probe_publication($descriptor,$asset);$a(Serving_Viability::PROCESSING===$r->status(),'Public processing placeholder did not classify as processing.');
    $missing=array('ok'=>false,'http_status'=>404,'headers'=>array(),'body'=>'','error'=>array('status'=>'not_found','http_status'=>404));
    $r=$factory($missing,$goodApi)->probe_publication($descriptor,$asset);$a(Serving_Viability::MISSING===$r->status(),'Public 404 did not classify as missing.');
    $down=array('ok'=>false,'http_status'=>503,'headers'=>array(),'body'=>'','error'=>array('status'=>'remote_error','http_status'=>503));
    $r=$factory($down,$goodApi)->probe_publication($descriptor,$asset);$a(Serving_Viability::TEMPORARILY_UNAVAILABLE===$r->status(),'Public 503 did not classify as temporarily unavailable.');
    $privateApi=array('ok'=>true,'data'=>array('privacy_id'=>3,'state_id'=>1,'embed_path'=>'/videos/embed/abcDEF_123'),'error'=>null);
    $r=$factory($goodHttp,$privateApi)->probe_publication($descriptor,$asset);$a(Serving_Viability::PRIVATE_OR_RESTRICTED===$r->status(),'Provider-confirmed private state did not disqualify the otherwise reachable embed shell.');
    $wrongApi=array('ok'=>true,'data'=>array('privacy_id'=>1,'state_id'=>1,'embed_path'=>'/videos/embed/DIFFERENT'),'error'=>null);
    $r=$factory($goodHttp,$wrongApi)->probe_publication($descriptor,$asset);$a(Serving_Viability::HEALTHY===$r->status(),'Provider API embed metadata incorrectly overrode a usable verified serving URL.');
    fwrite(STDOUT,"PeerTube publication health tests passed.\n");
}
