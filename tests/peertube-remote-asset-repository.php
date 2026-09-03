<?php
/** Focused tests for durable PeerTube remote-asset persistence. */
declare(strict_types=1);

if (! defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

final class Awvp_R44_Remote_Asset_Wpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var array<int,array<string,mixed>> */
    public array $rows = array();
    /** @var callable|null */
    public $before_update = null;
    private int $next_id = 1;

    public function prepare(string $query, mixed ...$args): array { return array('query'=>$query,'args'=>$args); }
    public function get_row(mixed $prepared, mixed $output = null): ?array
    {
        if (! is_array($prepared)) return null;
        $q=(string)($prepared['query']??''); $a=$prepared['args']??array();
        if (str_contains($q, 'WHERE id = %d')) {
            $id=(int)($a[1]??0); return $this->rows[$id]??null;
        }
        if (str_contains($q, 'backend_id = %s AND remote_id = %s')) {
            $backend=(string)($a[1]??''); $remote=(string)($a[2]??'');
            foreach ($this->rows as $row) if (($row['backend_id']??'')===$backend && ($row['remote_id']??'')===$remote) return $row;
        }
        return null;
    }
    public function insert(string $table, array $data, array $formats): int|false
    {
        if (count($data)!==count($formats)) return false;
        foreach ($this->rows as $row) {
            if (($row['backend_id']??'')===($data['backend_id']??null) && ($row['remote_id']??'')===($data['remote_id']??null)) return false;
        }
        $id=$this->next_id++; $this->insert_id=$id; $data['id']=$id; $this->rows[$id]=$data; return 1;
    }
    public function update(string $table, array $data, array $where, array $formats, array $where_formats): int|false
    {
        if (count($data)!==count($formats) || count($where)!==count($where_formats)) return false;
        $id=(int)($where['id']??0); if (!isset($this->rows[$id])) return 0;
        if (is_callable($this->before_update)) { $hook=$this->before_update; $this->before_update=null; $hook($this,$id); }
        foreach ($where as $key=>$value) if (($this->rows[$id][$key]??null)!==$value) return 0;
        $changed=false;
        foreach ($data as $key=>$value) { if (($this->rows[$id][$key]??null)!==$value) $changed=true; $this->rows[$id][$key]=$value; }
        return $changed?1:0;
    }
}
$GLOBALS['wpdb']=new Awvp_R44_Remote_Asset_Wpdb();

require_once dirname(__DIR__).'/includes/Backend_Identity.php';
require_once dirname(__DIR__).'/includes/PeerTube_Origin.php';
require_once dirname(__DIR__).'/includes/PeerTube_Connection_Input.php';
require_once dirname(__DIR__).'/includes/PeerTube_Staged_Source_Identity.php';
require_once dirname(__DIR__).'/includes/PeerTube_Staged_Upload_State_Machine.php';
require_once dirname(__DIR__).'/includes/PeerTube_Remote_Asset_Store.php';
require_once dirname(__DIR__).'/includes/Remote_Asset_Repository.php';

use ArgentVideo\PeerTube_Staged_Upload_State_Machine as Machine;
use ArgentVideo\PeerTube_Remote_Asset_Store;
use ArgentVideo\Remote_Asset_Repository;

$assert=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
$source=array('kind'=>'wordpress_staging','relative_path'=>'77/staging/source.mp4','sha256'=>str_repeat('a',64),'bytes'=>10);
$upload=array('filename'=>'source.mp4','content_type'=>'video/mp4','name'=>'R44 remote asset','privacy'=>3);
$r=Machine::create(array('operation_id'=>'upload_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa','video_post_id'=>77,'backend_id'=>'peertube-primary','origin'=>'https://video.example.org','destination_id'=>'41','source'=>$source,'upload'=>$upload),7,1000);
$cap=str_repeat('1',64);
$r=Machine::apply($r,Machine::EVENT_CLAIM_UPLOAD,array('attempt_capability'=>$cap,'request_kind'=>'init','request_start'=>0,'request_bytes'=>0),1001);
$r=Machine::apply($r,Machine::EVENT_UPLOAD_SESSION_CREATED,array('attempt_capability'=>$cap,'session_id'=>'abcd1234efgh5678'),1002);
$cap=str_repeat('2',64);
$r=Machine::apply($r,Machine::EVENT_CLAIM_UPLOAD,array('attempt_capability'=>$cap,'request_kind'=>'chunk','request_start'=>0,'request_bytes'=>10),1003);
$r=Machine::apply($r,Machine::EVENT_REMOTE_CREATED,array('attempt_capability'=>$cap,'remote_identity'=>array('id'=>'901','uuid'=>'12345678-1234-4abc-9def-1234567890ab')),1004);
$assert(is_array($r)&&Machine::PHASE_REMOTE_CREATED===$r['phase'],'Could not create remote-created fixture.');

$repo=new Remote_Asset_Repository();
$first=$repo->commit_created($r,1010);
$assert(PeerTube_Remote_Asset_Store::APPLIED===$first['status']&&1===$first['remote_asset_id'],'Initial remote-asset commit failed.');
$second=$repo->commit_created($r,1011);
$assert(PeerTube_Remote_Asset_Store::PRESENT===$second['status']&&1===$second['remote_asset_id']&&1===count($GLOBALS['wpdb']->rows),'Exact restart replay created a duplicate remote asset.');
$row=$repo->find(1);
$assert(is_array($row)&&'secondary'===$row['role']&&'processing'===$row['state']&&'private'===$row['desired_privacy']&&null===$row['actual_privacy'],'Initial remote-asset authority drifted.');

$committed=Machine::apply($r,Machine::EVENT_COMMIT_REMOTE_ASSET,array('remote_asset_id'=>1),1012);
$processing=array('state'=>'processing','actual_privacy'=>'private','remote_processing_state'=>'2:to_transcode','embed_url'=>'https://video.example.org/videos/embed/12345678-1234-4abc-9def-1234567890ab','verified'=>false,'error_code'=>'');
$status=$repo->record_observation(1,$committed,$processing,1020);
$assert(PeerTube_Remote_Asset_Store::APPLIED===$status,'Processing observation was not stored.');
$processing_record=Machine::apply($committed,Machine::EVENT_PROCESSING_OBSERVED,array('retry_after'=>30),1020);
$ready=array('state'=>'ready','actual_privacy'=>'private','remote_processing_state'=>'1:published','embed_url'=>'https://video.example.org/videos/embed/12345678-1234-4abc-9def-1234567890ab','verified'=>true,'error_code'=>'');

// Same-second timestamp reuse must not defeat the observation CAS. Simulate a
// concurrent processing observation after this request's read but before its
// guarded update; the stale writer must not overwrite the winner.
$GLOBALS['wpdb']->before_update=static function(Awvp_R44_Remote_Asset_Wpdb $db,int $id):void{
    $db->rows[$id]['remote_processing_state']='6:moving_to_external_storage';
};
$status=$repo->record_observation(1,$processing_record,$ready,1020);
$assert(PeerTube_Remote_Asset_Store::CONFLICT===$status,'Same-second concurrent row change bypassed the observation CAS.');
$assert('6:moving_to_external_storage'===($repo->find(1)['remote_processing_state']??''),'Stale observation overwrote the concurrent winner.');

$status=$repo->record_observation(1,$processing_record,$ready,1051);
$assert(PeerTube_Remote_Asset_Store::APPLIED===$status,'Ready observation was not stored.');

$invalid_ready=$ready;
$invalid_ready['remote_processing_state']='2:to_transcode';
$assert(PeerTube_Remote_Asset_Store::CONFLICT===$repo->record_observation(1,$processing_record,$invalid_ready,1052),'Repository accepted a semantically impossible ready observation.');
$invalid_embed=$ready;
$invalid_embed['embed_url']='https://evil.example/videos/embed/12345678-1234-4abc-9def-1234567890ab';
$assert(PeerTube_Remote_Asset_Store::CONFLICT===$repo->record_observation(1,$processing_record,$invalid_embed,1052),'Repository accepted an embed URL outside the exact PeerTube origin.');

$row=$repo->find(1);
$assert('ready'===($row['state']??'')&&'private'===($row['actual_privacy']??'')&&''!==($row['last_verified_at']??''),'Ready remote-asset verification did not persist.');

// A ready row cannot regress to processing, even if a stale operation asks.
$status=$repo->record_observation(1,$processing_record,$processing,1060);
$assert(PeerTube_Remote_Asset_Store::CONFLICT===$status,'Ready remote asset regressed to processing.');

// Same remote key with different immutable intent is a conflict, not a second row.
$poison=$r; $poison['video_post_id']=78;
$assert(PeerTube_Remote_Asset_Store::CONFLICT===$repo->commit_created($poison,1070)['status'],'Conflicting remote identity reused an existing row.');
$assert(1===count($GLOBALS['wpdb']->rows),'Conflicting commit inserted a duplicate remote asset.');

fwrite(STDOUT,"PeerTube remote-asset repository tests passed.\n");
