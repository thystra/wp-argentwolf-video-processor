<?php
/** Focused dependency-free tests for R46.7 migration planner. */
declare(strict_types=1);

$GLOBALS['awvp_migration_options'] = array();
$GLOBALS['awvp_migration_posts'] = array();
$GLOBALS['awvp_migration_meta'] = array();
$GLOBALS['awvp_migration_tags'] = array();

function __(string $text, string $domain = ''): string { unset($domain); return $text; }
function sanitize_text_field(mixed $value): string { return trim((string) $value); }
function sanitize_key(mixed $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string) $value)) ?? ''; }
function esc_url_raw(string $url): string { return $url; }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['awvp_migration_options'][$name] ?? $default; }
function add_option(string $name, mixed $value = '', string $deprecated = '', bool|string $autoload = 'yes'): bool { unset($deprecated,$autoload); if (array_key_exists($name,$GLOBALS['awvp_migration_options'])) return false; $GLOBALS['awvp_migration_options'][$name]=$value; return true; }
function update_option(string $name, mixed $value, bool|string|null $autoload = null): bool { unset($autoload); $GLOBALS['awvp_migration_options'][$name]=$value; return true; }
function wp_set_option_autoload(string $name, bool|string $autoload): bool { unset($name,$autoload); return true; }
function wp_load_alloptions(bool $force_cache = false): array { unset($force_cache); return array(); }
function get_post(int $post_id): object|false { return $GLOBALS['awvp_migration_posts'][$post_id] ?? false; }
function metadata_exists(string $type, int $object_id, string $meta_key): bool { unset($type); return array_key_exists($meta_key, $GLOBALS['awvp_migration_meta'][$object_id] ?? array()); }
function get_post_meta(int $post_id, string $meta_key, bool $single = false): mixed { $v=$GLOBALS['awvp_migration_meta'][$post_id][$meta_key] ?? ($single ? '' : array()); return $single ? $v : array($v); }
function update_post_meta(int $post_id, string $meta_key, mixed $value): int|bool { $GLOBALS['awvp_migration_meta'][$post_id][$meta_key]=$value; return 1; }
function wp_attachment_is(string $type, int $post_id): bool { $p=$GLOBALS['awvp_migration_posts'][$post_id] ?? null; return 'video' === $type && is_object($p) && str_starts_with((string)($p->post_mime_type ?? ''),'video/'); }
function wp_get_post_tags(int $post_id, array $args = array()): array { unset($args); return $GLOBALS['awvp_migration_tags'][$post_id] ?? array(); }
function get_posts(array $args = array()): array
{
    $ids=[];
    foreach ($GLOBALS['awvp_migration_posts'] as $id=>$p) {
        if (($p->post_type ?? '') !== ($args['post_type'] ?? 'post')) continue;
        if (isset($args['meta_key']) && !metadata_exists('post',(int)$id,(string)$args['meta_key'])) continue;
        $ids[]=(int)$id;
    }
    sort($ids,SORT_NUMERIC);
    $offset=(int)($args['offset'] ?? 0);
    $limit=(int)($args['posts_per_page'] ?? count($ids));
    return array_slice($ids,$offset,$limit);
}

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Input.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Snapshot.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Mutation_Plan.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Plan_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Store.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
require_once dirname(__DIR__) . '/includes/Video_Destination.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Plan.php';
require_once dirname(__DIR__) . '/includes/Video_Publishing_Defaults.php';
require_once dirname(__DIR__) . '/includes/Video_Publishing_Defaults_Store.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Catalog.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Catalog_Store.php';
require_once dirname(__DIR__) . '/includes/Video_Post_Type.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Migration_Plan.php';
require_once dirname(__DIR__) . '/includes/Video_Meta.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Migration_Planner.php';

use ArgentVideo\Backend_Registry;
use ArgentVideo\PeerTube_Migration_Plan;
use ArgentVideo\PeerTube_Migration_Planner;
use ArgentVideo\PeerTube_Publication_Catalog_Store;
use ArgentVideo\PeerTube_Publication_Plan;
use ArgentVideo\Video_Destination;
use ArgentVideo\Video_Meta;
use ArgentVideo\Video_Publishing_Defaults;
use ArgentVideo\Video_Publishing_Defaults_Store;

$assert=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}};

$local=array('id'=>'local','type'=>'local','label'=>'Local','state'=>'active','default_destination'=>'','secret_ref'=>'','config_version'=>1,'config'=>array());
$pt=array('id'=>'pt-primary','type'=>'peertube','label'=>'PeerTube','state'=>'active','default_destination'=>'41','secret_ref'=>'managed:pt-primary','config_version'=>1,'config'=>array('origin'=>'https://video.example.org'));
$GLOBALS['awvp_migration_options'][Backend_Registry::OPTION]=array('version'=>1,'backends'=>array('local'=>$local,'pt-primary'=>$pt));
$settings=Video_Publishing_Defaults::defaults();
$settings['backend_overrides']['pt-primary']=array('channel_id'=>'77','final_privacy_id'=>'2','licence_id'=>null,'category_id'=>null);
$GLOBALS['awvp_migration_options'][Video_Publishing_Defaults_Store::OPTION]=$settings;
$catalog=array(
    'version'=>1,'backend_id'=>'pt-primary','origin'=>'https://video.example.org','secret_generation'=>3,
    'server_version'=>'8.1.2','refreshed_at'=>2000000000,'stale'=>false,'stale_since'=>null,'stale_reason'=>'',
    'channels'=>array(array('id'=>'77','name'=>'main','display_name'=>'Main','authority'=>'owned')),
    'privacies'=>array('1'=>'Public','2'=>'Unlisted','3'=>'Private','5'=>'Password protected'),
    'licences'=>array('1'=>'Attribution'),'categories'=>array('2'=>'People'),'languages'=>array('en'=>'English'),
    'capabilities'=>array('sensitive_content'=>true,'sensitive_flags'=>true,'password_privacy'=>true),
);
$GLOBALS['awvp_migration_options'][PeerTube_Publication_Catalog_Store::option_name('pt-primary')]=$catalog;

$GLOBALS['awvp_migration_posts'][10]=(object)array('ID'=>10,'post_type'=>'post','post_status'=>'publish','post_title'=>'Origin Post');
$GLOBALS['awvp_migration_posts'][11]=(object)array('ID'=>11,'post_type'=>'post','post_status'=>'publish','post_title'=>'Ambiguous Origin');
$GLOBALS['awvp_migration_posts'][20]=(object)array('ID'=>20,'post_type'=>'attachment','post_status'=>'inherit','post_title'=>'Legacy Clip','post_mime_type'=>'video/mp4');
$GLOBALS['awvp_migration_posts'][21]=(object)array('ID'=>21,'post_type'=>'attachment','post_status'=>'inherit','post_title'=>'Ambiguous Clip','post_mime_type'=>'video/mp4');
$GLOBALS['awvp_migration_posts'][100]=(object)array('ID'=>100,'post_type'=>'argent_video_asset','post_status'=>'publish','post_title'=>'Legacy Clip');
$GLOBALS['awvp_migration_posts'][101]=(object)array('ID'=>101,'post_type'=>'argent_video_asset','post_status'=>'publish','post_title'=>'Ambiguous Clip');
$GLOBALS['awvp_migration_meta'][100]=array(Video_Meta::ATTACHMENT_ID=>20,Video_Meta::ORIGIN_POST_ID=>10,Video_Meta::SOURCE_STATE=>'present');
$GLOBALS['awvp_migration_meta'][101]=array(Video_Meta::ATTACHMENT_ID=>21,Video_Meta::ORIGIN_POST_ID=>11,Video_Meta::SOURCE_STATE=>'present',Video_Meta::DESTINATION=>Video_Destination::local());
$GLOBALS['awvp_migration_tags'][10]=array('alpha','beta');
$GLOBALS['awvp_migration_tags'][11]=array('one','two','three','four','five','six');

$planner=new PeerTube_Migration_Planner(new Backend_Registry(),new Video_Publishing_Defaults_Store(new Backend_Registry()),new PeerTube_Publication_Catalog_Store());
$scan=$planner->candidates(100,0);
$assert(2===count($scan['items']),'Expected two eligible local videos.');
$assert('none'===$scan['items'][0]['plan_status'],'Legacy missing destination did not resolve local.');

$result=$planner->plan(array(100,101),'pt-primary','77',2000000100);
$assert(array(100,101)===$result['applied'],'Selected migration plans were not persisted.');
$plan100=PeerTube_Migration_Plan::sanitize($GLOBALS['awvp_migration_meta'][100][Video_Meta::PEERTUBE_MIGRATION_PLAN]??null);
$plan101=PeerTube_Migration_Plan::sanitize($GLOBALS['awvp_migration_meta'][101][Video_Meta::PEERTUBE_MIGRATION_PLAN]??null);
$assert(array()!==$plan100 && PeerTube_Migration_Plan::STATUS_NEEDS_REVIEW===$plan100['status'],'First migration plan not Needs Review.');
$assert(array('alpha','beta')===($plan100['publication_plan']['tags']??null),'Unambiguous <=5 WordPress tags were not offered as migration suggestions.');
$assert(false===($plan100['publication_plan']['review']['tags']??true),'Tag suggestions were incorrectly treated as reviewed.');
$assert(in_array('tag_selection_required',$plan101['issues'],true),'More-than-five tag suggestions did not require selection.');
$assert(array()===($plan101['publication_plan']['tags']??null),'Ambiguous >5 tags were silently truncated.');

// Planner state is inert: it must not write live destination/publication/lifecycle/execution/serving metadata.
foreach (array(100,101) as $video_id) {
    $assert(!metadata_exists('post',$video_id,Video_Meta::PEERTUBE_PUBLICATION_PLAN),'Planner wrote live publication plan.');
    $assert(!metadata_exists('post',$video_id,Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE),'Planner wrote lifecycle state.');
    $assert(!metadata_exists('post',$video_id,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION),'Planner wrote execution state.');
    $assert(!metadata_exists('post',$video_id,Video_Meta::SERVING_AUTHORITY),'Planner wrote serving authority.');
    $dest=Video_Destination::resolve(get_post_meta($video_id,Video_Meta::DESTINATION,true),metadata_exists('post',$video_id,Video_Meta::DESTINATION));
    $assert(Video_Destination::is_local($dest),'Planner changed live destination.');
}

// Explicit review can move one inert migration plan to READY without waking execution.
$review=$plan100['publication_plan'];
$review['review']=array('title'=>true,'channel'=>true,'tags'=>true,'privacy'=>true,'moderation'=>true);
$review['moderation']['reviewed']=true;
$save=$planner->review(100,$review,2000000200);
$assert(PeerTube_Migration_Planner::APPLIED===$save['status'],'Reviewed migration plan did not save.');
$ready=PeerTube_Migration_Plan::sanitize($GLOBALS['awvp_migration_meta'][100][Video_Meta::PEERTUBE_MIGRATION_PLAN]??null);
$assert(PeerTube_Migration_Plan::STATUS_READY===$ready['status'],'Fully reviewed migration plan did not become ready.');
$replan=$planner->plan(array(100),'pt-primary','77',2000000250);
$assert(array(100)===$replan['present'],'Same-target select-all replay did not preserve reviewed migration state.');
$preserved=PeerTube_Migration_Plan::sanitize($GLOBALS['awvp_migration_meta'][100][Video_Meta::PEERTUBE_MIGRATION_PLAN]??null);
$assert(PeerTube_Migration_Plan::STATUS_READY===$preserved['status'],'Same-target replan reset completed review.');
$assert(array()===$ready['issues'],'Ready migration plan retained Needs Review issues.');
$assert(!metadata_exists('post',100,Video_Meta::PEERTUBE_PUBLICATION_PLAN),'Review promoted migration plan prematurely.');

// Provider/context drift fails closed.
$bad=$review; $bad['channel_id']='999';
$refused=$planner->review(100,$bad,2000000300);
$assert(PeerTube_Migration_Planner::REFUSED===$refused['status'],'Unavailable migration channel was accepted.');

// Existing remote destinations are not migration candidates.
$GLOBALS['awvp_migration_meta'][101][Video_Meta::DESTINATION]=array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'77');
$assert(null===$planner->candidate(101),'Already-remote video remained migration eligible.');

// Bounded select-all guard refuses oversized batches without writes.
$too_many=array_fill(0,PeerTube_Migration_Planner::MAX_SELECT_ALL+1,100);
$bounded=$planner->plan($too_many,'pt-primary','77',2000000400);
$assert(array()===array_merge($bounded['applied'],$bounded['present'],$bounded['refused'],$bounded['indeterminate']),'Oversized select-all was not refused before writes.');

// Source scan forbids consequential surfaces.
$source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Migration_Planner.php');
foreach (array('wp_remote_','PeerTube_Api_Client','Task_Repository','transition_post_status','wp_publish_post','delete_attachment','wp_delete_post','Video_Meta::PEERTUBE_PUBLICATION_PLAN, $record') as $forbidden) {
    $assert(!str_contains($source,$forbidden),'Migration planner acquired forbidden authority: '.$forbidden);
}

echo "PeerTube migration planner test passed.\n";
