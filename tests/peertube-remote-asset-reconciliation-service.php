<?php
/** Focused dependency-free tests for R44 remote-asset commit/reconciliation. */
declare(strict_types=1);

require_once __DIR__ . '/peertube-staged-upload-service.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Remote_Reconciliation_Api.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Remote_Asset_Store.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Remote_Asset_Reconciliation_Service.php';

use ArgentVideo\Backend_Registry;
use ArgentVideo\Managed_Backend_Secret_Store;
use ArgentVideo\PeerTube_Remote_Asset_Reconciliation_Service;
use ArgentVideo\PeerTube_Remote_Asset_Store;
use ArgentVideo\PeerTube_Remote_Reconciliation_Api;
use ArgentVideo\PeerTube_Staged_Upload_Operation_Store;
use ArgentVideo\PeerTube_Staged_Upload_Service;
use ArgentVideo\PeerTube_Staged_Upload_State_Machine as R44Machine;

final class Awvp_R44_Fake_Reconciliation_Api implements PeerTube_Remote_Reconciliation_Api
{
    public int $gets=0;
    public string $mode='processing';
    public function __construct(private readonly string $api_origin, private readonly string $channel_id) {}
    public function origin(): string { return $this->api_origin; }
    public function video_status(string $access_token,string $video_uuid): array
    {
        ++$this->gets;
        if ('throw'===$this->mode) throw new RuntimeException('Synthetic read transport failure.');
        if ('rate'===$this->mode) return array('ok'=>false,'data'=>null,'error'=>array('status'=>'rate_limited','http_status'=>429,'retry_after'=>40));
        if ('missing'===$this->mode) return array('ok'=>false,'data'=>null,'error'=>array('status'=>'not_found','http_status'=>404,'retry_after'=>0));
        if ('auth'===$this->mode) return array('ok'=>false,'data'=>null,'error'=>array('status'=>'authentication_required','http_status'=>401,'retry_after'=>0));
        $state='ready'===$this->mode?1:('failed'===$this->mode?7:2);
        $id='mismatch'===$this->mode?'999':'901';
        return array('ok'=>true,'data'=>array(
            'id'=>$id,'uuid'=>$video_uuid,'state_id'=>$state,'privacy_id'=>3,'channel_id'=>$this->channel_id,
            'embed_path'=>'/videos/embed/'.$video_uuid,'is_live'=>false,
        ),'error'=>null);
    }
}

final class Awvp_R44_Fake_Asset_Store implements PeerTube_Remote_Asset_Store
{
    /** @var array<int,array<string,mixed>> */ public array $rows=array();
    public int $next=101;
    public int $commits=0;
    public int $observations=0;
    public function commit_created(array $operation,int $now): array
    {
        ++$this->commits;
        foreach($this->rows as $id=>$row){
            if(($row['backend_id']??'')===$operation['backend_id'] && ($row['remote_id']??'')===$operation['remote_identity']['uuid']){
                $exact=(int)$row['video_post_id']===(int)$operation['video_post_id'] && (string)$row['channel_id']===(string)$operation['destination_id'];
                return array('status'=>$exact?self::PRESENT:self::CONFLICT,'remote_asset_id'=>$exact?$id:0);
            }
        }
        $id=$this->next++;
        $this->rows[$id]=array('id'=>$id,'video_post_id'=>$operation['video_post_id'],'backend_id'=>$operation['backend_id'],'channel_id'=>$operation['destination_id'],'remote_id'=>$operation['remote_identity']['uuid'],'role'=>'secondary','state'=>'processing','desired_privacy'=>'private','actual_privacy'=>null,'remote_processing_state'=>'created','embed_url'=>null,'last_verified_at'=>null,'error_code'=>null);
        return array('status'=>self::APPLIED,'remote_asset_id'=>$id);
    }
    public function record_observation(int $remote_asset_id,array $operation,array $observation,int $now): string
    {
        ++$this->observations;
        $row=$this->rows[$remote_asset_id]??null;
        if(!is_array($row)||($row['remote_id']??'')!==$operation['remote_identity']['uuid']) return self::CONFLICT;
        $before=(string)($row['state']??''); $after=(string)($observation['state']??'');
        if($before!==$after && !('processing'===$before && in_array($after,array('ready','failed','missing'),true))) return self::CONFLICT;
        $this->rows[$remote_asset_id]=array_merge($row,$observation,array('last_verified_at'=>true===$observation['verified']?'1970-01-01 00:00:01':null,'error_code'=>''===$observation['error_code']?null:$observation['error_code'],'last_now'=>$now));
        return self::APPLIED;
    }
    public function find(int $remote_asset_id): ?array { return $this->rows[$remote_asset_id]??null; }
}

/** @return array{operation_id:string,store:PeerTube_Staged_Upload_Operation_Store,backend:array<string,mixed>,source:string} */
function awvp_r44_created(string $name,int $now): array
{
    $backend=awvp_r43_active_backend();
    $source=awvp_r43_source($name,'0123456789');
    $upload_api=new Awvp_R43_Fake_Api($backend['descriptor']['config']['origin']);
    $bundle=awvp_r43_service($upload_api);
    $begun=$bundle['service']->begin(77,$backend['backend_id'],$source,'R44 reconcile fixture',7,$now);
    $id=$begun['operation_id'];
    $bundle['service']->advance($id,$now+1); // init/session
    $created=$bundle['service']->advance($id,$now+2); // single final chunk
    awvp_coordinator_assert(PeerTube_Staged_Upload_Service::STATUS_REMOTE_CREATED===$created['status'],'R44 fixture did not reach remote_created.');
    return array('operation_id'=>$id,'store'=>$bundle['store'],'backend'=>$backend,'source'=>$source);
}

/** @return array{service:PeerTube_Remote_Asset_Reconciliation_Service,api:Awvp_R44_Fake_Reconciliation_Api,assets:Awvp_R44_Fake_Asset_Store} */
function awvp_r44_reconciler(array $fixture,?Awvp_R44_Fake_Asset_Store $assets=null): array
{
    $api=new Awvp_R44_Fake_Reconciliation_Api($fixture['backend']['descriptor']['config']['origin'], $fixture['backend']['descriptor']['default_destination']);
    $assets??=new Awvp_R44_Fake_Asset_Store();
    $service=new PeerTube_Remote_Asset_Reconciliation_Service(
        $fixture['store'],$assets,new Backend_Registry(),new Managed_Backend_Secret_Store(),
        static function(string $origin) use($api): PeerTube_Remote_Reconciliation_Api {
            awvp_coordinator_assert($origin===$api->origin(),'R44 reconciliation factory received wrong origin.'); return $api;
        }
    );
    return array('service'=>$service,'api'=>$api,'assets'=>$assets);
}

// Happy path: relational commit is a separate explicit request, then read-only
// processing and readiness observations each advance the durable journal.
$f=awvp_r44_created('r44-happy.mp4',8000); $r=awvp_r44_reconciler($f);
$committed=$r['service']->advance($f['operation_id'],8003);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_REMOTE_COMMITTED===$committed['status']&&R44Machine::PHASE_REMOTE_COMMITTED===$committed['phase']&&6===$committed['record_revision'],'R44 remote asset did not commit in its own request.');
awvp_coordinator_assert(0===$r['api']->gets&&1===$r['assets']->commits,'R44 relational commit crossed remote GET boundary.');
$processing=$r['service']->advance($f['operation_id'],8004);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_PROCESSING===$processing['status']&&R44Machine::PHASE_PROCESSING===$processing['phase']&&7===$processing['record_revision']&&30===$processing['retry_after'],'R44 processing observation/wait drifted.');
$early=$r['service']->advance($f['operation_id'],8020);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_WAIT===$early['status']&&1===$r['api']->gets,'R44 durable processing wait performed an early GET.');
$r['api']->mode='ready';
$ready=$r['service']->advance($f['operation_id'],8035);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_READY_VERIFIED===$ready['status']&&R44Machine::PHASE_READY_VERIFIED===$ready['phase']&&8===$ready['record_revision'],'R44 ready verification did not commit.');
$row=$r['assets']->find($ready['remote_asset_id']);
awvp_coordinator_assert('ready'===($row['state']??'')&&true===($row['verified']??false)&&is_file($f['source']),'R44 readiness changed/deleted the staged source or lost asset state.');

// Crash window: a previously committed exact relational row is observed and
// attached to the journal without inserting a duplicate or doing remote I/O.
$f=awvp_r44_created('r44-crash-window.mp4',8100); $assets=new Awvp_R44_Fake_Asset_Store();
$before=$f['store']->get($f['operation_id']); $pre=$assets->commit_created($before,8103);
$r=awvp_r44_reconciler($f,$assets); $recovered=$r['service']->advance($f['operation_id'],8104);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_REMOTE_COMMITTED===$recovered['status']&&$pre['remote_asset_id']===$recovered['remote_asset_id']&&1===count($assets->rows)&&0===$r['api']->gets,'R44 crash-window recovery duplicated or remotely queried the asset.');

// Crash after a positive relational readiness write but before the option
// journal transition recovers locally without issuing another PeerTube GET.
$f=awvp_r44_created('r44-ready-crash.mp4',8150); $r=awvp_r44_reconciler($f);
$committed=$r['service']->advance($f['operation_id'],8153);
$record=$f['store']->get($f['operation_id']);
$r['assets']->record_observation($committed['remote_asset_id'],$record,array('state'=>'ready','actual_privacy'=>'private','remote_processing_state'=>'1:published','embed_url'=>$record['origin'].'/videos/embed/'.$record['remote_identity']['uuid'],'verified'=>true,'error_code'=>''),8154);
$recovered=$r['service']->advance($f['operation_id'],8155);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_READY_VERIFIED===$recovered['status']&&R44Machine::PHASE_READY_VERIFIED===$recovered['phase']&&0===$r['api']->gets,'R44 relational-ready crash recovery repeated a remote GET.');

// A read-only 429 is durably rate-limited; early calls perform no GET and a
// later explicit call may retry because GET is non-mutating.
$f=awvp_r44_created('r44-rate.mp4',8200); $r=awvp_r44_reconciler($f); $r['service']->advance($f['operation_id'],8203); $r['api']->mode='rate';
$wait=$r['service']->advance($f['operation_id'],8204);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_WAIT===$wait['status']&&40===$wait['retry_after']&&1===$r['api']->gets,'R44 429 did not establish durable read wait.');
$r['service']->advance($f['operation_id'],8230);
awvp_coordinator_assert(1===$r['api']->gets,'R44 early rate-limit call repeated a GET.');
$r['api']->mode='ready'; $done=$r['service']->advance($f['operation_id'],8245);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_READY_VERIFIED===$done['status']&&2===$r['api']->gets,'R44 explicit post-wait GET did not recover readiness.');

// Transport uncertainty on GET is safe to retry only on a later explicit call.
$f=awvp_r44_created('r44-transport.mp4',8300); $r=awvp_r44_reconciler($f); $r['service']->advance($f['operation_id'],8303); $r['api']->mode='throw';
$wait=$r['service']->advance($f['operation_id'],8304);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_WAIT===$wait['status']&&60===$wait['retry_after']&&1===$r['api']->gets,'R44 uncertain read transport was not bounded.');
$r['service']->advance($f['operation_id'],8350); awvp_coordinator_assert(1===$r['api']->gets,'R44 uncertain GET auto-retried before wait expiry.');

// A positive 404 and a positive PeerTube processing failure are terminal and
// persisted into both authorities.
$f=awvp_r44_created('r44-missing.mp4',8400); $r=awvp_r44_reconciler($f); $r['service']->advance($f['operation_id'],8403); $r['api']->mode='missing';
$missing=$r['service']->advance($f['operation_id'],8404);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_MISSING===$missing['status']&&R44Machine::PHASE_FAILED===$missing['phase']&&'missing'===($r['assets']->find($missing['remote_asset_id'])['state']??''),'R44 positive missing outcome was not terminally committed.');
$f=awvp_r44_created('r44-failed.mp4',8500); $r=awvp_r44_reconciler($f); $r['service']->advance($f['operation_id'],8503); $r['api']->mode='failed';
$failed=$r['service']->advance($f['operation_id'],8504);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_FAILED===$failed['status']&&R44Machine::PHASE_FAILED===$failed['phase']&&'failed'===($r['assets']->find($failed['remote_asset_id'])['state']??''),'R44 processing failure was not terminally committed.');

// Remote identity mismatch fails closed without changing relational state.
$f=awvp_r44_created('r44-mismatch.mp4',8600); $r=awvp_r44_reconciler($f); $r['service']->advance($f['operation_id'],8603); $r['api']->mode='mismatch';
$conflict=$r['service']->advance($f['operation_id'],8604);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_CONFLICT===$conflict['status']&&'processing'===($r['assets']->find($conflict['remote_asset_id'])['state']??'')&&1===$r['api']->gets,'R44 mismatched remote identity mutated durable asset state.');

// Auth expiry is surfaced for the explicit token lifecycle, not written as a
// processing failure and not automatically refreshed here.
$f=awvp_r44_created('r44-auth.mp4',8700); $r=awvp_r44_reconciler($f); $r['service']->advance($f['operation_id'],8703); $r['api']->mode='auth';
$auth=$r['service']->advance($f['operation_id'],8704);
awvp_coordinator_assert(PeerTube_Remote_Asset_Reconciliation_Service::STATUS_REFRESH_REQUIRED===$auth['status']&&R44Machine::PHASE_REMOTE_COMMITTED===$auth['phase']&&1===$r['api']->gets,'R44 auth failure crossed the token lifecycle boundary.');

// Clean test sources without exercising product cleanup behavior.
$root=\ArgentVideo\Storage::root();
if(is_dir($root)){ $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST); foreach($it as $e){$e->isDir()?@rmdir($e->getPathname()):@unlink($e->getPathname());} @rmdir($root); @rmdir(dirname($root)); }

echo "PeerTube remote-asset reconciliation service tests passed.\n";
