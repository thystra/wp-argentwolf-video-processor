<?php
/** Focused dependency-free tests for RC13.10 durable external AWVP Video binding. */
declare(strict_types=1);

$GLOBALS['ext_posts'] = array(10 => (object) array('ID'=>10,'post_type'=>'post','post_status'=>'publish','post_title'=>'Origin'));
$GLOBALS['ext_meta'] = array();
$GLOBALS['ext_options'] = array();
$GLOBALS['ext_next'] = 100;

class ExtWpdb { public string $prefix='wp_'; public string $last_error=''; }
$GLOBALS['wpdb'] = new ExtWpdb();
class WP_Error {}
function __(string $t,string $d=''): string { return $t; }
function sanitize_text_field(mixed $v): string { return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string)$v)) ?? ''); }
function sanitize_key(mixed $v): string { return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string)$v)) ?? ''; }
function wp_parse_url(string $url): array|false { return parse_url($url); }
function is_wp_error(mixed $v): bool { return $v instanceof WP_Error; }
function get_post(int $id): object|false { return $GLOBALS['ext_posts'][$id] ?? false; }
function wp_insert_post(array $data,bool $wp_error=false): int|WP_Error {
    $id=++$GLOBALS['ext_next']; $GLOBALS['ext_posts'][$id]=(object) array('ID'=>$id,'post_type'=>$data['post_type'],'post_status'=>$data['post_status'],'post_title'=>$data['post_title']); return $id;
}
function wp_delete_post(int $id,bool $force=false): object|false { $p=$GLOBALS['ext_posts'][$id]??false; unset($GLOBALS['ext_posts'][$id],$GLOBALS['ext_meta'][$id]); return $p; }
function update_post_meta(int $id,string $key,mixed $value): int|bool { $GLOBALS['ext_meta'][$id][$key]=$value; return 1; }
function get_post_meta(int $id,string $key,bool $single=false): mixed { $v=$GLOBALS['ext_meta'][$id][$key]??($single?'':array()); return $single?$v:array($v); }
function get_posts(array $args): array {
    $out=array(); foreach($GLOBALS['ext_posts'] as $id=>$post){ if(($post->post_type??'')!==($args['post_type']??''))continue; if(($GLOBALS['ext_meta'][$id][$args['meta_key']]??null)!==($args['meta_value']??null))continue; $out[]=$id; }
    return array_slice($out,0,(int)($args['posts_per_page']??2));
}
function get_option(string $n,mixed $d=false): mixed { return array_key_exists($n,$GLOBALS['ext_options'])?$GLOBALS['ext_options'][$n]:$d; }
function add_option(string $n,mixed $v='',string $deprecated='',bool|string $autoload='yes'): bool { if(array_key_exists($n,$GLOBALS['ext_options']))return false; $GLOBALS['ext_options'][$n]=$v; return true; }
function delete_option(string $n): bool { if(!array_key_exists($n,$GLOBALS['ext_options']))return false; unset($GLOBALS['ext_options'][$n]); return true; }

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
require_once dirname(__DIR__) . '/includes/Video_Embed_Provider.php';
require_once dirname(__DIR__) . '/includes/Video_Embed_Identity.php';
require_once dirname(__DIR__) . '/includes/YouTube_Embed_Provider.php';
require_once dirname(__DIR__) . '/includes/Vimeo_Embed_Provider.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Embed_Provider.php';
require_once dirname(__DIR__) . '/includes/Video_Embed_Resolver.php';
require_once dirname(__DIR__) . '/includes/External_Video_Source.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Public_Video_Verifier.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Remote_Asset_Store.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Asset_Store.php';
require_once dirname(__DIR__) . '/includes/Remote_Asset_Repository.php';
require_once dirname(__DIR__) . '/includes/Video_Post_Type.php';
require_once dirname(__DIR__) . '/includes/Source_Retirement_Record.php';
require_once dirname(__DIR__) . '/includes/Video_Destination.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Plan.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Migration_Plan.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Migration_Execution.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Lifecycle.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Manifest.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Execution.php';
require_once dirname(__DIR__) . '/includes/Remote_Republish_Request.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Input.php';
require_once dirname(__DIR__) . '/includes/Video_Serving_Authority.php';
require_once dirname(__DIR__) . '/includes/Local_Retention_Policy.php';
require_once dirname(__DIR__) . '/includes/WordPress_Source_File.php';
require_once dirname(__DIR__) . '/includes/Local_Retention_Execution.php';
require_once dirname(__DIR__) . '/includes/Video_Meta.php';
require_once dirname(__DIR__) . '/includes/External_Video_Source_Service.php';

use ArgentVideo\Backend_Registry;
use ArgentVideo\External_Video_Source;
use ArgentVideo\External_Video_Source_Service;
use ArgentVideo\PeerTube_Public_Video_Verifier;
use ArgentVideo\Remote_Asset_Repository;
use ArgentVideo\Video_Embed_Resolver;
use ArgentVideo\Video_Meta;

$assert=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
$service=new External_Video_Source_Service(
    new Video_Embed_Resolver(),
    new PeerTube_Public_Video_Verifier(static fn(string $origin):object=>new class{public function get_public_video(string $id):array{return array('ok'=>false,'http_status'=>404,'headers'=>array(),'body'=>'','error'=>array());}}),
    new Backend_Registry(),
    new Remote_Asset_Repository()
);
$r1=$service->bind_url('https://youtu.be/dQw4w9WgXcQ?t=1',10,7,1000);
$assert(External_Video_Source_Service::APPLIED===$r1['status']&&$r1['video_id']>0,'YouTube external source was not created.');
$id=$r1['video_id'];
$assert('external'===($GLOBALS['ext_meta'][$id][Video_Meta::SOURCE_STATE]??''),'External source state was not persisted.');
$assert('external_archive'===($GLOBALS['ext_meta'][$id][Video_Meta::MASTER_AUTHORITY]??''),'External source acquired wrong master authority.');
$record=External_Video_Source::sanitize($GLOBALS['ext_meta'][$id][Video_Meta::EXTERNAL_SOURCE]??array());
$assert('youtube'===($record['identity']['provider']??''),'External provider identity was not persisted.');
$assert('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ'===($record['identity']['embed_url']??''),'External embed URL was not canonical/no-autoplay.');
$r2=$service->bind_url('https://www.youtube.com/embed/dQw4w9WgXcQ?autoplay=1',10,7,1001);
$assert(External_Video_Source_Service::PRESENT===$r2['status']&&$id===$r2['video_id'],'Equivalent external URL did not reuse the existing AWVP Video.');
$assert(2===count($GLOBALS['ext_posts']),'Equivalent URL created a duplicate AWVP Video.');

echo "PASS: external URL binding creates one durable provider-neutral AWVP Video and reuses canonical equivalents.\n";
