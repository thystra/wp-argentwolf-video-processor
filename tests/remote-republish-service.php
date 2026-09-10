<?php
/** Dependency-free RC10 restart-safe remote republish service tests. */
declare(strict_types=1);

namespace ArgentVideo {
$GLOBALS['awvp_republish_meta'] = array();
$GLOBALS['awvp_republish_posts'] = array();
$GLOBALS['awvp_republish_source'] = array(
    'relative_path' => '2026/09/source.mp4',
    'bytes' => 123456789,
    'device' => 1,
    'inode' => 2,
    'mtime' => 3,
    'ctime' => 4,
);
$GLOBALS['awvp_republish_ids'] = array();

function get_post(int $id): mixed { return $GLOBALS['awvp_republish_posts'][$id] ?? null; }
function get_post_meta(int $id, string $key, bool $single = true): mixed { unset($single); return $GLOBALS['awvp_republish_meta'][$id][$key] ?? ''; }
function update_post_meta(int $id, string $key, mixed $value): int|bool { $GLOBALS['awvp_republish_meta'][$id][$key] = $value; return 1; }
function delete_post_meta(int $id, string $key): bool { unset($GLOBALS['awvp_republish_meta'][$id][$key]); return true; }
function metadata_exists(string $type, int $id, string $key): bool { unset($type); return array_key_exists($key, $GLOBALS['awvp_republish_meta'][$id] ?? array()); }
function get_posts(array $args = array()): array { unset($args); return $GLOBALS['awvp_republish_ids']; }

final class Backend_Identity {
    public static function sanitize(mixed $value): string {
        if (! is_string($value)) return '';
        $value = strtolower(trim($value));
        return 1 === preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/D', $value) ? $value : '';
    }
}

final class Backend_Registry {
    public const LOCAL_ID = 'local';
    public const PEERTUBE_TYPE = 'peertube';
    /** @param array<string,array<string,mixed>> $rows */
    public function __construct(public array $rows) {}
    public function all(): array { return $this->rows; }
    public function get(string $id): ?array { return $this->rows[$id] ?? null; }
    public function get_fresh(string $id): ?array { return $this->get($id); }
}

final class Video_Post_Type { public const POST_TYPE = 'argent_video_asset'; }

final class Video_Meta {
    public const ATTACHMENT_ID = 'attachment';
    public const DESTINATION = 'destination';
    public const PEERTUBE_PUBLICATION_PLAN = 'plan';
    public const PEERTUBE_PUBLICATION_LIFECYCLE = 'lifecycle';
    public const PEERTUBE_PUBLICATION_EXECUTION = 'execution';
    public const REMOTE_REPUBLISH_REQUEST = 'republish';
    public const SERVING_AUTHORITY = 'authority';
    public const LAST_ERROR = 'last_error';
    public static function sanitize_positive_id(mixed $value): int { return is_numeric($value) && (int) $value > 0 ? (int) $value : 0; }
}

final class Video_Destination {
    public const VERSION = 1;
    public static function sanitize(mixed $value): array {
        if (! is_array($value)) return array();
        $backend = Backend_Identity::sanitize($value['backend_id'] ?? null);
        $channel = is_scalar($value['channel_id'] ?? null) ? trim((string) $value['channel_id']) : '';
        if (1 !== ($value['version'] ?? null) || '' === $backend || '' === $channel) return array();
        return array('version'=>1, 'backend_id'=>$backend, 'channel_id'=>$channel);
    }
}

final class PeerTube_Publication_Plan {
    public static function sanitize(mixed $value): array {
        if (! is_array($value)) return array();
        $backend = Backend_Identity::sanitize($value['backend_id'] ?? null);
        $channel = is_scalar($value['channel_id'] ?? null) ? trim((string) $value['channel_id']) : '';
        if ('' === $backend || '' === $channel || ! isset($value['anchor_post_id']) || (int) $value['anchor_post_id'] < 1) return array();
        $out = $value;
        $out['backend_id'] = $backend;
        $out['channel_id'] = $channel;
        $out['anchor_post_id'] = (int) $value['anchor_post_id'];
        return $out;
    }
    public static function ready_for_dispatch(array $plan): bool { return array() !== self::sanitize($plan) && true === ($plan['reviewed_ready'] ?? false); }
}

final class PeerTube_Publication_Lifecycle {
    public static function sanitize(mixed $value): array {
        return is_array($value) && isset($value['generation']) && (int) $value['generation'] > 0 ? $value : array();
    }
}

final class PeerTube_Publication_Catalog {
    public static function sanitize(mixed $value): array {
        return is_array($value) && isset($value['backend_id'], $value['origin'], $value['stale']) ? $value : array();
    }
}

final class PeerTube_Origin {
    public static function sanitize(mixed $value): string {
        return is_string($value) && str_starts_with($value, 'https://') ? rtrim($value, '/') : '';
    }
}

final class Video_Publishing_Defaults {
    public static function effective_for_backend(array $settings, array $descriptor): array {
        $id = (string) ($descriptor['id'] ?? '');
        $override = $settings['backend_overrides'][$id] ?? null;
        return is_array($override) ? $override : array();
    }
}

final class PeerTube_Publication_Manifest {
    public static function build(array $plan, array $catalog, ?array $settings = null): array {
        unset($settings);
        if (array() === PeerTube_Publication_Plan::sanitize($plan) || true === ($catalog['stale'] ?? true)) return array();
        $allowed = $catalog['channels'] ?? array();
        return in_array((string) $plan['channel_id'], $allowed, true) ? array('ok'=>true) : array();
    }
}

final class WordPress_Source_File {
    public static function capture(int $attachment_id): array {
        return 501 === $attachment_id ? ($GLOBALS['awvp_republish_source'] ?? array()) : array();
    }
    public static function sanitize_identity(mixed $value): array {
        return is_array($value) && isset($value['relative_path'], $value['bytes'], $value['inode']) ? $value : array();
    }
    public static function matches(int $attachment_id, array $identity): bool { return self::capture($attachment_id) === self::sanitize_identity($identity); }
}

final class Video_Publishing_Defaults_Store {
    public function __construct(public ?array $settings) {}
    public function get(): ?array { return $this->settings; }
}

final class PeerTube_Publication_Catalog_Store {
    /** @param array<string,array<string,mixed>> $catalogs */
    public function __construct(public array $catalogs) {}
    public function get(string $backend_id): ?array { return $this->catalogs[$backend_id] ?? null; }
    public function get_fresh(string $backend_id): ?array { return $this->catalogs[$backend_id] ?? null; }
}

final class Task_Repository {
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const CONFLICT = 'conflict';
    public const INDETERMINATE = 'indeterminate';
}

final class PeerTube_Publication_Synchronizer {
    /** @var list<array{status:string,generation:int,task_id:int}> */
    public array $results = array();
    /** @var list<array{video_id:int,status:string,now:int,generation:int}> */
    public array $calls = array();
    public function sync_republish_video(int $video_id, string $status, int $now, int $generation): array {
        $this->calls[] = compact('video_id','status','now','generation');
        $result = array_shift($this->results) ?? array('status'=>Task_Repository::APPLIED,'generation'=>$generation,'task_id'=>700 + count($this->calls));
        if (in_array($result['status'], array(Task_Repository::APPLIED, Task_Repository::PRESENT), true)) {
            $before = PeerTube_Publication_Lifecycle::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, true));
            $before['generation'] = $generation;
            $before['backend_id'] = (string) (get_post_meta($video_id, Video_Meta::DESTINATION, true)['backend_id'] ?? '');
            $before['task_pending'] = false;
            $before['updated_at'] = $now;
            update_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, $before);
        }
        return $result;
    }
}

final class PeerTube_Event_Repository {
    public array $events = array();
    public function record(int $video_id, int $step, string $code, string $severity, string $message, int $occurred_at, int $task_id = 0, string $operation_id = '', int $remote_asset_id = 0, string $backend_id = '', int $http_status = 0, string $automatic_action = '', string $operator_action = '', array $context = array()): bool {
        $this->events[] = compact('video_id','step','code','severity','message','occurred_at','task_id','operation_id','remote_asset_id','backend_id','http_status','automatic_action','operator_action','context');
        return true;
    }
}
}

namespace {
require_once dirname(__DIR__) . '/includes/Remote_Republish_Request.php';
require_once dirname(__DIR__) . '/includes/Remote_Republish_Service.php';

use ArgentVideo\Backend_Registry;
use ArgentVideo\PeerTube_Event_Repository;
use ArgentVideo\PeerTube_Publication_Catalog_Store;
use ArgentVideo\PeerTube_Publication_Synchronizer;
use ArgentVideo\Remote_Republish_Request;
use ArgentVideo\Remote_Republish_Service;
use ArgentVideo\Task_Repository;
use ArgentVideo\Video_Meta;
use ArgentVideo\Video_Publishing_Defaults_Store;

$failures = 0;
$assert = static function (bool $ok, string $message) use (&$failures): void {
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        ++$failures;
    }
};

$descriptor = static function (string $id, string $origin, string $channel): array {
    return array(
        'id'=>$id, 'type'=>'peertube', 'label'=>strtoupper($id), 'state'=>'active',
        'default_destination'=>$channel, 'config'=>array('origin'=>$origin),
    );
};
$catalog = static function (string $id, string $origin, array $channels): array {
    return array('backend_id'=>$id, 'origin'=>$origin, 'stale'=>false, 'channels'=>$channels);
};
$plan = static function (string $backend, string $channel): array {
    return array(
        'backend_id'=>$backend, 'channel_id'=>$channel, 'anchor_post_id'=>900,
        'title'=>'Keep title', 'description_markdown'=>'Keep description', 'tags'=>array('one','two words'),
        'final_privacy_id'=>'1', 'licence_id'=>'9', 'category_id'=>'8', 'language'=>'en',
        'review'=>array('channel'=>true,'privacy'=>true), 'reviewed_ready'=>true,
    );
};
$settings = array('backend_overrides'=>array(
    'pt1'=>array('channel_id'=>'2','final_privacy_id'=>'1','licence_id'=>'9','category_id'=>'8','language'=>'en'),
    'pt2'=>array('channel_id'=>'77','final_privacy_id'=>'3','licence_id'=>'4','category_id'=>'12','language'=>'fr'),
));
$registry = new Backend_Registry(array(
    'pt1'=>$descriptor('pt1','https://pt1.example','2'),
    'pt2'=>$descriptor('pt2','https://pt2.example','77'),
));
$catalogs = new PeerTube_Publication_Catalog_Store(array(
    'pt1'=>$catalog('pt1','https://pt1.example',array('2')),
    'pt2'=>$catalog('pt2','https://pt2.example',array('77')),
));
$defaults = new Video_Publishing_Defaults_Store($settings);

$reset = static function (array $initial_plan): void {
    $GLOBALS['awvp_republish_meta'] = array(
        100=>array(
            Video_Meta::ATTACHMENT_ID=>501,
            Video_Meta::DESTINATION=>array('version'=>1,'backend_id'=>'pt1','channel_id'=>'2'),
            Video_Meta::PEERTUBE_PUBLICATION_PLAN=>$initial_plan,
            Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE=>array('generation'=>4,'backend_id'=>'pt1','task_pending'=>false,'updated_at'=>1000),
            Video_Meta::PEERTUBE_PUBLICATION_EXECUTION=>array('operation_id'=>'old-upload'),
            Video_Meta::SERVING_AUTHORITY=>array('remote_asset_id'=>9,'backend_id'=>'pt1'),
            Video_Meta::LAST_ERROR=>'old historical error',
            'remote_history'=>array('asset'=>9),
        ),
    );
    $GLOBALS['awvp_republish_posts'] = array(
        100=>(object)array('ID'=>100,'post_type'=>'argent_video_asset','post_status'=>'publish'),
        501=>(object)array('ID'=>501,'post_type'=>'attachment','post_status'=>'inherit'),
        900=>(object)array('ID'=>900,'post_type'=>'post','post_status'=>'publish'),
    );
    $GLOBALS['awvp_republish_ids'] = array(100);
    $GLOBALS['awvp_republish_source'] = array('relative_path'=>'2026/09/source.mp4','bytes'=>123456789,'device'=>1,'inode'=>2,'mtime'=>3,'ctime'=>4);
};

// Same-backend republish creates exactly one new generation/task and resets only current execution.
$reset($plan('pt1','2'));
$sync = new PeerTube_Publication_Synchronizer();
$events = new PeerTube_Event_Repository();
$svc = new Remote_Republish_Service($registry,$defaults,$catalogs,$sync,$events);
$before_authority = $GLOBALS['awvp_republish_meta'][100][Video_Meta::SERVING_AUTHORITY];
$before_history = $GLOBALS['awvp_republish_meta'][100]['remote_history'];
$r = $svc->request(100,'pt1',42,2000);
$assert(Remote_Republish_Service::APPLIED === $r['status'] && 5 === $r['generation'] && $r['task_id'] > 0, 'Same-backend republish did not queue generation 5.');
$assert(1 === count($sync->calls) && 5 === $sync->calls[0]['generation'], 'Same-backend republish did not dispatch exactly one exact-generation sync.');
$assert(! array_key_exists(Video_Meta::PEERTUBE_PUBLICATION_EXECUTION,$GLOBALS['awvp_republish_meta'][100]), 'Republish did not clear the current execution pointer before new upload work.');
$assert($before_authority === $GLOBALS['awvp_republish_meta'][100][Video_Meta::SERVING_AUTHORITY], 'Republish overwrote historical/current serving authority before replacement verification.');
$assert($before_history === $GLOBALS['awvp_republish_meta'][100]['remote_history'], 'Republish mutated historical remote-asset evidence.');
$stored = $GLOBALS['awvp_republish_meta'][100][Video_Meta::REMOTE_REPUBLISH_REQUEST] ?? array();
$assert(Remote_Republish_Request::DISPATCHED === ($stored['status'] ?? '') && $r['task_id'] === ($stored['task_id'] ?? 0), 'Republish request journal was not finalized as dispatched.');
$assert(1 === count($events->events) && 'republish_queued' === $events->events[0]['code'], 'Republish did not record one operator event.');
$again = $svc->resume(100,2001);
$assert(Remote_Republish_Service::PRESENT === $again['status'] && 1 === count($sync->calls), 'Resume replay duplicated a dispatched republish task.');

// Different-backend republish preserves editorial content but applies the target backend defaults.
$reset($plan('pt1','2'));
$sync2 = new PeerTube_Publication_Synchronizer();
$svc2 = new Remote_Republish_Service($registry,$defaults,$catalogs,$sync2,null);
$r = $svc2->request(100,'pt2',42,2100);
$new_plan = $GLOBALS['awvp_republish_meta'][100][Video_Meta::PEERTUBE_PUBLICATION_PLAN];
$new_dest = $GLOBALS['awvp_republish_meta'][100][Video_Meta::DESTINATION];
$assert(Remote_Republish_Service::APPLIED === $r['status'] && 5 === $r['generation'], 'Different-backend republish was not queued.');
$assert('pt2' === $new_plan['backend_id'] && '77' === $new_plan['channel_id'] && 'pt2' === $new_dest['backend_id'], 'Different-backend republish did not switch destination/channel.');
$assert('3' === $new_plan['final_privacy_id'] && '4' === $new_plan['licence_id'] && '12' === $new_plan['category_id'] && 'fr' === $new_plan['language'], 'Different-backend republish did not apply target backend defaults.');
$assert('Keep title' === $new_plan['title'] && array('one','two words') === $new_plan['tags'], 'Different-backend republish did not preserve reviewed editorial content.');

// Missing retained source is a hard refusal and does not mutate publication state.
$reset($plan('pt1','2'));
$GLOBALS['awvp_republish_source'] = array();
$sync3 = new PeerTube_Publication_Synchronizer();
$svc3 = new Remote_Republish_Service($registry,$defaults,$catalogs,$sync3,null);
$before = $GLOBALS['awvp_republish_meta'][100];
$r = $svc3->request(100,'pt1',42,2200);
$assert(Remote_Republish_Service::REFUSED === $r['status'] && array() === $sync3->calls, 'Missing-source republish did not fail closed before synchronization.');
$assert($before === $GLOBALS['awvp_republish_meta'][100], 'Missing-source refusal mutated video publication state.');

// Durable pending request survives an indeterminate enqueue and reuses the exact request/generation on recovery.
$reset($plan('pt1','2'));
$sync4 = new PeerTube_Publication_Synchronizer();
$sync4->results[] = array('status'=>Task_Repository::INDETERMINATE,'generation'=>5,'task_id'=>0);
$sync4->results[] = array('status'=>Task_Repository::APPLIED,'generation'=>5,'task_id'=>812);
$events4 = new PeerTube_Event_Repository();
$svc4 = new Remote_Republish_Service($registry,$defaults,$catalogs,$sync4,$events4);
$r1 = $svc4->request(100,'pt1',42,2300);
$pending = $GLOBALS['awvp_republish_meta'][100][Video_Meta::REMOTE_REPUBLISH_REQUEST];
$assert(Remote_Republish_Service::INDETERMINATE === $r1['status'] && Remote_Republish_Request::PENDING === $pending['status'], 'Indeterminate republish did not retain a durable pending journal.');
$request_id = $pending['request_id'];
$r2 = $svc4->resume(100,2301);
$done = $GLOBALS['awvp_republish_meta'][100][Video_Meta::REMOTE_REPUBLISH_REQUEST];
$assert(Remote_Republish_Service::APPLIED === $r2['status'] && 812 === $r2['task_id'] && 5 === $r2['generation'], 'Recovery did not dispatch the pending exact-generation republish.');
$assert($request_id === $done['request_id'] && 5 === $done['target_generation'] && Remote_Republish_Request::DISPATCHED === $done['status'], 'Recovery changed republish request identity/generation.');
$assert(2 === count($sync4->calls) && 5 === $sync4->calls[0]['generation'] && 5 === $sync4->calls[1]['generation'], 'Recovery manufactured a different generation.');
$assert(1 === count($events4->events), 'Recovery produced duplicate republish queued events.');

if ($failures > 0) {
    fwrite(STDERR, "Remote republish service tests failed: {$failures}\n");
    exit(1);
}
fwrite(STDOUT, "RC10 remote republish service tests passed.\n");
}
