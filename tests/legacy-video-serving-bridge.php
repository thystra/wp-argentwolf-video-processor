<?php
/** Focused dependency-free tests for RC10 legacy runtime serving bridge. */
declare(strict_types=1);

$GLOBALS['awvp_bridge_posts']=array();
$GLOBALS['awvp_bridge_meta']=array();
$GLOBALS['awvp_bridge_current_post']=10;
$GLOBALS['awvp_bridge_url_ids']=array('https://example.test/uploads/legacy.mp4'=>20);
$GLOBALS['awvp_bridge_styles']=array();

function __(string $text,string $domain=''):string{unset($domain);return $text;}
function metadata_exists(string $type,int $id,string $key):bool{unset($type);return array_key_exists($key,$GLOBALS['awvp_bridge_meta'][$id]??array());}
function get_post_meta(int $id,string $key,bool $single=false):mixed{$v=$GLOBALS['awvp_bridge_meta'][$id][$key]??($single?'':array());return $single?$v:array($v);}
function get_post(int $id):object|false{return $GLOBALS['awvp_bridge_posts'][$id]??false;}
function get_the_ID():int{return (int)$GLOBALS['awvp_bridge_current_post'];}
function get_the_title(int $id):string{return (string)(($GLOBALS['awvp_bridge_posts'][$id]->post_title??''));}
function attachment_url_to_postid(string $url):int{return (int)($GLOBALS['awvp_bridge_url_ids'][$url]??0);}
function wp_enqueue_style(string $handle):void{$GLOBALS['awvp_bridge_styles'][]=$handle;}
function esc_url(string $url):string{return htmlspecialchars($url,ENT_QUOTES);}
function esc_attr(string $value):string{return htmlspecialchars($value,ENT_QUOTES);}

require_once dirname(__DIR__).'/includes/Video_Serving_Resolver.php';
require_once dirname(__DIR__).'/includes/Video_Post_Type.php';
require_once dirname(__DIR__).'/includes/Video_Meta.php';
require_once dirname(__DIR__).'/includes/Legacy_Video_Adoption_Service.php';
require_once dirname(__DIR__).'/includes/Legacy_Video_Serving_Bridge.php';

use ArgentVideo\Legacy_Video_Adoption_Service;
use ArgentVideo\Legacy_Video_Serving_Bridge;
use ArgentVideo\Video_Meta;
use ArgentVideo\Video_Post_Type;
use ArgentVideo\Video_Serving_Resolver;

final class Awvp_Legacy_Bridge_Resolver implements Video_Serving_Resolver {
    public string $url='';
    public function peertube_embed_url(int $video_id):string{return 100===$video_id?$this->url:'';}
}
$assert=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}};
$GLOBALS['awvp_bridge_posts'][100]=(object)array('ID'=>100,'post_type'=>Video_Post_Type::POST_TYPE,'post_status'=>'publish','post_title'=>'Adopted Legacy');
$GLOBALS['awvp_bridge_posts'][20]=(object)array('ID'=>20,'post_type'=>'attachment','post_status'=>'inherit','post_title'=>'Legacy attachment');
$GLOBALS['awvp_bridge_meta'][20]=array(Legacy_Video_Adoption_Service::ATTACHMENT_ASSET_META=>100);
$GLOBALS['awvp_bridge_meta'][100]=array(Video_Meta::ATTACHMENT_ID=>20,Video_Meta::ORIGIN_POST_ID=>10);
$resolver=new Awvp_Legacy_Bridge_Resolver();
$bridge=new Legacy_Video_Serving_Bridge($resolver);
$local='<figure class="wp-block-video"><video src="https://example.test/uploads/managed.mp4" controls></video></figure>';
$block=array('blockName'=>'core/video','attrs'=>array('id'=>20));

$assert($local===$bridge->render_block($local,$block),'Legacy block changed before verified serving authority existed.');
$resolver->url='https://video.example.org/videos/embed/5wwQY8261uRv7aPsVWhEiY';
$remote=$bridge->render_block($local,$block);
$assert(str_contains($remote,'<iframe') && str_contains($remote,'video.example.org/videos/embed/5wwQY8261uRv7aPsVWhEiY'),'Verified authority did not bridge legacy core/video to PeerTube.');
$assert(!str_contains($remote,'managed.mp4'),'PeerTube bridge retained local derivative as serving source.');
$assert(!str_contains(strtolower($remote),'autoplay'),'Legacy PeerTube bridge enabled autoplay.');
$assert(in_array(Legacy_Video_Serving_Bridge::STYLE_HANDLE,$GLOBALS['awvp_bridge_styles'],true),'Legacy bridge did not enqueue responsive AWVP block style.');

$GLOBALS['awvp_bridge_current_post']=11;
$assert($local===$bridge->render_block($local,$block),'Reused attachment was rerouted outside its adopted origin post.');
$GLOBALS['awvp_bridge_current_post']=10;
$short_local='<video src="https://example.test/uploads/managed.mp4" controls></video>';
$short_remote=$bridge->render_shortcode($short_local,array('src'=>'https://example.test/uploads/legacy.mp4'));
$assert(str_contains($short_remote,'<iframe') && !str_contains($short_remote,'managed.mp4'),'Supported legacy [video] shortcode did not follow verified serving authority.');

$GLOBALS['awvp_bridge_meta'][100][Video_Meta::ATTACHMENT_ID]=21;
$assert($local===$bridge->render_block($local,$block),'Mismatched reverse/forward binding was allowed to bridge serving.');

$source=(string)file_get_contents(dirname(__DIR__).'/includes/Legacy_Video_Serving_Bridge.php');
foreach(array('update_post_meta','wp_update_post','wp_remote_','PeerTube_Api_Client','Task_Repository') as $forbidden){$assert(!str_contains($source,$forbidden),'Legacy serving bridge acquired mutation/provider authority: '.$forbidden);}
$assert(str_contains($source,'$anchor_post_id !== $current_post_id'),'Legacy serving bridge lost conservative origin-post boundary.');

echo "Legacy video serving bridge tests passed.\n";
