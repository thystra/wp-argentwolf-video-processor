<?php
/** R46.9 confined physical WordPress source deletion tests. */
declare(strict_types=1);
namespace ArgentVideo {
    $root=sys_get_temp_dir().'/awvp-r469-source-'.bin2hex(random_bytes(4));mkdir($root.'/uploads/2026/09',0777,true);file_put_contents($root.'/uploads/2026/09/video.mp4','video-bytes');
    $GLOBALS['r469_root']=$root;$GLOBALS['r469_path']=$root.'/uploads/2026/09/video.mp4';$GLOBALS['r469_post']=(object)array('ID'=>20,'post_type'=>'attachment');
    function get_post(int $id):mixed{return 20===$id?$GLOBALS['r469_post']:null;}
    function get_post_mime_type(int $id):string{return 20===$id?'video/mp4':'';}
    function get_attached_file(int $id,bool $unfiltered=false):string|false{unset($unfiltered);return 20===$id?$GLOBALS['r469_path']:false;}
    function wp_upload_dir():array{return array('basedir'=>$GLOBALS['r469_root'].'/uploads','baseurl'=>'https://example.test/uploads','error'=>false);}
    function wp_mkdir_p(string $p):bool{return is_dir($p)||mkdir($p,0777,true);}
    function trailingslashit(string $v):string{return rtrim($v,'/\\').'/';}
    function wp_normalize_path(string $p):string{return str_replace('\\','/',$p);}
    function wp_delete_file(string $p):void{@unlink($p);}
}
namespace {
require_once dirname(__DIR__).'/includes/Storage.php';require_once dirname(__DIR__).'/includes/WordPress_Source_File.php';use ArgentVideo\WordPress_Source_File as S;use ArgentVideo\Storage;
$f=0;$a=function(bool $v,string $m)use(&$f){if(!$v){fwrite(STDERR,"FAIL: $m\n");$f++;}};
$id=S::capture(20);$a('2026/09/video.mp4'===($id['relative_path']??'')&&isset($id['ctime']),'Relative uploads/stat identity mismatch.');$a(S::matches(20,$id),'Exact source identity should match before deletion.');
$sha=S::sha256(20,$id);$a(hash_file('sha256',$GLOBALS['r469_path'])===$sha,'Confined source hash did not bind the exact attachment object.');
$copy_dir=Storage::ensure_attachment_directory(20).'/staging';Storage::make_directory($copy_dir);$copy=$copy_dir.'/copy.tmp';$a(S::copy_to_managed(20,$id,$copy),'Confined source did not copy into managed uploads storage.');$a(hash_file('sha256',$copy)===$sha,'Managed staging copy changed source bytes.');Storage::delete_file($copy);
$bad=$id;$bad['inode']++;$a(!S::delete(20,$bad)&&is_file($GLOBALS['r469_path']),'Changed identity must not delete source.');
$bad=$id;$bad['ctime']++;$a(!S::delete(20,$bad)&&is_file($GLOBALS['r469_path']),'Changed ctime identity must not delete source.');
$a(S::delete(20,$id),'Exact confined source deletion should verify absence.');$a(!file_exists($GLOBALS['r469_path']),'Source still exists after verified delete.');$a(is_object(ArgentVideo\get_post(20)),'Physical cleanup must not delete WordPress attachment object.');$a(S::absent(20,$id),'Running-journal absence confirmation should accept exact missing path.');
file_put_contents($GLOBALS['r469_root'].'/outside.mp4','outside');$GLOBALS['r469_path']=$GLOBALS['r469_root'].'/outside.mp4';$a(array()===S::capture(20),'Source outside uploads must fail closed.');
@unlink($GLOBALS['r469_root'].'/outside.mp4');Storage::remove_tree(Storage::attachment_directory(20));@rmdir($GLOBALS['r469_root'].'/uploads/2026/09');@rmdir($GLOBALS['r469_root'].'/uploads/2026');@rmdir($GLOBALS['r469_root'].'/uploads');@rmdir($GLOBALS['r469_root']);
if($f>0)exit(1);echo "R46.9 WordPress source-file boundary tests passed.\n";
}
