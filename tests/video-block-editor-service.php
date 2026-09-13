<?php
/** Focused dependency-free tests for R46.3b block editor service. */
declare(strict_types=1);

$GLOBALS['awvp_editor_options'] = array();
$GLOBALS['awvp_editor_posts'] = array();
$GLOBALS['awvp_editor_meta'] = array();
$GLOBALS['awvp_editor_next_post_id'] = 100;
$GLOBALS['awvp_editor_deleted'] = array();

function __(string $text, string $domain = ''): string { unset($domain); return $text; }
function sanitize_text_field(mixed $value): string
{
    $value = preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $value)) ?? '';
    return trim($value);
}
function sanitize_key(mixed $value): string
{
    $value = strtolower((string) $value);
    return preg_replace('/[^a-z0-9_\-]/', '', $value) ?? '';
}
function esc_url_raw(string $url): string { return $url; }
function wp_parse_url(string $url): array|false { return parse_url($url); }
function sanitize_file_name(string $value): string { return preg_replace('/[^A-Za-z0-9._-]/', '-', $value) ?? ''; }
function sanitize_mime_type(string $value): string { return preg_replace('/[^A-Za-z0-9.+\/-]/', '', $value) ?? ''; }
function absint(mixed $value): int { return abs((int) $value); }
function get_option(string $name, mixed $default = false): mixed
{
    return array_key_exists($name, $GLOBALS['awvp_editor_options']) ? $GLOBALS['awvp_editor_options'][$name] : $default;
}
function add_option(string $name, mixed $value = '', string $deprecated = '', bool|string $autoload = 'yes'): bool
{
    unset($deprecated, $autoload);
    if (array_key_exists($name, $GLOBALS['awvp_editor_options'])) return false;
    $GLOBALS['awvp_editor_options'][$name] = $value;
    return true;
}
function update_option(string $name, mixed $value, bool|string|null $autoload = null): bool
{
    unset($autoload);
    $GLOBALS['awvp_editor_options'][$name] = $value;
    return true;
}
function delete_option(string $name): bool
{
    if (! array_key_exists($name, $GLOBALS['awvp_editor_options'])) return false;
    unset($GLOBALS['awvp_editor_options'][$name]);
    return true;
}
function get_post(int $post_id): object|false
{
    return $GLOBALS['awvp_editor_posts'][$post_id] ?? false;
}
function get_post_mime_type(int $post_id): string|false
{
    $post = $GLOBALS['awvp_editor_posts'][$post_id] ?? null;
    return is_object($post) ? (string) ($post->post_mime_type ?? '') : false;
}
function get_the_title(int $post_id): string
{
    $post = $GLOBALS['awvp_editor_posts'][$post_id] ?? null;
    return is_object($post) ? (string) ($post->post_title ?? '') : '';
}
function wp_get_attachment_url(int $post_id): string|false
{
    $post = $GLOBALS['awvp_editor_posts'][$post_id] ?? null;
    return is_object($post) ? (string) ($post->url ?? '') : false;
}
function metadata_exists(string $type, int $object_id, string $meta_key): bool
{
    unset($type);
    return array_key_exists($meta_key, $GLOBALS['awvp_editor_meta'][$object_id] ?? array());
}
function get_post_meta(int $post_id, string $meta_key, bool $single = false): mixed
{
    $value = $GLOBALS['awvp_editor_meta'][$post_id][$meta_key] ?? ($single ? '' : array());
    return $single ? $value : array($value);
}
function update_post_meta(int $post_id, string $meta_key, mixed $value): int|bool
{
    $GLOBALS['awvp_editor_meta'][$post_id][$meta_key] = $value;
    return 1;
}
function add_post_meta(int $post_id, string $meta_key, mixed $value, bool $unique = false): int|false
{
    if ($unique && metadata_exists('post', $post_id, $meta_key)) return false;
    $GLOBALS['awvp_editor_meta'][$post_id][$meta_key] = $value;
    return 1;
}
function wp_insert_post(array $postarr, bool $wp_error = false): int
{
    unset($wp_error);
    $id = $GLOBALS['awvp_editor_next_post_id']++;
    $GLOBALS['awvp_editor_posts'][$id] = (object) array_merge(
        array('ID'=>$id,'post_mime_type'=>'','url'=>''),
        $postarr
    );
    return $id;
}
function is_wp_error(mixed $value): bool { return false; }
function wp_delete_post(int $post_id, bool $force_delete = false): object|false
{
    unset($force_delete);
    if (! isset($GLOBALS['awvp_editor_posts'][$post_id])) return false;
    $post = $GLOBALS['awvp_editor_posts'][$post_id];
    unset($GLOBALS['awvp_editor_posts'][$post_id], $GLOBALS['awvp_editor_meta'][$post_id]);
    $GLOBALS['awvp_editor_deleted'][] = $post_id;
    return $post;
}

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Input.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Snapshot.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Mutation_Plan.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Plan_Result.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Store.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
require_once dirname(__DIR__) . '/includes/Video_Destination.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Plan.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Migration_Plan.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Migration_Execution.php';
require_once dirname(__DIR__) . '/includes/Video_Publishing_Defaults.php';
require_once dirname(__DIR__) . '/includes/Video_Publishing_Defaults_Store.php';
require_once dirname(__DIR__) . '/includes/Video_Post_Type.php';
require_once dirname(__DIR__) . '/includes/WordPress_Source_File.php';
require_once dirname(__DIR__) . '/includes/Source_Retirement_Record.php';
require_once dirname(__DIR__) . '/includes/Video_Serving_Authority.php';
require_once dirname(__DIR__) . '/includes/Video_Meta.php';
require_once dirname(__DIR__) . '/includes/Video_Block_Editor_Service.php';

use ArgentVideo\Backend_Registry;
use ArgentVideo\PeerTube_Publication_Plan;
use ArgentVideo\Video_Block_Editor_Service;
use ArgentVideo\Video_Destination;
use ArgentVideo\Video_Meta;
use ArgentVideo\Video_Publishing_Defaults;
use ArgentVideo\Video_Publishing_Defaults_Store;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

$local = array(
    'id'=>'local','type'=>'local','label'=>'Local AWVP','state'=>'active','default_destination'=>'',
    'secret_ref'=>'','config_version'=>1,'config'=>array(),
);
$peertube = array(
    'id'=>'pt-primary','type'=>'peertube','label'=>'Home PeerTube','state'=>'active','default_destination'=>'41',
    'secret_ref'=>'managed:pt-primary','config_version'=>1,'config'=>array('origin'=>'https://video.example.org'),
);
$GLOBALS['awvp_editor_options'][Backend_Registry::OPTION] = array(
    'version'=>1,
    'backends'=>array('local'=>$local,'pt-primary'=>$peertube),
);
$settings = Video_Publishing_Defaults::defaults();
$settings['default_destination'] = array('version'=>1,'backend_id'=>'pt-primary');
$settings['backend_overrides']['pt-primary'] = array(
    'channel_id'=>'77','final_privacy_id'=>'2','licence_id'=>null,'category_id'=>null,
);
$GLOBALS['awvp_editor_options'][Video_Publishing_Defaults_Store::OPTION] = $settings;

$GLOBALS['awvp_editor_posts'][10] = (object) array(
    'ID'=>10,'post_type'=>'post','post_status'=>'draft','post_title'=>'Origin','post_mime_type'=>'','url'=>'',
);
$GLOBALS['awvp_editor_posts'][11] = (object) array(
    'ID'=>11,'post_type'=>'post','post_status'=>'draft','post_title'=>'Reuse Post','post_mime_type'=>'','url'=>'',
);
foreach (array(
    20=>array('Legacy Clip','https://example.test/uploads/clip.mp4'),
    21=>array('Busy Clip','https://example.test/uploads/busy.mp4'),
    22=>array('Stale Lock Clip','https://example.test/uploads/stale.mp4'),
    23=>array('Malformed Reverse Clip','https://example.test/uploads/malformed.mp4'),
    24=>array('Second Clip','https://example.test/uploads/second.mp4'),
    25=>array('Bad Defaults New Clip','https://example.test/uploads/bad-defaults.mp4'),
) as $id=>$fixture) {
    $GLOBALS['awvp_editor_posts'][$id] = (object) array(
        'ID'=>$id,'post_type'=>'attachment','post_status'=>'inherit','post_title'=>$fixture[0],
        'post_mime_type'=>'video/mp4','url'=>$fixture[1],
    );
}

$registry = new Backend_Registry();
$store = new Video_Publishing_Defaults_Store($registry);
$service = new Video_Block_Editor_Service($registry, $store);
$now = 2000000000;

$first = $service->bind_local_attachment(20, 10, 7, $now);
$assert(Video_Block_Editor_Service::APPLIED === $first['status'], 'First legacy attachment bind did not apply.');
$video_id = $first['video_id'];
$assert($video_id > 0, 'First bind did not return stable AWVP Video ID.');
$assert($video_id === Video_Meta::sanitize_positive_id($GLOBALS['awvp_editor_meta'][20][Video_Block_Editor_Service::ATTACHMENT_ASSET_META] ?? null), 'Attachment reverse pointer mismatch.');
$assert(20 === Video_Meta::sanitize_positive_id($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::ATTACHMENT_ID] ?? null), 'Forward attachment link mismatch.');
$assert(10 === Video_Meta::sanitize_positive_id($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::ORIGIN_POST_ID] ?? null), 'Origin post link mismatch.');
$assert('wordpress_attachment' === ($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::INGEST_KIND] ?? null), 'Adopted video ingest kind mismatch.');
$assert('wordpress_source' === ($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::MASTER_AUTHORITY] ?? null), 'Adopted video master authority mismatch.');
$assert('present' === ($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::SOURCE_STATE] ?? null), 'Adopted video source state mismatch.');
$assert(
    array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'77') === Video_Destination::sanitize($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::DESTINATION] ?? null),
    'New binding did not freeze the current effective site/backend destination.'
);

// Rebinding the same attachment is idempotent and never changes origin/destination.
$settings['default_destination'] = Video_Destination::local();
$GLOBALS['awvp_editor_options'][Video_Publishing_Defaults_Store::OPTION] = $settings;
$before_posts = count($GLOBALS['awvp_editor_posts']);
$again = $service->bind_local_attachment(20, 11, 7, $now + 1);
$assert(Video_Block_Editor_Service::PRESENT === $again['status'] && $video_id === $again['video_id'], 'Repeated attachment bind did not converge to existing AWVP Video.');
$assert($before_posts === count($GLOBALS['awvp_editor_posts']), 'Repeated attachment bind created a duplicate AWVP Video.');
$assert(10 === Video_Meta::sanitize_positive_id($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::ORIGIN_POST_ID] ?? null), 'Repeated bind rewrote original origin post.');
$assert('pt-primary' === (($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::DESTINATION]['backend_id'] ?? '')), 'Site-default change rerouted existing video.');

// Existing identity remains usable even if current defaults become malformed/future.
$valid_local_settings = $settings;
$GLOBALS['awvp_editor_options'][Video_Publishing_Defaults_Store::OPTION] = array('version'=>99,'future'=>true);
$existing_under_bad_defaults = $service->bind_local_attachment(20, 10, 7, $now + 2);
$assert(Video_Block_Editor_Service::PRESENT === $existing_under_bad_defaults['status'] && $video_id === $existing_under_bad_defaults['video_id'], 'Existing binding became unavailable after defaults corruption.');
$before_bad_default_posts = count($GLOBALS['awvp_editor_posts']);
$new_under_bad_defaults = $service->bind_local_attachment(25, 10, 7, $now + 3);
$assert(Video_Block_Editor_Service::REFUSED === $new_under_bad_defaults['status'], 'New binding did not fail closed on malformed/future defaults.');
$assert($before_bad_default_posts === count($GLOBALS['awvp_editor_posts']), 'Refused bad-default binding created durable identity.');
$GLOBALS['awvp_editor_options'][Video_Publishing_Defaults_Store::OPTION] = $valid_local_settings;

// Explicit destination choices are concrete stored state, not mutable pointers.
$set_local = $service->set_destination($video_id, 'local');
$assert(Video_Block_Editor_Service::APPLIED === $set_local['status'], 'Explicit local destination was not stored.');
$assert(Video_Destination::local() === Video_Destination::sanitize($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::DESTINATION] ?? null), 'Explicit local destination bytes mismatch.');
$set_remote = $service->set_destination($video_id, 'backend', 'pt-primary');
$assert(Video_Block_Editor_Service::APPLIED === $set_remote['status'], 'Explicit PeerTube backend destination was not stored.');
$assert('77' === (($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::DESTINATION]['channel_id'] ?? '')), 'Backend destination did not use the current qualified publication-channel override.');
$assert(Video_Block_Editor_Service::REFUSED === $service->set_destination($video_id, 'backend', '../bad')['status'], 'Malformed backend selector was accepted.');

// R46.8 one-way migration commitment freezes the concrete destination target.
$GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::PEERTUBE_MIGRATION_EXECUTION] = array(
    'version'=>1,'video_id'=>$video_id,'migration_plan_sha256'=>str_repeat('a',64),'backend_id'=>'pt-primary','channel_id'=>'77','anchor_post_id'=>10,
    'status'=>'dispatched','lifecycle_generation'=>1,'task_id'=>9,'started_at'=>$now+30,'updated_at'=>$now+30,'promoted_at'=>$now+30,'dispatched_at'=>$now+30,
);
$assert(Video_Block_Editor_Service::REFUSED === $service->set_destination($video_id, 'local')['status'], 'Committed migration was allowed to return to Local.');
$assert(Video_Block_Editor_Service::PRESENT === $service->set_destination($video_id, 'backend', 'pt-primary')['status'], 'Committed migration did not permit idempotent same-target destination selection.');

$state = $service->editor_state($video_id);
$assert(is_array($state), 'Editor state unavailable for valid bound AWVP Video.');
$assert($video_id === ($state['video']['id'] ?? 0), 'Editor state video identity mismatch.');
$assert(20 === ($state['video']['attachment_id'] ?? 0), 'Editor state attachment identity mismatch.');
$assert(true === ($state['video']['destination_valid'] ?? false), 'Valid stored destination was marked invalid.');
$assert('pt-primary' === ($state['video']['destination']['backend_id'] ?? ''), 'Editor state lost selected backend.');
$assert('local' === ($state['site_default']['backend_id'] ?? ''), 'Editor state did not expose current site default separately from stored destination.');
$option_ids = array_column($state['destinations'] ?? array(), 'backend_id');
$assert(array('local','pt-primary') === $option_ids, 'Editor destination options drifted from active local/PeerTube backends.');

// A retired local attachment leaves the durable AWVP Video usable as a remote-only block identity.
unset($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::PEERTUBE_MIGRATION_EXECUTION]);
$source_identity = array('relative_path'=>'2026/09/clip.mp4','bytes'=>123,'device'=>1,'inode'=>2,'mtime'=>3,'ctime'=>4);
$GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::SOURCE_STATE] = 'removed';
$GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::SOURCE_TOMBSTONE] = array(
    'version'=>1,'former_attachment_id'=>20,'attachment_title'=>'Legacy Clip','filename'=>'clip.mp4','mime_type'=>'video/mp4',
    'relative_path'=>'2026/09/clip.mp4','bytes'=>123,'source_identity'=>$source_identity,'retention_task_id'=>55,'removed_at'=>$now+40,
);
$GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::SERVING_AUTHORITY] = array(
    'version'=>2,'mode'=>'peertube','basis'=>'operator_verified_remote','backend_id'=>'pt-primary','channel_id'=>'77',
    'anchor_post_id'=>10,'generation'=>2,'plan_sha256'=>str_repeat('a',64),'manifest_sha256'=>str_repeat('b',64),
    'remote_asset_id'=>9,'remote_uuid'=>'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa',
    'embed_url'=>'https://video.example.org/videos/embed/remote-only','privacy_id'=>'2','verified_at'=>$now+40,
);
unset($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::ATTACHMENT_ID]);
$remote_state = $service->editor_state($video_id);
$assert(is_array($remote_state), 'Remote-only AWVP Video disappeared from editor state after attachment retirement.');
$assert(true === ($remote_state['video']['remote_only'] ?? false), 'Retired attachment was not represented as remote-only editor state.');
$assert(0 === ($remote_state['video']['attachment_id'] ?? -1) && '' === ($remote_state['video']['attachment_url'] ?? 'x'), 'Remote-only editor state still exposes a local attachment.');
$assert('https://video.example.org/videos/embed/remote-only' === ($remote_state['video']['remote_embed_url'] ?? ''), 'Remote-only editor state lost verified PeerTube embed URL.');
$assert(Video_Block_Editor_Service::REFUSED === $service->set_destination($video_id, 'local')['status'], 'Remote-only AWVP Video allowed destination mutation without attaching a new local source.');

// Restore the local fixture for the remaining lock/binding tests.
$GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::ATTACHMENT_ID] = 20;
$GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::SOURCE_STATE] = 'present';
unset($GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::SOURCE_TOMBSTONE], $GLOBALS['awvp_editor_meta'][$video_id][Video_Meta::SERVING_AUTHORITY]);

// Active lock makes the bind busy; stale lock is recovered safely.
$lock_name_21 = 'argentwolf_video_processor_attachment_bind_lock_' . hash('sha256', '21');
$GLOBALS['awvp_editor_options'][$lock_name_21] = array('version'=>1,'token'=>str_repeat('a',32),'created_at'=>$now);
$busy = $service->bind_local_attachment(21, 10, 7, $now + 10);
$assert(Video_Block_Editor_Service::BUSY === $busy['status'], 'Fresh attachment-bind mutex was ignored.');
$lock_name_22 = 'argentwolf_video_processor_attachment_bind_lock_' . hash('sha256', '22');
$GLOBALS['awvp_editor_options'][$lock_name_22] = array('version'=>1,'token'=>str_repeat('b',32),'created_at'=>$now - 1000);
$stale = $service->bind_local_attachment(22, 10, 7, $now + 10);
$assert(Video_Block_Editor_Service::APPLIED === $stale['status'], 'Stale attachment-bind mutex did not recover.');
$assert(! array_key_exists($lock_name_22, $GLOBALS['awvp_editor_options']), 'Recovered mutex was not released.');

// Present malformed reverse state is ambiguous and is never repaired implicitly.
$GLOBALS['awvp_editor_meta'][23][Video_Block_Editor_Service::ATTACHMENT_ASSET_META] = 'not-an-id';
$malformed = $service->bind_local_attachment(23, 10, 7, $now + 20);
$assert(Video_Block_Editor_Service::REFUSED === $malformed['status'], 'Malformed present reverse pointer was overwritten during bind.');
$assert('not-an-id' === $GLOBALS['awvp_editor_meta'][23][Video_Block_Editor_Service::ATTACHMENT_ASSET_META], 'Malformed reverse pointer bytes changed.');

$source = (string) file_get_contents(dirname(__DIR__) . '/includes/Video_Block_Editor_Service.php');
foreach (array('wp_remote_','PeerTube_Api_Client','PeerTube_Task','wp_schedule_','transition_post_status','wp_publish_post') as $forbidden) {
    $assert(! str_contains($source, $forbidden), 'Editor service acquired forbidden remote/scheduler/publish authority: ' . $forbidden);
}

fwrite(STDOUT, "R46 video block editor service tests passed.\n");
