<?php
/** Focused dependency-free R46.3c publication editor tests. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Backend_Identity { public static function sanitize(mixed $v): string { return is_string($v)&&1===preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/D',$v)?$v:''; } }
    final class Backend_Registry {
        public const LOCAL_ID='local'; public const PEERTUBE_TYPE='peertube';
        public array $items=array(); public function get(string $id): ?array { return $this->items[$id]??null; }
    }
    final class Video_Post_Type { public const POST_TYPE='argent_video'; }
    final class Video_Meta {
        public const ATTACHMENT_ID='_argent_video_attachment_id'; public const ORIGIN_POST_ID='_argent_video_origin_post_id';
        public const DESTINATION='_argent_video_destination'; public const PEERTUBE_PUBLICATION_PLAN='_argent_video_peertube_publication_plan';
        public static function sanitize_positive_id(mixed $v): int { return is_int($v)&&$v>0?$v:(is_string($v)&&1===preg_match('/^[1-9][0-9]*$/D',$v)?(int)$v:0); }
    }
    final class PeerTube_Origin { public static function sanitize(mixed $v): string { return is_string($v)&&str_starts_with($v,'https://')?rtrim($v,'/'):''; } }
    final class Video_Publishing_Defaults_Store { public ?array $value=null; public function get(): ?array { return $this->value; } }
    final class PeerTube_Publication_Catalog_Store { public ?array $value=null; public function get(string $id): ?array { return $this->value; } }
    final class Video_Publishing_Defaults {
        public static function effective_for_backend(array $settings,array $backend): array { unset($backend); return $settings['effective']??array(); }
    }
}

namespace {
    $GLOBALS['awvp_pub_posts']=array(); $GLOBALS['awvp_pub_meta']=array();
    function __(string $s,string $d=''): string { unset($d); return $s; }
    function get_post(int $id): mixed { return $GLOBALS['awvp_pub_posts'][$id]??null; }
    function get_post_meta(int $id,string $key,bool $single=true): mixed { unset($single); return $GLOBALS['awvp_pub_meta'][$id][$key]??''; }
    function metadata_exists(string $type,int $id,string $key): bool { unset($type); return array_key_exists($key,$GLOBALS['awvp_pub_meta'][$id]??array()); }
    function update_post_meta(int $id,string $key,mixed $value): bool { $GLOBALS['awvp_pub_meta'][$id][$key]=$value; return true; }

    require_once dirname(__DIR__).'/includes/Video_Destination.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Publication_Plan.php';
    require_once dirname(__DIR__).'/includes/PeerTube_Publication_Editor_Service.php';

    use ArgentVideo\Backend_Registry; use ArgentVideo\PeerTube_Publication_Catalog_Store;
    use ArgentVideo\PeerTube_Publication_Editor_Service; use ArgentVideo\PeerTube_Publication_Plan;
    use ArgentVideo\Video_Meta; use ArgentVideo\Video_Publishing_Defaults_Store;

    $assert=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: $m\n");exit(1);}};
    $registry=new Backend_Registry();
    $registry->items['pt-primary']=array('id'=>'pt-primary','type'=>'peertube','label'=>'Home PeerTube','state'=>'active','default_destination'=>'41','config'=>array('origin'=>'https://video.example.org'));
    $defaults=new Video_Publishing_Defaults_Store();
    $defaults->value=array('support_presets'=>array('support-us'=>array('label'=>'Support us','markdown'=>'Help us')), 'effective'=>array(
        'channel_id'=>'41','final_privacy_id'=>'1','licence_id'=>'2','category_id'=>'15','language'=>'en','comments_policy'=>'enabled','download_enabled'=>true,
        'support'=>array('mode'=>'preset','preset_id'=>'support-us','markdown'=>'Help us'),'dispatch_policy'=>PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,
        'moderation'=>array('sensitive'=>false,'reason'=>'','violent'=>false,'sexually_explicit'=>false),
    ));
    $catalogs=new PeerTube_Publication_Catalog_Store();
    $catalogs->value=array(
        'origin'=>'https://video.example.org','server_version'=>'8.2.0','refreshed_at'=>2000000000,'stale'=>false,'stale_reason'=>'',
        'channels'=>array(array('id'=>'41','name'=>'main','display_name'=>'Main','authority'=>'owned'),array('id'=>'42','name'=>'other','display_name'=>'Other','authority'=>'owned')),
        'privacies'=>array('1'=>'Public','2'=>'Unlisted','3'=>'Private','4'=>'Internal','5'=>'Password protected'),
        'licences'=>array('2'=>'Attribution'),'categories'=>array('15'=>'Science'),'languages'=>array('_unknown'=>'Unknown','en'=>'English'),
        'capabilities'=>array('sensitive_content'=>true,'sensitive_flags'=>true,'password_privacy'=>true),
    );
    $GLOBALS['awvp_pub_posts'][100]=(object)array('ID'=>100,'post_type'=>'argent_video','post_title'=>'Video');
    $GLOBALS['awvp_pub_posts'][20]=(object)array('ID'=>20,'post_type'=>'attachment','post_mime_type'=>'video/mp4','post_title'=>'Farm Tour','post_content'=>'Description');
    $GLOBALS['awvp_pub_posts'][10]=(object)array('ID'=>10,'post_type'=>'post','post_title'=>'Origin');
    $GLOBALS['awvp_pub_posts'][30]=(object)array('ID'=>30,'post_type'=>'attachment','post_mime_type'=>'image/jpeg','post_title'=>'Cover');
    $GLOBALS['awvp_pub_meta'][100]=array(Video_Meta::ATTACHMENT_ID=>20,Video_Meta::ORIGIN_POST_ID=>10,Video_Meta::DESTINATION=>array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41'));
    $service=new PeerTube_Publication_Editor_Service($registry,$defaults,$catalogs);
    $state=$service->editor_state(100);
    $assert(is_array($state)&&'absent'===$state['plan_status'],'Absent plan state failed.');
    $assert(false===$state['draft']['review']['tags'],'Defaults must not satisfy tag review.');
    $assert(array('5')===array_column($state['choices']['unsupported_privacies'],'id'),'Password privacy must stay visible but unselectable.');
    $assert(array('en')===array_column($state['choices']['languages'],'id'),'_unknown language must not become an authored provider ID.');

    $plan=$state['draft']; $plan['channel_id']='42'; $plan['tags']=array(); $plan['thumbnail_attachment_id']=30;
    foreach (array('title','channel','tags','privacy','moderation') as $f) $plan['review'][$f]=true;
    $plan['moderation']['reviewed']=true;
    $result=$service->save(100,$plan,false);
    $assert(PeerTube_Publication_Editor_Service::APPLIED===$result['status'],'Valid reviewed plan was not saved.');
    $saved=$GLOBALS['awvp_pub_meta'][100][Video_Meta::PEERTUBE_PUBLICATION_PLAN];
    $assert('42'===$saved['channel_id']&&array()===$saved['tags'],'Reviewed zero-tag plan/channel were not preserved.');
    $assert('42'===$GLOBALS['awvp_pub_meta'][100][Video_Meta::DESTINATION]['channel_id'],'Channel selection did not freeze into destination.');
    $state=$service->editor_state(100); $assert(true===$state['ready_for_dispatch'],'Fully reviewed stored plan not reported ready.');

    // A concrete destination-channel drift must never inherit the old channel
    // review bit. The stored plan remains intact, but the editable draft requires
    // an explicit channel review before it can be considered dispatch-ready.
    $GLOBALS['awvp_pub_meta'][100][Video_Meta::DESTINATION]=array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41');
    $mismatch=$service->editor_state(100);
    $assert(PeerTube_Publication_Editor_Service::PLAN_CHANNEL_MISMATCH===$mismatch['plan_status'],'Destination/plan channel drift was not surfaced.');
    $assert(false===$mismatch['draft']['review']['channel'],'Channel mismatch retained a stale channel-review confirmation.');
    $assert(true===$mismatch['plan']['review']['channel'],'Read-only stored plan was mutated while projecting a mismatch draft.');
    $GLOBALS['awvp_pub_meta'][100][Video_Meta::DESTINATION]=array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'42');

    $bad=$plan; $bad['final_privacy_id']='5';
    $assert(PeerTube_Publication_Editor_Service::REFUSED===$service->save(100,$bad,false)['status'],'Password privacy was authorable without a password lifecycle.');
    $catalogs->value['stale']=true; $catalogs->value['stale_reason']='remote_failed';
    $assert(true===$service->editor_state(100)['catalog']['usable'],'Same-context last-known-good stale catalog should remain editable.');
    $catalogs->value['stale_reason']='backend_context_changed';
    $assert(false===$service->editor_state(100)['catalog']['usable'],'Backend-context-changed catalog must not authorize authoring choices.');
    $assert(PeerTube_Publication_Editor_Service::REFUSED===$service->save(100,$plan,false)['status'],'Context-changed catalog accepted a save.');

    $source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Publication_Editor_Service.php');
    foreach(array('wp_remote_','PeerTube_Api_Client','PeerTube_Task','wp_schedule_','transition_post_status','wp_publish_post') as $forbidden){$assert(!str_contains($source,$forbidden),'Publication editor acquired forbidden authority: '.$forbidden);}
    fwrite(STDOUT,"R46 PeerTube publication editor tests passed.\n");
}
