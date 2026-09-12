<?php
/** Dependency-free RC13.2 verified local-HLS delivery evidence tests. */
declare(strict_types=1);
namespace ArgentVideo {
$GLOBALS['awvp_local_delivery_meta']=array();
function get_post_meta(int $id,string $key,bool $single=true):mixed{unset($single);return $GLOBALS['awvp_local_delivery_meta'][$id][$key]??'';}
function wp_json_encode(mixed $v,int $flags=0):string|false{return json_encode($v,$flags);}
function wp_normalize_path(string $path):string{return str_replace('\\','/',$path);}
final class Storage{
    public static string $root='';
    public static function root():string{return self::$root;}
    public static function assert_managed_path(string $path):string{$root=rtrim(self::$root,'/');$path=rtrim(wp_normalize_path($path),'/');if(''===$root||($path!==$root&&!str_starts_with($path,$root.'/')))throw new \RuntimeException('outside');return $path;}
    public static function url_for_path(string $path):string{$path=self::assert_managed_path($path);return 'https://example.test/uploads/argentwolf-video-processor/'.substr($path,strlen(rtrim(self::$root,'/'))+1);}
}
final class Adaptive_HLS{
    public static function validate_media_playlist(string $playlist):void{$c=is_file($playlist)?file_get_contents($playlist):false;if(!is_string($c)||!str_contains($c,'#EXTM3U')||!str_contains($c,'#EXT-X-ENDLIST')||!str_contains($c,'#EXT-X-MAP')||!str_contains($c,'.m4s'))throw new \RuntimeException('invalid');}
}
}
namespace {
require_once dirname(__DIR__).'/includes/Local_Delivery_Evidence.php';
use ArgentVideo\Local_Delivery_Evidence as E;use ArgentVideo\Storage;
$f=0;$a=function(bool $ok,string $m)use(&$f){if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");++$f;}};
$root=sys_get_temp_dir().'/awvp-local-delivery-'.bin2hex(random_bytes(4));$hls=$root.'/20/hls';$r=$hls.'/360p';mkdir($r,0777,true);Storage::$root=$root;
file_put_contents($hls.'/master.m3u8',"#EXTM3U\n#EXT-X-VERSION:7\n#EXT-X-STREAM-INF:BANDWIDTH=1000000\n360p/index.m3u8\n");
file_put_contents($r.'/index.m3u8',"#EXTM3U\n#EXT-X-MAP:URI=\"init.mp4\"\n#EXTINF:6.0,\nseg000.m4s\n#EXT-X-ENDLIST\n");
file_put_contents($r.'/init.mp4','init');file_put_contents($r.'/seg000.m4s','segment');
$master=$hls.'/master.m3u8';$playlist=$r.'/index.m3u8';
$GLOBALS['awvp_local_delivery_meta'][20]['_argent_video_outputs']=array('hls'=>array(
    'path'=>$master,'directory'=>$hls,'url'=>Storage::url_for_path($master),'mime'=>'application/vnd.apple.mpegurl',
    'renditions'=>array(array('label'=>'360p','path'=>$playlist,'url'=>Storage::url_for_path($playlist)))
));
$e=E::capture(20);$a(array()!==$e&&20===($e['attachment_id']??0)&&4===($e['file_count']??0)&&64===strlen((string)($e['tree_sha256']??'')),'Valid managed HLS delivery was not positively captured.');
$a(E::matches(20,$e),'Freshly captured HLS delivery did not match itself.');
$a(Storage::url_for_path($master)===E::available_hls_url(20),'Lightweight HLS availability did not expose the managed master URL.');
file_put_contents($r.'/seg000.m4s','segment-changed');clearstatcache(true,$r.'/seg000.m4s');$a(!E::matches(20,$e),'Changed HLS media segment did not invalidate retained delivery evidence.');
$GLOBALS['awvp_local_delivery_meta'][20]['_argent_video_outputs']['hls']['path']='/tmp/outside/master.m3u8';$a(array()===E::capture(20),'Out-of-bound HLS path was accepted as managed delivery evidence.');
$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::CHILD_FIRST);foreach($it as $item){$item->isDir()?rmdir($item->getPathname()):unlink($item->getPathname());}rmdir($root);
if($f>0)exit(1);echo "RC13.2 local delivery evidence tests passed.\n";
}
