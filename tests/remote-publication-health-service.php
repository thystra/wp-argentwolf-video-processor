<?php
/** Dependency-free periodic remote-publication health ownership tests. */
declare(strict_types=1);

namespace ArgentVideo {
    $GLOBALS['awvp_health_service_meta'] = array();

    function get_post_meta(int $post_id, string $key, bool $single = true): mixed {
        unset($single);
        return $GLOBALS['awvp_health_service_meta'][$post_id][$key] ?? '';
    }

    final class Backend_Identity {
        public static function sanitize(mixed $value): string {
            return is_string($value) && 1 === preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $value) ? $value : '';
        }
    }

    final class Backend_Registry {
        public const LOCAL_ID = 'local';
        /** @param array<string,array<string,mixed>> $rows */
        public function __construct(public array $rows) {}
        public function get_fresh(string $backend_id): ?array { return $this->rows[$backend_id] ?? null; }
    }

    final class Video_Meta { public const SERVING_AUTHORITY = '_authority'; }

    final class Video_Serving_Authority {
        public static function sanitize(mixed $value): array {
            if (! is_array($value)) return array();
            $asset = (int) ($value['remote_asset_id'] ?? 0);
            $backend = Backend_Identity::sanitize($value['backend_id'] ?? null);
            return $asset > 0 && '' !== $backend ? array('remote_asset_id'=>$asset,'backend_id'=>$backend) : array();
        }
    }

    final class Serving_Viability {
        public const HEALTHY='healthy';
        public const PROCESSING='processing';
        public const MISSING='missing';
        public const PROBE_INDETERMINATE='probe_indeterminate';
        private function __construct(private string $status, private string $reason, private string $message, private int $http) {}
        public static function create(string $status, string $reason, string $message, int $http_status = 0): self {
            return new self($status,$reason,$message,$http_status);
        }
        public static function healthy(): self { return new self(self::HEALTHY,'','',200); }
        public function status(): string { return $this->status; }
        public function reason_code(): string { return $this->reason; }
        public function message(): string { return $this->message; }
        public function http_status(): int { return $this->http; }
    }

    final class Remote_Publication_Health_Repository {
        public const APPLIED='applied';
        public const PRESENT='present';
        public const FIRST_FAILURE_RETRY=900;
        /** @param list<array<string,mixed>> $due @param array<int,array<string,mixed>> $rows */
        public function __construct(public array $due, public array $rows = array()) {}
        public int $record_calls=0;
        public function due_publications(int $now, int $limit=20): array { unset($now,$limit); return $this->due; }
        public function find(int $asset_id): ?array { return $this->rows[$asset_id] ?? null; }
        public function record(int $asset_id,int $video_id,string $backend_id,Serving_Viability $observation,int $now,bool $initial_verified=false,int $next_check_override=0): string {
            unset($now,$initial_verified,$next_check_override);
            ++$this->record_calls;
            $before=$this->rows[$asset_id]??array();
            $this->rows[$asset_id]=array(
                'remote_asset_id'=>$asset_id,'video_post_id'=>$video_id,'backend_id'=>$backend_id,
                'status'=>$observation->status(),'eligible'=>0,
                'last_healthy_at'=>$before['last_healthy_at']??null,
            );
            return self::APPLIED;
        }
    }

    final class Awvp_Health_Test_Adapter {
        public int $calls=0;
        public ?Serving_Viability $observation=null;
        public function probe_publication(array $descriptor,array $asset): Serving_Viability {
            unset($descriptor,$asset);
            ++$this->calls;
            return $this->observation ?? Serving_Viability::create(Serving_Viability::MISSING,'provider.missing','Gone',404);
        }
    }


    final class Backend_Processing_Estimator {
        public array $observations=array();
        public function estimate(string $backend_id,int $bytes,int $started,int $now):array {
            unset($backend_id,$bytes,$started,$now);
            return array('seconds'=>300,'estimated_ready_at'=>2300,'confidence'=>'low','basis'=>'size_fallback','sample_count'=>0,'same_bucket_count'=>0);
        }
        public static function next_probe_delay(int $estimated_ready_at,int $now):int { unset($estimated_ready_at,$now); return 60; }
        public function observe(string $backend_id,int $bytes,int $seconds,int $observed_at):bool {
            $this->observations[]=compact('backend_id','bytes','seconds','observed_at');
            return true;
        }
    }

    final class Remote_Health_Notification_Service {
        public int $publication_calls=0;
        public int $backend_calls=0;
        public function publication_observed(array $asset,array $health,int $now,bool $backend_outage=false):void{unset($asset,$health,$now,$backend_outage);++$this->publication_calls;}
        public function backend_observed(string $backend_id,?array $previous,?array $incident,int $now):void{unset($backend_id,$previous,$incident,$now);++$this->backend_calls;}
    }

    final class Serving_Health_Adapter_Factory {
        public function __construct(public Awvp_Health_Test_Adapter $adapter) {}
        public function resolve(string $type): ?Awvp_Health_Test_Adapter { return 'peertube' === $type ? $this->adapter : null; }
    }
}

namespace {
    require_once dirname(__DIR__) . '/includes/Remote_Publication_Health_Service.php';

    use ArgentVideo\Awvp_Health_Test_Adapter;
    use ArgentVideo\Backend_Registry;
    use ArgentVideo\Remote_Publication_Health_Repository;
    use ArgentVideo\Remote_Publication_Health_Service;
    use ArgentVideo\Remote_Health_Notification_Service;
    use ArgentVideo\Serving_Health_Adapter_Factory;
    use ArgentVideo\Video_Meta;

    $assert=static function(bool $ok,string $message):void{if(!$ok){fwrite(STDERR,"FAIL: {$message}\n");exit(1);}};
    $asset=static fn(int $id,int $video=77):array=>array('id'=>$id,'video_post_id'=>$video,'backend_id'=>'pt1','state'=>'ready');
    $registry=new Backend_Registry(array('pt1'=>array('id'=>'pt1','type'=>'peertube','state'=>'active')));

    // A new/private pre-cutover publication has no proof that it has ever
    // served visitors. Even a stale failure row created by an older prerelease
    // must not make periodic monitoring own that initial-publication state.
    $adapter=new Awvp_Health_Test_Adapter();
    $repo=new Remote_Publication_Health_Repository(array($asset(1)),array(
        1=>array('remote_asset_id'=>1,'video_post_id'=>77,'backend_id'=>'pt1','status'=>'private_or_restricted','eligible'=>0,'last_healthy_at'=>null),
    ));
    $service=new Remote_Publication_Health_Service($repo,$registry,new Serving_Health_Adapter_Factory($adapter));
    $assert(0===$service->run(2000),'Pre-cutover publication was incorrectly processed by periodic broken-publication monitoring.');
    $assert(0===$adapter->calls&&0===$repo->record_calls,'Pre-cutover periodic monitor performed a provider probe or health write.');

    // Once public serving has been qualified, last_healthy_at is durable
    // historical evidence. Monitoring continues even after a later failover.
    $adapter2=new Awvp_Health_Test_Adapter();
    $repo2=new Remote_Publication_Health_Repository(array($asset(2)),array(
        2=>array('remote_asset_id'=>2,'video_post_id'=>77,'backend_id'=>'pt1','status'=>'missing','eligible'=>0,'last_healthy_at'=>'2026-09-11 10:00:00'),
    ));
    $service2=new Remote_Publication_Health_Service($repo2,$registry,new Serving_Health_Adapter_Factory($adapter2));
    $assert(1===$service2->run(2000),'Previously qualified publication was not periodically monitored after failure/failover.');
    $assert(1===$adapter2->calls&&1===$repo2->record_calls,'Previously qualified publication did not receive exactly one bounded probe/write.');

    // Upgrade compatibility: an exact durable serving authority is sufficient
    // cutover evidence when an older installation has not yet written a health
    // history row.
    $GLOBALS['awvp_health_service_meta'][88][Video_Meta::SERVING_AUTHORITY]=array('remote_asset_id'=>3,'backend_id'=>'pt1');
    $adapter3=new Awvp_Health_Test_Adapter();
    $repo3=new Remote_Publication_Health_Repository(array($asset(3,88)));
    $service3=new Remote_Publication_Health_Service($repo3,$registry,new Serving_Health_Adapter_Factory($adapter3));
    $assert(1===$service3->run(2000),'Existing serving authority did not bootstrap periodic monitoring after upgrade.');
    $assert(1===$adapter3->calls&&1===$repo3->record_calls,'Serving-authority bootstrap did not perform exactly one health probe/write.');

    // Authority for another asset/backend cannot accidentally qualify this
    // ready publication for periodic outage ownership.
    $GLOBALS['awvp_health_service_meta'][99][Video_Meta::SERVING_AUTHORITY]=array('remote_asset_id'=>999,'backend_id'=>'pt1');
    $adapter4=new Awvp_Health_Test_Adapter();
    $repo4=new Remote_Publication_Health_Repository(array($asset(4,99)));
    $service4=new Remote_Publication_Health_Service($repo4,$registry,new Serving_Health_Adapter_Factory($adapter4));
    $assert(0===$service4->run(2000)&&0===$adapter4->calls,'Mismatched serving authority qualified an unrelated publication for periodic monitoring.');

    // The direct finalizer probe may record pre-cutover diagnostics, but the
    // broken-publication email channel remains silent until the publication has
    // historical public-serving qualification. This keeps the finalizer, not
    // the outage notifier, responsible for first-publication problems.
    $adapter5=new Awvp_Health_Test_Adapter();
    $notify5=new Remote_Health_Notification_Service();
    $repo5=new Remote_Publication_Health_Repository(array());
    $service5=new Remote_Publication_Health_Service($repo5,$registry,new Serving_Health_Adapter_Factory($adapter5),null,null,null,$notify5);
    $direct=$service5->probe_and_record($asset(5,105),2100,true);
    $assert(true===($direct['recorded']??false),'Initial direct health probe did not preserve diagnostic health state.');
    $assert(0===$notify5->publication_calls,'Initial unqualified publication failure entered broken-publication notification ownership.');

    $adapter6=new Awvp_Health_Test_Adapter();
    $notify6=new Remote_Health_Notification_Service();
    $repo6=new Remote_Publication_Health_Repository(array(),array(
        6=>array('remote_asset_id'=>6,'video_post_id'=>106,'backend_id'=>'pt1','status'=>'healthy','eligible'=>1,'last_healthy_at'=>'2026-09-11 10:00:00'),
    ));
    $service6=new Remote_Publication_Health_Service($repo6,$registry,new Serving_Health_Adapter_Factory($adapter6),null,null,null,$notify6);
    $service6->probe_and_record($asset(6,106),2200,false);
    $assert(1===$notify6->publication_calls,'Previously qualified publication failure did not enter notification ownership.');


    // Readiness learning must measure only provider processing time from the
    // durable upload-accepted timestamp to remote ready verification. A later
    // public/finalizer delay must not inflate the recorded sample.
    $adapter7=new Awvp_Health_Test_Adapter();
    $adapter7->observation=\ArgentVideo\Serving_Viability::healthy();
    $repo7=new Remote_Publication_Health_Repository(array());
    $estimator7=new \ArgentVideo\Backend_Processing_Estimator();
    $service7=new Remote_Publication_Health_Service($repo7,$registry,new Serving_Health_Adapter_Factory($adapter7),null,$estimator7);
    $service7->probe_and_record($asset(7,107),3000,true,array(
        'source_bytes'=>123456789,
        'processing_started_at'=>2500,
        'processing_verified_at'=>2800,
    ));
    $assert(array(array('backend_id'=>'pt1','bytes'=>123456789,'seconds'=>300,'observed_at'=>2800))===$estimator7->observations,'Readiness sample included finalizer/public-cutover delay instead of the accepted-to-ready interval.');

    fwrite(STDOUT,"Remote publication periodic-health ownership tests passed.\n");
}
