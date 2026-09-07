<?php
/** Dependency-free tests for R46.5b publication source staging. */
declare(strict_types=1);

$GLOBALS['awvp_stage_meta'] = array();
$GLOBALS['awvp_stage_mime'] = array();
$GLOBALS['awvp_stage_files'] = array();
$GLOBALS['awvp_stage_uploads'] = sys_get_temp_dir().'/awvp-publication-staging-'.bin2hex(random_bytes(4));
@mkdir($GLOBALS['awvp_stage_uploads'], 0777, true);

function wp_upload_dir(): array { return array('basedir'=>$GLOBALS['awvp_stage_uploads'],'baseurl'=>'https://example.test/uploads','error'=>false); }
function wp_normalize_path(string $p): string { return str_replace('\\','/',$p); }
function wp_mkdir_p(string $p): bool { return is_dir($p) || mkdir($p,0777,true); }
function wp_delete_file(string $p): void { @unlink($p); }
function get_post_meta(int $id,string $k,bool $single=true): mixed { return $GLOBALS['awvp_stage_meta'][$id][$k] ?? ''; }
function get_post_mime_type(int $id): string|false { return $GLOBALS['awvp_stage_mime'][$id] ?? false; }
function get_attached_file(int $id,bool $unfiltered=false): string|false { return $GLOBALS['awvp_stage_files'][$id] ?? false; }
function __($s,$d=''){return $s;}
function absint($v): int { return abs((int)$v); }
function sanitize_key($v): string { return is_string($v)?preg_replace('/[^a-z0-9_\-]/','',strtolower($v)):''; }

require_once dirname(__DIR__).'/includes/Video_Post_Type.php';
require_once dirname(__DIR__).'/includes/Backend_Identity.php';
require_once dirname(__DIR__).'/includes/Backend_Registry.php';
require_once dirname(__DIR__).'/includes/Video_Destination.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Plan.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Thumbnail.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Manifest.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Execution.php';
require_once dirname(__DIR__).'/includes/Video_Meta.php';
require_once dirname(__DIR__).'/includes/Storage.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Staging_Service.php';

use ArgentVideo\Storage;
use ArgentVideo\Video_Meta;
use ArgentVideo\PeerTube_Publication_Staging_Service;

$assert=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
$cleanup=static function(string $root) use (&$cleanup): void { if(!is_dir($root))return; foreach(scandir($root)?:array() as $n){if('.'===$n||'..'===$n)continue;$p=$root.'/'.$n;if(is_dir($p)&&!is_link($p))$cleanup($p);else @unlink($p);} @rmdir($root); };

$outside=sys_get_temp_dir().'/awvp-source-'.bin2hex(random_bytes(4)).'.mp4'; file_put_contents($outside,"ftyp\0synthetic-mp4-data\n");
$GLOBALS['awvp_stage_meta'][100][Video_Meta::ATTACHMENT_ID]=20;
$GLOBALS['awvp_stage_mime'][20]='video/mp4';
$GLOBALS['awvp_stage_files'][20]=$outside;
$service=new PeerTube_Publication_Staging_Service();
$first=$service->stage(100);
$assert('ready'===$first['status'],'Original MP4 did not stage.');
$assert(20===$first['attachment_id']&&Storage::is_managed_path($first['path']),'Staged source did not land inside managed storage.');
$assert(hash_file('sha256',$outside)===hash_file('sha256',$first['path']),'Staged bytes changed.');
$second=$service->stage(100);
$assert($first===$second,'Exact staging replay did not reuse the stable managed source.');

// A managed transcoded MP4 wins over the original attachment.
$managed=Storage::ensure_attachment_directory(20).'/preferred.mp4'; file_put_contents($managed,"preferred-mp4\n");
$GLOBALS['awvp_stage_meta'][20]['_argent_video_outputs']=array('mp4'=>array('path'=>$managed));
$preferred=$service->stage(100);
$assert('ready'===$preferred['status']&&$managed===$preferred['path'],'Managed MP4 derivative was not preferred.');

// Unsupported original formats cannot be copied merely because a filename exists.
unset($GLOBALS['awvp_stage_meta'][20]['_argent_video_outputs']);
$GLOBALS['awvp_stage_mime'][20]='video/webm';
$assert('refused'===($service->stage(100)['status']??''),'Non-MP4 original was accepted for publication staging.');
$GLOBALS['awvp_stage_mime'][20]='video/mp4';

// Symlinked originals fail closed.
$link=sys_get_temp_dir().'/awvp-source-link-'.bin2hex(random_bytes(4)).'.mp4';
if (@symlink($outside,$link)) {
    $GLOBALS['awvp_stage_files'][20]=$link;
    $assert('refused'===($service->stage(100)['status']??''),'Symlinked source was accepted.');
    @unlink($link);
}
$GLOBALS['awvp_stage_files'][20]=$outside;

// A managed-output path that resolves through a symlink is not accepted; fallback
// to the ordinary original remains safe and deterministic.
$badDir=Storage::ensure_attachment_directory(20).'/badlink';
if (@symlink(dirname($outside),$badDir)) {
    $GLOBALS['awvp_stage_meta'][20]['_argent_video_outputs']=array('mp4'=>array('path'=>$badDir.'/'.basename($outside)));
    $fallback=$service->stage(100);
    $assert('ready'===$fallback['status']&&$fallback['path']!==$badDir.'/'.basename($outside),'Managed symlink traversal was accepted.');
    @unlink($badDir);
}

$cleanup($GLOBALS['awvp_stage_uploads']); @unlink($outside);
fwrite(STDOUT,"R46.5b publication staging tests passed.\n");
