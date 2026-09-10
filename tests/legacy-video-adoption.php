<?php
/** Focused dependency-free tests for RC10 legacy AWVP 1.x discovery/adoption. */
declare(strict_types=1);

$GLOBALS['awvp_legacy_posts'] = array();
$GLOBALS['awvp_legacy_meta'] = array();
$GLOBALS['awvp_legacy_options'] = array();
$GLOBALS['awvp_legacy_next_post_id'] = 500;
$GLOBALS['awvp_legacy_url_ids'] = array();

function __(string $text, string $domain = ''): string { unset($domain); return $text; }
function sanitize_text_field(mixed $value): string { return trim(strip_tags((string)$value)); }
function sanitize_key(mixed $value): string { return preg_replace('/[^a-z0-9_-]/', '', strtolower((string)$value)) ?? ''; }
function esc_url_raw(string $url): string { return $url; }
function wp_get_post_tags(int $post_id, array $args = array()): array { unset($post_id,$args); return array(); }
function get_option(string $name, mixed $default = false): mixed { return $GLOBALS['awvp_legacy_options'][$name] ?? $default; }
function add_option(string $name, mixed $value = '', string $deprecated = '', bool|string $autoload = 'yes'): bool { unset($deprecated,$autoload); if (array_key_exists($name,$GLOBALS['awvp_legacy_options'])) return false; $GLOBALS['awvp_legacy_options'][$name]=$value; return true; }
function update_option(string $name, mixed $value, bool|string|null $autoload = null): bool { unset($autoload); $GLOBALS['awvp_legacy_options'][$name]=$value; return true; }
function delete_option(string $name): bool { if (!array_key_exists($name,$GLOBALS['awvp_legacy_options'])) return false; unset($GLOBALS['awvp_legacy_options'][$name]); return true; }
function wp_set_option_autoload(string $name, bool|string $autoload): bool { unset($name,$autoload); return true; }
function wp_load_alloptions(bool $force_cache = false): array { unset($force_cache); return array(); }
function get_post(int $post_id): object|false { return $GLOBALS['awvp_legacy_posts'][$post_id] ?? false; }
function metadata_exists(string $type, int $object_id, string $meta_key): bool { unset($type); return array_key_exists($meta_key,$GLOBALS['awvp_legacy_meta'][$object_id]??array()); }
function get_post_meta(int $post_id, string $meta_key, bool $single = false): mixed { $v=$GLOBALS['awvp_legacy_meta'][$post_id][$meta_key]??($single?'':array()); return $single?$v:array($v); }
function update_post_meta(int $post_id, string $meta_key, mixed $value): int|bool { $GLOBALS['awvp_legacy_meta'][$post_id][$meta_key]=$value; return 1; }
function add_post_meta(int $post_id, string $meta_key, mixed $value, bool $unique = false): int|false { if ($unique && metadata_exists('post',$post_id,$meta_key)) return false; $GLOBALS['awvp_legacy_meta'][$post_id][$meta_key]=$value; return 1; }
function wp_attachment_is(string $type, int $post_id): bool { $p=$GLOBALS['awvp_legacy_posts'][$post_id]??null; return 'video'===$type && is_object($p) && str_starts_with((string)($p->post_mime_type??''),'video/'); }
function get_attached_file(int $attachment_id, bool $unfiltered = false): string|false { unset($unfiltered); $p=$GLOBALS['awvp_legacy_posts'][$attachment_id]??null; return is_object($p)&&is_string($p->file??null)?$p->file:false; }
function attachment_url_to_postid(string $url): int { return (int)($GLOBALS['awvp_legacy_url_ids'][$url]??0); }
function is_wp_error(mixed $thing): bool { return false; }
function wp_insert_post(array $postarr, bool $wp_error = false): int { unset($wp_error); $id=$GLOBALS['awvp_legacy_next_post_id']++; $GLOBALS['awvp_legacy_posts'][$id]=(object)array_merge(array('ID'=>$id,'post_author'=>0),$postarr); return $id; }
function wp_delete_post(int $post_id, bool $force_delete = false): object|false { unset($force_delete); $p=$GLOBALS['awvp_legacy_posts'][$post_id]??false; if(false===$p)return false; unset($GLOBALS['awvp_legacy_posts'][$post_id],$GLOBALS['awvp_legacy_meta'][$post_id]); return $p; }
function parse_blocks(string $content): array {
    $out=[];
    if (preg_match_all('/<!--\s+wp:video\s+\{[^}]*"id"\s*:\s*([1-9][0-9]*)[^}]*\}\s+-->/', $content, $m)) {
        foreach ($m[1] as $id) $out[]=array('blockName'=>'core/video','attrs'=>array('id'=>(int)$id),'innerBlocks'=>array());
    }
    return $out;
}
function get_posts(array $args = array()): array {
    $ids=[];
    foreach ($GLOBALS['awvp_legacy_posts'] as $id=>$p) {
        $pt=(string)($p->post_type??'');
        $want=(string)($args['post_type']??'post');
        if ('any'!==$want && $pt!==$want) continue;
        if ('attachment'===$want && isset($args['post_mime_type']) && 'video'===$args['post_mime_type'] && !str_starts_with((string)($p->post_mime_type??''),'video/')) continue;
        if (isset($args['meta_key']) && !metadata_exists('post',(int)$id,(string)$args['meta_key'])) continue;
        if (isset($args['meta_value']) && get_post_meta((int)$id,(string)$args['meta_key'],true)!==$args['meta_value']) continue;
        $ids[]=(int)$id;
    }
    sort($ids,SORT_NUMERIC);
    $offset=(int)($args['offset']??0);
    $limit=(int)($args['posts_per_page']??count($ids));
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
require_once dirname(__DIR__) . '/includes/Video_Post_Type.php';
require_once dirname(__DIR__) . '/includes/Video_Publishing_Defaults.php';
require_once dirname(__DIR__) . '/includes/Video_Publishing_Defaults_Store.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Catalog.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Catalog_Store.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Plan.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Lifecycle.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Manifest.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Execution.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Migration_Plan.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Migration_Execution.php';
require_once dirname(__DIR__) . '/includes/Local_Retention_Policy.php';
require_once dirname(__DIR__) . '/includes/Local_Retention_Execution.php';
require_once dirname(__DIR__) . '/includes/Video_Serving_Authority.php';
require_once dirname(__DIR__) . '/includes/Video_Meta.php';
require_once dirname(__DIR__) . '/includes/Legacy_Video_Adoption_Service.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Migration_Planner.php';

use ArgentVideo\Backend_Registry;
use ArgentVideo\Legacy_Video_Adoption_Service;
use ArgentVideo\PeerTube_Migration_Plan;
use ArgentVideo\PeerTube_Migration_Planner;
use ArgentVideo\PeerTube_Publication_Catalog_Store;
use ArgentVideo\Video_Publishing_Defaults;
use ArgentVideo\Video_Publishing_Defaults_Store;
use ArgentVideo\Video_Destination;
use ArgentVideo\Video_Meta;
use ArgentVideo\Video_Post_Type;

$assert=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}};
$tmp=sys_get_temp_dir().'/awvp-legacy-adoption-'.bin2hex(random_bytes(4));
mkdir($tmp,0700,true);
foreach (array(20,21,22,23,24) as $id) {
    $file=$tmp.'/legacy-'.$id.'.mp4'; file_put_contents($file,"legacy {$id}\n");
    $GLOBALS['awvp_legacy_posts'][$id]=(object)array('ID'=>$id,'post_type'=>'attachment','post_status'=>'inherit','post_title'=>'Legacy '.$id,'post_mime_type'=>'video/mp4','post_author'=>7,'file'=>$file);
    $GLOBALS['awvp_legacy_meta'][$id]=array(Legacy_Video_Adoption_Service::LEGACY_STATUS_META=>'complete',Legacy_Video_Adoption_Service::LEGACY_OUTPUTS_META=>array('mp4'=>array('url'=>'https://example.test/'.$id.'.mp4')));
}
$GLOBALS['awvp_legacy_posts'][10]=(object)array('ID'=>10,'post_type'=>'post','post_status'=>'publish','post_title'=>'Origin A','post_author'=>9,'post_content'=>'<!-- wp:video {"id":20} --><video></video><!-- /wp:video -->');
$GLOBALS['awvp_legacy_posts'][11]=(object)array('ID'=>11,'post_type'=>'post','post_status'=>'publish','post_title'=>'Origin B','post_author'=>9,'post_content'=>'<!-- wp:video {"id":21} --><video></video><!-- /wp:video -->');
$GLOBALS['awvp_legacy_posts'][12]=(object)array('ID'=>12,'post_type'=>'page','post_status'=>'publish','post_title'=>'Second B','post_author'=>9,'post_content'=>'<!-- wp:video {"id":21} --><video></video><!-- /wp:video -->');
$GLOBALS['awvp_legacy_posts'][13]=(object)array('ID'=>13,'post_type'=>'post','post_status'=>'publish','post_title'=>'Origin shortcode','post_author'=>9,'post_content'=>'[video id="24"]');
// 22 has no anchor; 23 is missing its source file.
unlink($GLOBALS['awvp_legacy_posts'][23]->file);

$service=new Legacy_Video_Adoption_Service();
$eligible=$service->inspect(20);
$assert('eligible'===$eligible['status'] && 10===$eligible['anchor_post_id'],'Single-post completed legacy attachment was not discovered as eligible.');
$assert('anchor_ambiguous'===$service->inspect(21)['status'],'Multi-post legacy attachment was not excluded as ambiguous.');
$assert('anchor_missing'===$service->inspect(22)['status'],'Legacy attachment without publication anchor was not excluded.');
$assert('source_missing'===$service->inspect(23)['status'],'Legacy attachment with missing WordPress source was not excluded.');
$assert('eligible'===$service->inspect(24)['status'] && 13===$service->inspect(24)['anchor_post_id'],'Supported legacy video shortcode anchor was not discovered.');

$before_posts=count($GLOBALS['awvp_legacy_posts']);
$scan=$service->candidates(100,0);
$assert($before_posts===count($GLOBALS['awvp_legacy_posts']),'Read-only legacy discovery created a 2.0 model object.');
$assert(2===count($scan['items']),'Legacy candidate scan did not return exactly the two unambiguous fixtures.');
$assert('legacy:20'===$scan['items'][0]['candidate_key'] && 0===$scan['items'][0]['video_id'],'Legacy candidate identity is not explicitly distinct from an AWVP Video ID.');
$census=$service->census();
$assert(5===$census['completed'] && 2===$census['eligible'] && 1===$census['source_missing'] && 1===$census['anchor_missing'] && 1===$census['anchor_ambiguous'],'Legacy census classifications are incorrect.');

$post_content_before=$GLOBALS['awvp_legacy_posts'][10]->post_content;
$attachment_meta_before=$GLOBALS['awvp_legacy_meta'][20];
$adopt=$service->adopt(20,2000000000);
$video_id=$adopt['video_id'];
$assert(Legacy_Video_Adoption_Service::APPLIED===$adopt['status'] && $video_id>=500,'Explicit legacy adoption did not create an AWVP Video.');
$assert(Video_Post_Type::POST_TYPE===($GLOBALS['awvp_legacy_posts'][$video_id]->post_type??null),'Adoption created the wrong post type.');
$assert($video_id===(int)get_post_meta(20,Legacy_Video_Adoption_Service::ATTACHMENT_ASSET_META,true),'Adoption did not create attachment reverse binding.');
$assert(20===Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ATTACHMENT_ID,true)),'Adopted Video attachment binding is incorrect.');
$assert(10===Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ORIGIN_POST_ID,true)),'Adopted Video origin anchor is incorrect.');
$assert('wordpress_attachment'===get_post_meta($video_id,Video_Meta::INGEST_KIND,true),'Adopted ingest kind is incorrect.');
$assert('wordpress_source'===get_post_meta($video_id,Video_Meta::MASTER_AUTHORITY,true),'Adopted master authority is incorrect.');
$assert('present'===get_post_meta($video_id,Video_Meta::SOURCE_STATE,true),'Adopted source state is incorrect.');
$assert(Video_Destination::local()===Video_Destination::sanitize(get_post_meta($video_id,Video_Meta::DESTINATION,true)),'Legacy adoption did not force the local destination.');
$assert($post_content_before===$GLOBALS['awvp_legacy_posts'][10]->post_content,'Legacy adoption rewrote historical post_content.');
foreach ($attachment_meta_before as $key=>$value) $assert($value===get_post_meta(20,$key,true),'Legacy adoption altered historical attachment processing metadata: '.$key);
$assert(!metadata_exists('post',$video_id,Video_Meta::PEERTUBE_PUBLICATION_PLAN),'Legacy adoption prematurely created publication work.');
$assert(!metadata_exists('post',$video_id,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION),'Legacy adoption prematurely created execution work.');
$assert(!metadata_exists('post',$video_id,Video_Meta::PEERTUBE_MIGRATION_PLAN),'Legacy adoption prematurely created migration plan itself.');
$again=$service->adopt(20,2000000010);
$assert(Legacy_Video_Adoption_Service::PRESENT===$again['status'] && $video_id===$again['video_id'],'Legacy adoption did not converge idempotently on the established binding.');

// The migration planner may explicitly adopt a selected legacy key, then plan
// the resulting local 2.0 Video without any upgrade-time mass adoption.
$local=array('id'=>'local','type'=>'local','label'=>'Local','state'=>'active','default_destination'=>'','secret_ref'=>'','config_version'=>1,'config'=>array());
$pt=array('id'=>'pt-primary','type'=>'peertube','label'=>'PeerTube','state'=>'active','default_destination'=>'77','secret_ref'=>'managed:pt-primary','config_version'=>1,'config'=>array('origin'=>'https://video.example.org'));
$GLOBALS['awvp_legacy_options'][Backend_Registry::OPTION]=array('version'=>1,'backends'=>array('local'=>$local,'pt-primary'=>$pt));
$settings=Video_Publishing_Defaults::defaults();
$settings['backend_overrides']['pt-primary']=array('channel_id'=>'77','final_privacy_id'=>'2','licence_id'=>null,'category_id'=>null);
$GLOBALS['awvp_legacy_options'][Video_Publishing_Defaults_Store::OPTION]=$settings;
$GLOBALS['awvp_legacy_options'][PeerTube_Publication_Catalog_Store::option_name('pt-primary')]=array(
    'version'=>1,'backend_id'=>'pt-primary','origin'=>'https://video.example.org','secret_generation'=>3,
    'server_version'=>'8.2.4','refreshed_at'=>2000000000,'stale'=>false,'stale_since'=>null,'stale_reason'=>'',
    'channels'=>array(array('id'=>'77','name'=>'main','display_name'=>'Main','authority'=>'owned')),
    'privacies'=>array('1'=>'Public','2'=>'Unlisted','3'=>'Private','5'=>'Password protected'),
    'licences'=>array('1'=>'Attribution'),'categories'=>array('2'=>'People'),'languages'=>array('en'=>'English'),
    'capabilities'=>array('sensitive_content'=>true,'sensitive_flags'=>true,'password_privacy'=>true),
);
$planner=new PeerTube_Migration_Planner(new Backend_Registry(),new Video_Publishing_Defaults_Store(new Backend_Registry()),new PeerTube_Publication_Catalog_Store(),$service);

// A large/current 2.0 candidate set must not starve legacy discovery from the
// bounded first migration page. The old current-first concatenation returned
// only current candidates whenever it filled the requested window.
foreach (array(600=>30,601=>31) as $current_video_id=>$current_attachment_id) {
    $current_file=$tmp.'/current-'.$current_attachment_id.'.mp4'; file_put_contents($current_file,"current {$current_attachment_id}\n");
    $current_anchor_id=100+$current_attachment_id;
    $GLOBALS['awvp_legacy_posts'][$current_attachment_id]=(object)array('ID'=>$current_attachment_id,'post_type'=>'attachment','post_status'=>'inherit','post_title'=>'Current '.$current_attachment_id,'post_mime_type'=>'video/mp4','post_author'=>7,'file'=>$current_file);
    $GLOBALS['awvp_legacy_posts'][$current_anchor_id]=(object)array('ID'=>$current_anchor_id,'post_type'=>'post','post_status'=>'publish','post_title'=>'Current anchor '.$current_attachment_id,'post_author'=>9,'post_content'=>'');
    $GLOBALS['awvp_legacy_posts'][$current_video_id]=(object)array('ID'=>$current_video_id,'post_type'=>Video_Post_Type::POST_TYPE,'post_status'=>'publish','post_title'=>'Current video '.$current_attachment_id,'post_author'=>9,'post_content'=>'');
    $GLOBALS['awvp_legacy_meta'][$current_video_id]=array(
        Video_Meta::ATTACHMENT_ID=>$current_attachment_id,
        Video_Meta::ORIGIN_POST_ID=>$current_anchor_id,
        Video_Meta::SOURCE_STATE=>'present',
        Video_Meta::DESTINATION=>Video_Destination::local(),
    );
}
$fair_page=$planner->candidates(2,0);
$assert(2===count($fair_page['items']),'Fair migration candidate page did not return the requested bounded size.');
$assert(false===($fair_page['items'][0]['legacy']??true),'Fair migration page should retain a current 2.0 candidate in the first position.');
$assert('legacy:24'===($fair_page['items'][1]['candidate_key']??''),'Existing 2.0 candidates starved an eligible legacy candidate from the first bounded page.');
$assert(true===$fair_page['more'],'Fair migration page did not preserve pagination when additional current candidates remain.');

$pre_plan_post_count=count($GLOBALS['awvp_legacy_posts']);
$planned=$planner->plan_candidates(array('legacy:24'),'pt-primary','77',2000000020);
$assert(1===count($planned['applied']),'Explicit legacy candidate planning did not adopt and create a migration plan.');
$legacy_video_id=(int)$planned['applied'][0];
$assert($legacy_video_id>0 && $pre_plan_post_count+1===count($GLOBALS['awvp_legacy_posts']),'Explicit planning did not create exactly one adopted 2.0 Video.');
$legacy_plan=PeerTube_Migration_Plan::sanitize(get_post_meta($legacy_video_id,Video_Meta::PEERTUBE_MIGRATION_PLAN,true));
$assert(array()!==$legacy_plan && 24===(int)$legacy_plan['attachment_id'] && 13===(int)$legacy_plan['anchor_post_id'],'Adopted legacy migration plan lost attachment/origin identity.');
$assert(Video_Destination::is_local(Video_Destination::sanitize(get_post_meta($legacy_video_id,Video_Meta::DESTINATION,true))),'Planning changed adopted legacy live destination before execution.');
$assert(!metadata_exists('post',$legacy_video_id,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION),'Legacy planning prematurely dispatched PeerTube execution.');
$repeat=$planner->plan_candidates(array('legacy:24'),'pt-primary','77',2000000030);
$assert(array($legacy_video_id)===$repeat['present'],'Repeated explicit planning did not converge on the adopted Video and preserve its plan.');

foreach (glob($tmp.'/*')?:array() as $f) @unlink($f); @rmdir($tmp);
$source=(string)file_get_contents(dirname(__DIR__).'/includes/Legacy_Video_Adoption_Service.php');
foreach (array('wp_remote_','PeerTube_Api_Client','Task_Repository','transition_post_status','wp_publish_post') as $forbidden) $assert(!str_contains($source,$forbidden),'Legacy adoption acquired forbidden provider/publication authority: '.$forbidden);

echo "Legacy video discovery/adoption tests passed.\n";
