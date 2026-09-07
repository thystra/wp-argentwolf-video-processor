<?php
declare(strict_types=1);
function absint(mixed $v):int{return abs((int)$v);} function sanitize_key(mixed $v):string{return strtolower((string)$v);} function wp_json_encode(mixed $v,int $flags=0):string|false{return json_encode($v,$flags);}
require_once dirname(__DIR__).'/includes/Backend_Identity.php';
require_once dirname(__DIR__).'/includes/Backend_Registry.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Plan.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Lifecycle.php';
require_once dirname(__DIR__).'/includes/PeerTube_Origin.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Catalog.php';
require_once dirname(__DIR__).'/includes/Video_Destination.php';
require_once dirname(__DIR__).'/includes/Video_Publishing_Defaults.php';
require_once dirname(__DIR__).'/includes/PeerTube_Publication_Manifest.php';
use ArgentVideo\PeerTube_Publication_Plan as Plan; use ArgentVideo\PeerTube_Publication_Manifest as Manifest; use ArgentVideo\Video_Publishing_Defaults;
$a=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
$plan=array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41','title'=>'Reviewed title','description_markdown'=>'Description','tags'=>array('alpha','beta'),'support'=>array('mode'=>'preset','preset_id'=>'help','markdown'=>''),'final_privacy_id'=>'1','licence_id'=>'2','category_id'=>'3','language'=>'en','thumbnail_attachment_id'=>0,'download_enabled'=>true,'originally_published_at'=>'','comments_policy'=>'approval_required','moderation'=>array('reviewed'=>true,'sensitive'=>true,'reason'=>'Content warning','violent'=>true,'sexually_explicit'=>false),'embed'=>array('restricted'=>false,'domains'=>array()),'review'=>array('title'=>true,'channel'=>true,'tags'=>true,'privacy'=>true,'moderation'=>true),'dispatch_policy'=>Plan::DISPATCH_SEND_NOW,'release_policy'=>Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,'anchor_post_id'=>10);
$catalog=array('version'=>1,'backend_id'=>'pt-primary','origin'=>'https://video.example.org','secret_generation'=>7,'server_version'=>'8.3.0','refreshed_at'=>1000,'stale'=>false,'stale_since'=>null,'stale_reason'=>'','channels'=>array(array('id'=>'41','name'=>'main','display_name'=>'Main','authority'=>'owned')),'privacies'=>array('1'=>'Public','2'=>'Unlisted','3'=>'Private','4'=>'Internal','5'=>'Password'),'licences'=>array('2'=>'CC BY'),'categories'=>array('3'=>'People'),'languages'=>array('en'=>'English'),'capabilities'=>array('sensitive_content'=>true,'sensitive_flags'=>true,'password_privacy'=>true));
$settings=Video_Publishing_Defaults::defaults();$settings['support_presets']['help']=array('label'=>'Support','markdown'=>'Please support us.');
$m=Manifest::build($plan,$catalog,$settings);$a([]!==$m,'Reviewed plan did not freeze into a manifest.');$a('Please support us.'===$m['support_markdown'],'Support preset was not frozen.');$a(64===strlen(Manifest::sha256($m)),'Manifest hash invalid.');
$bad=$plan;$bad['final_privacy_id']='5';$a([]===Manifest::build($bad,$catalog,$settings),'Password privacy was authorable without password lifecycle.');
$bad=$plan;$bad['embed']=array('restricted'=>true,'domains'=>array('example.test'));$a([]===Manifest::build($bad,$catalog,$settings),'Unsupported embed restriction was silently accepted.');
$badcat=$catalog;$badcat['stale']=true;$badcat['stale_since']=1001;$badcat['stale_reason']='remote_failed';$a([]===Manifest::build($plan,$badcat,$settings),'Stale catalog authorized a manifest.');
$bad=$plan;$bad['channel_id']='42';$a([]===Manifest::build($bad,$catalog,$settings),'Unowned channel authorized a manifest.');
$bad=$plan;$bad['support']['preset_id']='missing';$a([]===Manifest::build($bad,$catalog,$settings),'Missing support preset authorized a manifest.');
fwrite(STDOUT,"R46.5b publication manifest tests passed.\n");
