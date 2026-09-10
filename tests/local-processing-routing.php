<?php
/** Dependency-free RC10 local-vs-PeerTube routing regressions. */
declare(strict_types=1);

namespace ArgentVideo {
    final class Job_Repository
    {
        public int $next_id = 1;
        /** @var list<int> */
        public array $enqueued = array();
        public function find_by_attachment(int $attachment_id): ?array { unset($attachment_id); return null; }
        public function enqueue(int $attachment_id,string $source_path,string $signature,string $profile,bool $force=false): int
        {
            unset($source_path,$signature,$profile,$force);
            $this->enqueued[]=$attachment_id;
            return $this->next_id++;
        }
        public function delete_by_attachment(int $attachment_id): void { unset($attachment_id); }
    }

    final class Video_Publishing_Defaults_Store
    {
        /** @var array<string,mixed>|null */
        public ?array $settings;
        /** @param array<string,mixed>|null $settings */
        public function __construct(?array $settings) { $this->settings=$settings; }
        /** @return array<string,mixed>|null */
        public function get(): ?array { return $this->settings; }
    }

    final class Video_Block_Editor_Service { public const ATTACHMENT_ASSET_META='_argent_video_asset_id'; }
    final class Video_Meta
    {
        public const DESTINATION='_argent_video_destination';
        public static function sanitize_positive_id(mixed $value): int
        {
            return is_int($value) && $value>0 ? $value : (is_string($value)&&preg_match('/^[1-9][0-9]*$/D',$value)?(int)$value:0);
        }
    }
    final class Video_Destination
    {
        /** @return array{version:int,backend_id:string} */
        public static function local(): array { return array('version'=>1,'backend_id'=>'local'); }
        /** @return array<string,mixed> */
        public static function sanitize(mixed $value): array
        {
            if(!is_array($value)||1!==($value['version']??null)||!is_string($value['backend_id']??null)||''===$value['backend_id'])return array();
            return $value;
        }
        /** @return array<string,mixed> */
        public static function resolve(mixed $value,bool $exists): array { return $exists?self::sanitize($value):self::local(); }
        /** @param array<string,mixed> $destination */
        public static function is_local(array $destination): bool { return 'local'===($destination['backend_id']??''); }
    }
    final class Settings
    {
        public static function get(string $key,mixed $default=null): mixed { unset($key); return $default; }
        public static function current_job_profile(): string { return 'standard'; }
    }

    $GLOBALS['awvp_route_mime']=array(10=>'video/mp4');
    $GLOBALS['awvp_route_meta']=array();
    function get_post_mime_type(int $id): string { return $GLOBALS['awvp_route_mime'][$id]??''; }
    function get_post_type(int $id): string { return isset($GLOBALS['awvp_route_mime'][$id])?'attachment':''; }
    function metadata_exists(string $type,int $id,string $key): bool { unset($type); return array_key_exists($key,$GLOBALS['awvp_route_meta'][$id]??array()); }
    function get_post_meta(int $id,string $key,bool $single=true): mixed { unset($single); return $GLOBALS['awvp_route_meta'][$id][$key]??''; }
    function update_post_meta(int $id,string $key,mixed $value): bool { $GLOBALS['awvp_route_meta'][$id][$key]=$value; return true; }
    function delete_post_meta(int $id,string $key): bool { unset($GLOBALS['awvp_route_meta'][$id][$key]); return true; }
    function get_attached_file(int $id,bool $unfiltered=false): string { unset($id,$unfiltered); return __FILE__; }
    function wp_normalize_path(string $path): string { return $path; }
}

namespace {
    require_once dirname(__DIR__).'/includes/Queue.php';
    use ArgentVideo\Job_Repository;
    use ArgentVideo\Queue;
    use ArgentVideo\Video_Block_Editor_Service;
    use ArgentVideo\Video_Meta;
    use ArgentVideo\Video_Publishing_Defaults_Store;

    $assert=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}};
    $remote=array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41');
    $local=array('version'=>1,'backend_id'=>'local');

    $jobs=new Job_Repository();
    $defaults=new Video_Publishing_Defaults_Store(array('default_destination'=>$remote));
    $queue=new Queue($jobs,$defaults);
    $assert(false===$queue->local_processing_allowed(10),'Unbound attachment ignored remote site-default routing.');
    $queue->maybe_enqueue_attachment(10);
    $assert(array()===$jobs->enqueued,'Remote-default attachment created a local FFmpeg row from add_attachment.');

    $defaults->settings=array('default_destination'=>$local);
    $assert(true===$queue->local_processing_allowed(10),'Unbound local-default attachment was blocked from local processing.');
    $queue->maybe_enqueue_attachment(10);
    $assert(array(10)===$jobs->enqueued,'Local-default attachment was not auto-queued for FFmpeg.');

    $GLOBALS['awvp_route_meta'][10][Video_Block_Editor_Service::ATTACHMENT_ASSET_META]=100;
    $GLOBALS['awvp_route_meta'][100][Video_Meta::DESTINATION]=$remote;
    $assert(false===$queue->local_processing_allowed(10),'Concrete PeerTube video destination did not override local site default.');
    try {
        $queue->enqueue(10,true);
        $assert(false,'Manual/forced local enqueue bypassed a concrete PeerTube destination.');
    } catch (RuntimeException $error) {
        $assert(str_contains($error->getMessage(),'selected final destination'),'Remote routing refusal did not explain the local-processing boundary.');
    }
    $assert(array(10)===$jobs->enqueued,'Remote-bound manual enqueue mutated the local queue before refusing.');

    $defaults->settings=array('default_destination'=>$remote);
    $GLOBALS['awvp_route_meta'][100][Video_Meta::DESTINATION]=$local;
    $assert(true===$queue->local_processing_allowed(10),'Concrete local video destination did not override remote site default.');

    $bulk=(string)file_get_contents(dirname(__DIR__).'/includes/Bulk_Queue.php');
    $sync=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Publication_Synchronizer.php');
    $worker=(string)file_get_contents(dirname(__DIR__).'/includes/Worker.php');
    $jobs_source=(string)file_get_contents(dirname(__DIR__).'/includes/Job_Repository.php');
    $editor=(string)file_get_contents(dirname(__DIR__).'/includes/Video_Block_Editor_Service.php');
    $assert(substr_count($bulk,'local_processing_allowed($attachment_id)')>=2,'Bulk/smart backlog does not consistently exclude remote-routed media.');
    $assert(str_contains($sync,'discard_unstarted($attachment_id)'),'Publication routing still converts an unstarted local job into a normal cancellation.');
    $assert(str_contains($editor,'discard_unstarted_local_job'),'Destination selection does not immediately remove an unstarted local routing-race row.');
    $assert(str_contains($worker,'discard_claimed($job_id, $job_lock)')&&str_contains($worker,'local_processing_allowed($attachment_id)'),'Claim-time routing race can still reach FFmpeg after destination changes to PeerTube.');
    $assert(str_contains($jobs_source,"status = 'queued' AND started_at IS NULL")&&str_contains($jobs_source,"status = 'processing' AND lock_token = %s"),'Job repository lacks lock-fenced non-cancellation discard boundaries for routing races.');

    fwrite(STDOUT,"RC10 local/PeerTube routing tests passed.\n");
}
