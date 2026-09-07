<?php
/** Focused R46.4 WordPress publication-gate tests. */
declare(strict_types=1);

$GLOBALS['awvp_gate_posts'] = array();
$GLOBALS['awvp_gate_meta'] = array();
$GLOBALS['awvp_gate_blocks'] = array();
$GLOBALS['awvp_gate_filters'] = array();
$GLOBALS['awvp_gate_actions'] = array();

final class WP_Error
{
    public function __construct(public string $code, public string $message, public mixed $data = null) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): mixed { return $this->data; }
}
final class Awvp_Gate_Request
{
    public function __construct(private array $params) {}
    public function get_param(string $name): mixed { return $this->params[$name] ?? null; }
}

function __(string $text, string $domain = ''): string { unset($domain); return $text; }
function add_filter(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
{ $GLOBALS['awvp_gate_filters'][] = array($hook,$callback,$priority,$accepted_args); return true; }
function add_action(string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1): bool
{ $GLOBALS['awvp_gate_actions'][] = array($hook,$callback,$priority,$accepted_args); return true; }
function get_post_types(array $args = array(), string $output = 'names'): array
{
    unset($args,$output);
    return array(
        'post'=>(object) array('name'=>'post','public'=>true,'publicly_queryable'=>true),
        'page'=>(object) array('name'=>'page','public'=>true,'publicly_queryable'=>true),
        'wp_block'=>(object) array('name'=>'wp_block','public'=>false,'publicly_queryable'=>false),
        'argent_video_asset'=>(object) array('name'=>'argent_video_asset','public'=>false,'publicly_queryable'=>false),
    );
}
function parse_blocks(string $content): array { return $GLOBALS['awvp_gate_blocks'][$content] ?? array(); }
function get_post(int $id): mixed { return $GLOBALS['awvp_gate_posts'][$id] ?? null; }
function metadata_exists(string $type, int $id, string $key): bool
{ unset($type); return array_key_exists($key, $GLOBALS['awvp_gate_meta'][$id] ?? array()); }
function get_post_meta(int $id, string $key, bool $single = false): mixed
{ unset($single); return $GLOBALS['awvp_gate_meta'][$id][$key] ?? ''; }
function wp_unslash(string $value): string { return stripslashes($value); }
function wp_slash(string $value): string { return addslashes($value); }

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
require_once dirname(__DIR__) . '/includes/Video_Post_Type.php';
require_once dirname(__DIR__) . '/includes/Video_Meta.php';
require_once dirname(__DIR__) . '/includes/Video_Destination.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Plan.php';
require_once dirname(__DIR__) . '/includes/Editorial_Publish_Validator.php';
require_once dirname(__DIR__) . '/includes/Editorial_Publish_Gate.php';

use ArgentVideo\Editorial_Publish_Gate;
use ArgentVideo\Editorial_Publish_Validator;
use ArgentVideo\PeerTube_Publication_Plan;
use ArgentVideo\Video_Meta;
use ArgentVideo\Video_Post_Type;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};
$block = static fn (int $id): array => array('blockName'=>Editorial_Publish_Validator::BLOCK_NAME,'attrs'=>array('videoId'=>$id),'innerBlocks'=>array());
$plan = static fn (): array => array(
    'version'=>1,'backend_id'=>'pt-main','channel_id'=>'7','title'=>'Reviewed title','description_markdown'=>'','tags'=>array(),
    'support'=>array('mode'=>'none','preset_id'=>'','markdown'=>''),'final_privacy_id'=>'1','licence_id'=>'','category_id'=>'','language'=>'',
    'thumbnail_attachment_id'=>0,'download_enabled'=>true,'originally_published_at'=>'','comments_policy'=>'enabled',
    'moderation'=>array('reviewed'=>true,'sensitive'=>false,'reason'=>'','violent'=>false,'sexually_explicit'=>false),
    'embed'=>array('restricted'=>false,'domains'=>array()),
    'review'=>array('title'=>true,'channel'=>true,'tags'=>true,'privacy'=>true,'moderation'=>true),
    'dispatch_policy'=>PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,
    'release_policy'=>PeerTube_Publication_Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,'anchor_post_id'=>10,
);

$GLOBALS['awvp_gate_posts'][10] = (object) array('ID'=>10,'post_type'=>'post','post_status'=>'draft','post_content'=>'old-content');
foreach (array(201,202) as $id) {
    $GLOBALS['awvp_gate_posts'][$id] = (object) array('ID'=>$id,'post_type'=>Video_Post_Type::POST_TYPE,'post_status'=>'publish','post_content'=>'');
    $GLOBALS['awvp_gate_meta'][$id][Video_Meta::ORIGIN_POST_ID] = 10;
    $GLOBALS['awvp_gate_meta'][$id][Video_Meta::DESTINATION] = array('version'=>1,'backend_id'=>'pt-main','channel_id'=>'7');
}
$GLOBALS['awvp_gate_meta'][202][Video_Meta::PEERTUBE_PUBLICATION_PLAN] = $plan();
$GLOBALS['awvp_gate_blocks']['unresolved'] = array($block(201));
$GLOBALS['awvp_gate_blocks']['ready'] = array($block(202));
$GLOBALS['awvp_gate_blocks']['old-content'] = array();

$gate = new Editorial_Publish_Gate(new Editorial_Publish_Validator());
$gate->register();
$assert('wp_insert_post_data' === ($GLOBALS['awvp_gate_filters'][0][0] ?? ''), 'Generic pre-write publication gate was not registered.');
$assert('rest_api_init' === ($GLOBALS['awvp_gate_actions'][0][0] ?? ''), 'REST filter registrar was not registered.');
$gate->register_rest_filters();
$rest_hooks = array_map(static fn(array $row): string => $row[0], $GLOBALS['awvp_gate_filters']);
$assert(in_array('rest_pre_insert_post', $rest_hooks, true) && in_array('rest_pre_insert_page', $rest_hooks, true), 'Public REST post types did not receive publication filters.');
$assert(! in_array('rest_pre_insert_wp_block', $rest_hooks, true), 'Non-public wp_block received editorial publication authority.');

$request = new Awvp_Gate_Request(array('id'=>10));
$prepared = (object) array('ID'=>10,'post_status'=>'publish','post_content'=>'unresolved');
$blocked = $gate->rest_pre_insert($prepared, $request);
$assert($blocked instanceof WP_Error && Editorial_Publish_Gate::ERROR_CODE === $blocked->get_error_code(), 'REST publish with unresolved review was not blocked.');
$data = $blocked->get_error_data();
$assert(409 === ($data['status'] ?? 0) && is_array($data['awvp_issues'] ?? null), 'REST publication error did not expose bounded issue data.');
$assert(str_contains($blocked->get_error_message(), 'Review the PeerTube title'), 'REST error did not explain exact missing review.');

$new_prepared = (object) array(
    'post_status'=>'publish',
    'post_content'=>'<!-- wp:argentwolf-video-processor/video {"videoId":0} /-->',
);
$new_blocked = $gate->rest_pre_insert($new_prepared, new Awvp_Gate_Request(array()));
$assert($new_blocked instanceof WP_Error && Editorial_Publish_Gate::ERROR_CODE === $new_blocked->get_error_code(), 'Brand-new REST publication with AWVP content did not fail explicitly.');
$new_data = $new_blocked->get_error_data();
$assert('video_binding' === ($new_data['awvp_issues'][0]['code'] ?? ''), 'Brand-new REST publication returned the wrong AWVP issue.');

$draft = (object) array('ID'=>10,'post_status'=>'draft','post_content'=>'unresolved');
$assert($draft === $gate->rest_pre_insert($draft, $request), 'Draft save was blocked by publication validation.');

$prepared_ready = (object) array('ID'=>10,'post_status'=>'publish','post_content'=>'ready');
$assert($prepared_ready === $gate->rest_pre_insert($prepared_ready, $request), 'Reviewed REST publication was blocked.');
foreach (array('future','private') as $status) {
    $candidate = (object) array('ID'=>10,'post_status'=>$status,'post_content'=>'unresolved');
    $assert($gate->rest_pre_insert($candidate, $request) instanceof WP_Error, "REST {$status} transition bypassed publication validation.");
}

$data = array('post_status'=>'publish','post_content'=>'unresolved','post_type'=>'post');
$filtered = $gate->filter_insert_post_data($data, array('ID'=>10), array('ID'=>10), true);
$assert('draft' === $filtered['post_status'] && 'unresolved' === $filtered['post_content'], 'Non-REST draft publish fallback did not preserve edits as draft.');

$data_ready = array('post_status'=>'publish','post_content'=>'ready','post_type'=>'post');
$filtered_ready = $gate->filter_insert_post_data($data_ready, array('ID'=>10), array('ID'=>10), true);
$assert('publish' === $filtered_ready['post_status'], 'Non-REST reviewed publication was blocked.');

$GLOBALS['awvp_gate_posts'][10]->post_status = 'future';
$GLOBALS['awvp_gate_posts'][10]->post_content = 'old-content';
$future = $gate->filter_insert_post_data($data, array('ID'=>10), array('ID'=>10), true);
$assert('future' === $future['post_status'], 'Unresolved future->publish fallback did not retain future status.');
$assert(stripslashes($future['post_content']) === 'old-content', 'Protected-post fallback exposed unresolved new content.');

$GLOBALS['awvp_gate_posts'][10]->post_status = 'publish';
$published = $gate->filter_insert_post_data($data, array('ID'=>10), array('ID'=>10), true);
$assert('publish' === $published['post_status'] && stripslashes($published['post_content']) === 'old-content', 'Already-published non-REST fallback destructively changed status/content.');

$new_data = array('post_status'=>'publish','post_content'=>'<!-- wp:argentwolf-video-processor/video {"videoId":0} /-->','post_type'=>'post');
$new_filtered = $gate->filter_insert_post_data($new_data, array(), array(), false);
$assert('draft' === $new_filtered['post_status'], 'Unsaved post with AWVP block bypassed generic publication fallback.');

$source = (string) file_get_contents(dirname(__DIR__) . '/includes/Editorial_Publish_Gate.php');
foreach (array('wp_remote_', 'PeerTube_Api_Client', 'PeerTube_Publication_Catalog', 'PeerTube_Task', 'transition_post_status', 'wp_publish_post') as $forbidden) {
    $assert(! str_contains($source, $forbidden), 'Editorial publish gate acquired forbidden remote/post-transition authority: ' . $forbidden);
}
$assert(str_contains($source, "'publish', 'future', 'private'"), 'Protected WordPress publication statuses changed.');

fwrite(STDOUT, "R46 editorial publish gate tests passed.\n");
