<?php
/** Dependency-free RC9 PeerTube-first/player/cleanup policy tests. */
declare(strict_types=1);

namespace ArgentVideo {
    $GLOBALS['awvp_rc9_cleanup_meta']=array(20=>array('_argent_video_outputs'=>array('mp4'=>array('path'=>'managed.mp4'))),101=>array('_attachment'=>20));
    $GLOBALS['awvp_rc9_cleanup_dirs']=array('/managed/20'=>true);
    interface Video_Serving_Resolver { public function peertube_embed_url(int $video_id):string; }
    final class Video_Meta { public const ATTACHMENT_ID='_attachment'; public static function sanitize_positive_id(mixed $v):int{return is_int($v)&&$v>0?$v:0;} }
    final class Storage {
        public static function attachment_directory(int $id):string{return '/managed/'.$id;}
        public static function remove_tree(string $dir):void{unset($GLOBALS['awvp_rc9_cleanup_dirs'][$dir]);}
    }
    function get_post_meta(int $id,string $key,bool $single=true):mixed{unset($single);return $GLOBALS['awvp_rc9_cleanup_meta'][$id][$key]??'';}
    function metadata_exists(string $type,int $id,string $key):bool{unset($type);return array_key_exists($key,$GLOBALS['awvp_rc9_cleanup_meta'][$id]??array());}
    function delete_post_meta(int $id,string $key):bool{unset($GLOBALS['awvp_rc9_cleanup_meta'][$id][$key]);return true;}
    function is_dir(string $path):bool{return isset($GLOBALS['awvp_rc9_cleanup_dirs'][$path]);}
}

namespace {
    require_once dirname(__DIR__).'/includes/PeerTube_Derivative_Cleanup_Service.php';
    use ArgentVideo\PeerTube_Derivative_Cleanup_Service; use ArgentVideo\Video_Serving_Resolver;
    final class Rc9Serving implements Video_Serving_Resolver { public function __construct(private string $url){} public function peertube_embed_url(int $video_id):string{return 101===$video_id?$this->url:'';} }
    $assert=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
    $refused=(new PeerTube_Derivative_Cleanup_Service(new Rc9Serving('')))->cleanup(101);
    $assert(PeerTube_Derivative_Cleanup_Service::REFUSED===$refused&&isset($GLOBALS['awvp_rc9_cleanup_dirs']['/managed/20']),'Derivative cleanup ran before verified remote serving.');
    $done=(new PeerTube_Derivative_Cleanup_Service(new Rc9Serving('https://video.example.org/embed/uuid')))->cleanup(101);
    $assert(PeerTube_Derivative_Cleanup_Service::APPLIED===$done&&!isset($GLOBALS['awvp_rc9_cleanup_dirs']['/managed/20'])&&!isset($GLOBALS['awvp_rc9_cleanup_meta'][20]['_argent_video_outputs']),'Verified cutover did not remove only AWVP managed derivative state.');

    $root=dirname(__DIR__);
    $block=(string)file_get_contents($root.'/includes/Video_Block.php');
    $cleanup=(string)file_get_contents($root.'/includes/PeerTube_Derivative_Cleanup_Service.php');
    $staging=(string)file_get_contents($root.'/includes/PeerTube_Publication_Staging_Service.php');
    $retention=(string)file_get_contents($root.'/includes/Local_Retention_Service.php');
    $assert(strpos($block,'peertube_embed_url($video_id)')<strpos($block,'wp_get_attachment_url($attachment_id)'),'AWVP block does not resolve verified PeerTube serving before local source readability.');
    $assert(str_contains($cleanup,"'_argent_video_outputs'")&&str_contains($cleanup,'Storage::remove_tree')&&!str_contains($cleanup,'WordPress_Source_File::delete'),'Post-cutover derivative cleanup acquired WordPress source deletion authority.');
    $assert(str_contains($staging,'WordPress_Source_File::capture')&&!str_contains($staging,"'_argent_video_outputs'"),'PeerTube publication staging is not original-source authoritative.');
    $assert(str_contains($retention,'attachment_local_processing_blocked')&&str_contains($retention,'!Video_Destination::is_local'),'PeerTube destination does not fence redundant local FFmpeg processing.');

    fwrite(STDOUT,"RC9 PeerTube-first/player/cleanup policy tests passed.\n");
}
