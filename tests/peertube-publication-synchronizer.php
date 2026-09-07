<?php
/** Dependency-free lifecycle synchronization tests. */
declare(strict_types=1);
if (!defined('ARRAY_A')) define('ARRAY_A','ARRAY_A');
$GLOBALS['awvp_sync_posts']=array(); $GLOBALS['awvp_sync_meta']=array(); $GLOBALS['awvp_sync_options']=array();
function __(string $s,string $d=''): string { return $s; }
function wp_json_encode(mixed $v,int $f=0,int $d=512): string|false { return json_encode($v,$f,$d); }
function wp_generate_uuid4(): string { return '00000000-0000-4000-8000-000000000001'; }
function get_post(int $id): mixed { return $GLOBALS['awvp_sync_posts'][$id] ?? null; }
function get_post_meta(int $id,string $k,bool $single=true): mixed { return $GLOBALS['awvp_sync_meta'][$id][$k] ?? ''; }
function metadata_exists(string $type,int $id,string $k): bool { return array_key_exists($k,$GLOBALS['awvp_sync_meta'][$id] ?? array()); }
function update_post_meta(int $id,string $k,mixed $v): bool { $GLOBALS['awvp_sync_meta'][$id][$k]=$v; return true; }
function add_option(string $k,mixed $v,string $dep='',bool $autoload=true): bool { if(array_key_exists($k,$GLOBALS['awvp_sync_options']))return false; $GLOBALS['awvp_sync_options'][$k]=$v; return true; }
function get_option(string $k,mixed $d=false): mixed { return $GLOBALS['awvp_sync_options'][$k] ?? $d; }
function delete_option(string $k): bool { unset($GLOBALS['awvp_sync_options'][$k]); return true; }
function add_action(...$args): void {}
function parse_blocks(string $content): array { return array(array('blockName'=>'argentwolf-video-processor/video','attrs'=>array('videoId'=>100),'innerBlocks'=>array())); }
function get_posts(array $args): array {
    if (($args['post_type'] ?? '') !== 'argent_video') return array();
    if (($args['meta_key'] ?? '') === '_argent_video_origin_post_id') {
        $want=(string)($args['meta_value']??''); $out=array(); foreach($GLOBALS['awvp_sync_meta'] as $id=>$m){ if((string)($m['_argent_video_origin_post_id']??'')===$want)$out[]=$id; } return $out;
    }
    if (($args['meta_key'] ?? '') === '_argent_video_peertube_publication_lifecycle') {
        $out=array(); foreach($GLOBALS['awvp_sync_meta'] as $id=>$m){ if(array_key_exists('_argent_video_peertube_publication_lifecycle',$m))$out[]=$id; } return $out;
    }
    return array();
}
final class AwvpSyncWpdb {
    public string $prefix='wp_'; public int $insert_id=0; public array $rows=array(); private int $next=1;
    public function prepare(string $q,mixed ...$a): array{return array('query'=>$q,'args'=>$a);}
    public function get_row(mixed $p,mixed $o=null): ?array { if(!is_array($p))return null; $q=$p['query'];$a=$p['args']; if(str_contains($q,'idempotency_key = %s')){ $k=(string)($a[1]??'');foreach($this->rows as $r)if(($r['idempotency_key']??'')===$k)return $r;} if(str_contains($q,'WHERE id = %d'))return $this->rows[(int)($a[1]??0)]??null; return null; }
    public function insert(string $t,array $d,array $f): int|false { foreach($this->rows as $r)if(($r['idempotency_key']??'')===($d['idempotency_key']??''))return false; $id=$this->next++;$this->insert_id=$id;$d['id']=$id;$this->rows[$id]=$d;return 1; }
}
$GLOBALS['wpdb']=new AwvpSyncWpdb();
require_once dirname(__DIR__).'/includes/Backend_Identity.php'; require_once dirname(__DIR__).'/includes/Backend_Registry.php'; require_once dirname(__DIR__).'/includes/Video_Post_Type.php'; require_once dirname(__DIR__).'/includes/Video_Destination.php'; require_once dirname(__DIR__).'/includes/PeerTube_Publication_Plan.php'; require_once dirname(__DIR__).'/includes/PeerTube_Publication_Lifecycle.php'; require_once dirname(__DIR__).'/includes/Video_Meta.php'; require_once dirname(__DIR__).'/includes/Task_Repository.php'; require_once dirname(__DIR__).'/includes/Editorial_Publish_Validator.php'; require_once dirname(__DIR__).'/includes/PeerTube_Publication_Synchronizer.php';
use ArgentVideo\Video_Meta; use ArgentVideo\PeerTube_Publication_Plan as Plan; use ArgentVideo\PeerTube_Publication_Synchronizer as Sync; use ArgentVideo\Task_Repository; use ArgentVideo\Editorial_Publish_Validator;
$assert=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
$GLOBALS['awvp_sync_posts'][10]=(object)array('ID'=>10,'post_type'=>'post','post_status'=>'draft','post_content'=>'x');
$GLOBALS['awvp_sync_posts'][100]=(object)array('ID'=>100,'post_type'=>'argent_video_asset','post_status'=>'draft');
$plan=array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41','title'=>'Reviewed title','description_markdown'=>'','tags'=>array(),'support'=>array('mode'=>'none','preset_id'=>'','markdown'=>''),'final_privacy_id'=>'1','licence_id'=>'','category_id'=>'','language'=>'','thumbnail_attachment_id'=>0,'download_enabled'=>true,'originally_published_at'=>'','comments_policy'=>'enabled','moderation'=>array('reviewed'=>true,'sensitive'=>false,'reason'=>'','violent'=>false,'sexually_explicit'=>false),'embed'=>array('restricted'=>false,'domains'=>array()),'review'=>array('title'=>true,'channel'=>true,'tags'=>true,'privacy'=>true,'moderation'=>true),'dispatch_policy'=>Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,'release_policy'=>Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,'anchor_post_id'=>10);
$GLOBALS['awvp_sync_meta'][100]=array(Video_Meta::ORIGIN_POST_ID=>10,Video_Meta::DESTINATION=>array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41'),Video_Meta::PEERTUBE_PUBLICATION_PLAN=>$plan);
$sync=new Sync(new Task_Repository(),new Editorial_Publish_Validator());
$r=$sync->sync_video(100,'draft',1000); $assert(Task_Repository::APPLIED===$r['status'],'Draft lifecycle task did not enqueue.');
$life=$GLOBALS['awvp_sync_meta'][100][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]; $assert(false===$life['upload_authorized']&&false===$life['reveal_authorized']&&'3'===$life['target_privacy_id'],'Draft on-schedule intent was not remote-private/non-upload.');
$r=$sync->sync_video(100,'future',1010); $life=$GLOBALS['awvp_sync_meta'][100][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]; $assert(2===$life['generation']&&true===$life['upload_authorized']&&false===$life['reveal_authorized']&&'3'===$life['target_privacy_id'],'Future transition did not authorize private preupload with new generation.');
$r=$sync->sync_video(100,'publish',1020); $life=$GLOBALS['awvp_sync_meta'][100][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]; $assert(3===$life['generation']&&true===$life['reveal_authorized']&&'1'===$life['target_privacy_id'],'Actual publish did not authorize final privacy.');
$r=$sync->sync_video(100,'draft',1030); $life=$GLOBALS['awvp_sync_meta'][100][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]; $assert(4===$life['generation']&&false===$life['reveal_authorized']&&'3'===$life['target_privacy_id'],'Revert to draft did not supersede reveal with private intent.');
$plan['dispatch_policy']=Plan::DISPATCH_SEND_NOW; $GLOBALS['awvp_sync_meta'][100][Video_Meta::PEERTUBE_PUBLICATION_PLAN]=$plan; $r=$sync->sync_video(100,'draft',1040); $life=$GLOBALS['awvp_sync_meta'][100][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]; $assert(5===$life['generation']&&true===$life['upload_authorized']&&false===$life['reveal_authorized'],'Send-now draft did not authorize private upload only.');
$before=count($GLOBALS['wpdb']->rows); $sync->sync_video(100,'draft',1041); $assert($before===count($GLOBALS['wpdb']->rows),'Exact lifecycle replay duplicated a durable task.');
$source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Publication_Synchronizer.php'); foreach(array('PeerTube_Http_Client','PeerTube_Api_Client','wp_safe_remote','curl_','PeerTube_Staged_Upload_Service','transition_post_status(') as $needle){$assert(!str_contains($source,$needle),'Synchronizer acquired forbidden remote/inline execution authority: '.$needle);}
$worker=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Task_Worker.php'); $launcher=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Task_Worker_Launcher.php'); $plugin=(string)file_get_contents(dirname(__DIR__).'/includes/Plugin.php');
$assert(str_contains($worker,Sync::TASK_TYPE)&&str_contains($launcher,Sync::TASK_TYPE),'R46.5b publication task did not acquire explicit detached worker/launcher ownership.');
$assert(2===substr_count($plugin,'add_action(Activator::CRON_HOOK,'),'R46.5b changed the qualified recurring-dispatch topology.');
fwrite(STDOUT,"R46.5a publication synchronizer tests passed.\n");
