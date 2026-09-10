<?php
/** Dependency-free RC10 credential + catalog authority repair tests. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Backend_Registry {
        public const LOCAL_ID='local'; public const PEERTUBE_TYPE='peertube';
        public array $descriptor=array(); public int $fresh_reads=0;
        public function get_fresh(string $id):?array { ++$this->fresh_reads; return $id===($this->descriptor['id']??null)?$this->descriptor:null; }
    }
    final class Managed_Backend_Secret_Store {
        public ?array $secret=null; public int $reads=0;
        public function read(string $ref,string $backend):?array { unset($ref,$backend); ++$this->reads; return $this->secret; }
    }
    final class PeerTube_Token_Lifecycle_Service {
        public const STATUS_ADVANCED='advanced'; public const STATUS_COMPLETE='complete'; public const STATUS_WAIT='wait'; public const STATUS_REAUTHENTICATION_REQUIRED='reauthentication_required'; public const STATUS_INDETERMINATE='indeterminate'; public const STATUS_CONFLICT='conflict'; public const STATUS_REFUSED='refused';
        public int $calls=0;
        public function __construct(private readonly Managed_Backend_Secret_Store $secrets){}
        public function refresh(string $backend,int $now):array { unset($backend,$now); ++$this->calls; if(2===$this->calls){$this->secrets->secret=array('access_token'=>'new-access','refresh_token'=>'new-refresh','access_expires_at'=>5000,'refresh_expires_at'=>9000,'generation'=>2);} return array('status'=>3===$this->calls?self::STATUS_COMPLETE:self::STATUS_ADVANCED,'retry_after'=>0); }
    }
    final class PeerTube_Publication_Catalog_Store {
        public ?array $catalog=null; public int $fresh_reads=0;
        public function get_for_context_fresh(string $backend,string $origin,int $generation):?array { ++$this->fresh_reads; $c=$this->catalog; return is_array($c)&&$backend===($c['backend_id']??null)&&$origin===($c['origin']??null)&&$generation===($c['secret_generation']??null)?$c:null; }
    }
    final class PeerTube_Publication_Catalog_Service {
        public const COMPLETE='complete'; public const REFUSED='refused'; public const REMOTE_FAILED='remote_failed'; public const CACHE_FAILED='cache_failed';
        public int $calls=0; public string $status=self::COMPLETE;
        public function __construct(private readonly PeerTube_Publication_Catalog_Store $store,private readonly Managed_Backend_Secret_Store $secrets){}
        public function refresh(string $backend,int $now):array { unset($now); ++$this->calls; if(self::COMPLETE===$this->status && is_array($this->secrets->secret)){$this->store->catalog=array('backend_id'=>$backend,'origin'=>'https://video.example.org','secret_generation'=>$this->secrets->secret['generation'],'stale'=>false);} return array('status'=>$this->status,'catalog'=>$this->store->catalog); }
    }
}

namespace {
    function wp_parse_url(string $url):array|false { $v=parse_url($url); return is_array($v)?$v:false; }
    require_once dirname(__DIR__).'/includes/Backend_Identity.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Origin.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Publication_Authority_Repair.php';

    use ArgentVideo\Backend_Registry;
    use ArgentVideo\Managed_Backend_Secret_Store;
    use ArgentVideo\PeerTube_Publication_Authority_Repair as Repair;
    use ArgentVideo\PeerTube_Publication_Catalog_Service;
    use ArgentVideo\PeerTube_Publication_Catalog_Store;
    use ArgentVideo\PeerTube_Token_Lifecycle_Service;

    $a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
    $registry=new Backend_Registry();
    $registry->descriptor=array('id'=>'pt-primary','type'=>'peertube','state'=>'active','secret_ref'=>'managed:pt-primary','config'=>array('origin'=>'https://video.example.org'));
    $secrets=new Managed_Backend_Secret_Store();
    $secrets->secret=array('access_token'=>'old-access','refresh_token'=>'old-refresh','access_expires_at'=>2050,'refresh_expires_at'=>9000,'generation'=>1);
    $tokens=new PeerTube_Token_Lifecycle_Service($secrets);
    $catalogs=new PeerTube_Publication_Catalog_Store();
    $catalogs->catalog=array('backend_id'=>'pt-primary','origin'=>'https://video.example.org','secret_generation'=>1,'stale'=>false);
    $catalogService=new PeerTube_Publication_Catalog_Service($catalogs,$secrets);
    $repair=new Repair($tokens,$catalogService,$catalogs,$secrets,$registry);

    $result=$repair->repair('pt-primary',2000);
    $a(PeerTube_Token_Lifecycle_Service::STATUS_COMPLETE===($result['status']??''),'Expired access token did not settle to current authority in one repair request.');
    $a(3===$tokens->calls,'Restart-safe token lifecycle was not advanced through ready/in-flight/complete phases.');
    $a(2===($secrets->secret['generation']??0),'Credential repair did not observe the rotated secret generation.');
    $a(1===$catalogService->calls && 2===($catalogs->catalog['secret_generation']??0),'Catalog did not refresh against the new credential generation.');

    $tokenCalls=$tokens->calls; $catalogCalls=$catalogService->calls;
    $current=$repair->repair('pt-primary',2100);
    $a(PeerTube_Token_Lifecycle_Service::STATUS_COMPLETE===($current['status']??'')&&$tokenCalls===$tokens->calls&&$catalogCalls===$catalogService->calls,'Already-current authority performed an unnecessary credential or catalog refresh.');

    $secrets->secret=array('access_token'=>'expired','refresh_token'=>'expired-refresh','access_expires_at'=>2100,'refresh_expires_at'=>2150,'generation'=>3);
    $reauth=$repair->repair('pt-primary',2100);
    $a(PeerTube_Token_Lifecycle_Service::STATUS_REAUTHENTICATION_REQUIRED===($reauth['status']??''),'Expired refresh authority did not require reauthentication.');
    $a($tokenCalls===$tokens->calls,'Unusable refresh token was sent into the refresh lifecycle.');

    fwrite(STDOUT,"RC10 PeerTube publication authority repair tests passed.\n");
}
