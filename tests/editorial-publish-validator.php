<?php
/** Focused R46.4 local-only editorial publication validator tests. */
declare(strict_types=1);

$GLOBALS['awvp_pub_posts'] = array();
$GLOBALS['awvp_pub_meta'] = array();
$GLOBALS['awvp_pub_blocks'] = array();

function __(string $text, string $domain = ''): string { unset($domain); return $text; }
function parse_blocks(string $content): array { return $GLOBALS['awvp_pub_blocks'][$content] ?? array(); }
function get_post(int $id): mixed { return $GLOBALS['awvp_pub_posts'][$id] ?? null; }
function metadata_exists(string $type, int $id, string $key): bool
{
    unset($type);
    return array_key_exists($key, $GLOBALS['awvp_pub_meta'][$id] ?? array());
}
function get_post_meta(int $id, string $key, bool $single = false): mixed
{
    unset($single);
    return $GLOBALS['awvp_pub_meta'][$id][$key] ?? '';
}

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
require_once dirname(__DIR__) . '/includes/Video_Post_Type.php';
require_once dirname(__DIR__) . '/includes/Video_Meta.php';
require_once dirname(__DIR__) . '/includes/Video_Destination.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Plan.php';
require_once dirname(__DIR__) . '/includes/Editorial_Publish_Validator.php';

use ArgentVideo\Editorial_Publish_Validator;
use ArgentVideo\PeerTube_Publication_Plan;
use ArgentVideo\Video_Destination;
use ArgentVideo\Video_Meta;
use ArgentVideo\Video_Post_Type;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

$block = static fn (int $id): array => array(
    'blockName'=>Editorial_Publish_Validator::BLOCK_NAME,
    'attrs'=>array('videoId'=>$id),
    'innerBlocks'=>array(),
);
$video = static function (int $id, int $origin = 10): void {
    $GLOBALS['awvp_pub_posts'][$id] = (object) array(
        'ID'=>$id,'post_type'=>Video_Post_Type::POST_TYPE,'post_status'=>'publish','post_content'=>'',
    );
    $GLOBALS['awvp_pub_meta'][$id][Video_Meta::ORIGIN_POST_ID] = $origin;
};
$plan = static function (int $anchor = 10, string $backend = 'pt-main', string $channel = '7', array $review = array()): array {
    $review = array_merge(array('title'=>true,'channel'=>true,'tags'=>true,'privacy'=>true,'moderation'=>true), $review);
    return array(
        'version'=>PeerTube_Publication_Plan::VERSION,
        'backend_id'=>$backend,
        'channel_id'=>$channel,
        'title'=>'Reviewed title',
        'description_markdown'=>'Description',
        'tags'=>array(),
        'support'=>array('mode'=>'none','preset_id'=>'','markdown'=>''),
        'final_privacy_id'=>'1',
        'licence_id'=>'',
        'category_id'=>'',
        'language'=>'',
        'thumbnail_attachment_id'=>0,
        'download_enabled'=>true,
        'originally_published_at'=>'',
        'comments_policy'=>'enabled',
        'moderation'=>array('reviewed'=>$review['moderation'],'sensitive'=>false,'reason'=>'','violent'=>false,'sexually_explicit'=>false),
        'embed'=>array('restricted'=>false,'domains'=>array()),
        'review'=>$review,
        'dispatch_policy'=>PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,
        'release_policy'=>PeerTube_Publication_Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,
        'anchor_post_id'=>$anchor,
    );
};
$codes = static fn (array $result): array => array_values(array_map(static fn (array $issue): string => $issue['code'], $result['issues']));

$validator = new Editorial_Publish_Validator();

$GLOBALS['awvp_pub_blocks']['none'] = array(array('blockName'=>'core/paragraph','attrs'=>array(),'innerBlocks'=>array()));
$assert(true === $validator->validate(10, 'none')['ready'], 'Post without AWVP block was blocked.');

$GLOBALS['awvp_pub_blocks']['unbound'] = array(array('blockName'=>Editorial_Publish_Validator::BLOCK_NAME,'attrs'=>array(),'innerBlocks'=>array()));
$unbound = $validator->validate(10, 'unbound');
$assert(false === $unbound['ready'] && in_array('video_binding', $codes($unbound), true), 'Unbound AWVP block did not block publication.');

$video(101);
$GLOBALS['awvp_pub_meta'][101][Video_Meta::DESTINATION] = Video_Destination::local();
$GLOBALS['awvp_pub_blocks']['local'] = array($block(101));
$assert(true === $validator->validate(10, 'local')['ready'], 'Explicit local video was blocked.');

$video(102);
$GLOBALS['awvp_pub_blocks']['legacy-local'] = array($block(102));
$assert(true === $validator->validate(10, 'legacy-local')['ready'], 'Missing legacy destination did not resolve local.');

$video(103);
$GLOBALS['awvp_pub_meta'][103][Video_Meta::DESTINATION] = array('version'=>1,'backend_id'=>'pt-main','channel_id'=>'7');
$GLOBALS['awvp_pub_blocks']['remote-absent'] = array($block(103));
$absent = $validator->validate(10, 'remote-absent');
foreach (array('title','channel','tags','privacy','moderation') as $required) {
    $assert(in_array($required, $codes($absent), true), "Missing plan did not report {$required} review.");
}

$video(104);
$GLOBALS['awvp_pub_meta'][104][Video_Meta::DESTINATION] = array('version'=>1,'backend_id'=>'pt-main','channel_id'=>'7');
$GLOBALS['awvp_pub_meta'][104][Video_Meta::PEERTUBE_PUBLICATION_PLAN] = $plan(10, 'pt-main', '7', array('channel'=>false,'privacy'=>false));
$GLOBALS['awvp_pub_blocks']['partial'] = array($block(104));
$partial = $validator->validate(10, 'partial');
$assert(false === $partial['ready'] && in_array('channel', $codes($partial), true) && in_array('privacy', $codes($partial), true), 'Partial review was not blocked precisely.');
$assert(! in_array('title', $codes($partial), true), 'Reviewed title was reported missing.');

$video(105);
$GLOBALS['awvp_pub_meta'][105][Video_Meta::DESTINATION] = array('version'=>1,'backend_id'=>'pt-main','channel_id'=>'7');
$GLOBALS['awvp_pub_meta'][105][Video_Meta::PEERTUBE_PUBLICATION_PLAN] = $plan();
$GLOBALS['awvp_pub_blocks']['ready'] = array($block(105));
$ready = $validator->validate(10, 'ready');
$assert(true === $ready['ready'] && array() === $ready['issues'], 'Fully reviewed local plan was blocked.');

$video(106);
$GLOBALS['awvp_pub_meta'][106][Video_Meta::DESTINATION] = array('version'=>1,'backend_id'=>'pt-main','channel_id'=>'9');
$GLOBALS['awvp_pub_meta'][106][Video_Meta::PEERTUBE_PUBLICATION_PLAN] = $plan();
$GLOBALS['awvp_pub_blocks']['channel-mismatch'] = array($block(106));
$mismatch = $validator->validate(10, 'channel-mismatch');
$assert(false === $mismatch['ready'] && in_array('channel', $codes($mismatch), true), 'Destination/plan channel mismatch did not block publication.');

$video(107, 99);
$GLOBALS['awvp_pub_meta'][107][Video_Meta::DESTINATION] = array('broken'=>true);
$GLOBALS['awvp_pub_blocks']['reused'] = array($block(107));
$assert(true === $validator->validate(10, 'reused')['ready'], 'Reused block incorrectly made the second post publication-authoritative.');

$video(108);
$GLOBALS['awvp_pub_meta'][108][Video_Meta::DESTINATION] = array('version'=>1,'backend_id'=>'pt-main','channel_id'=>'7');
$GLOBALS['awvp_pub_meta'][108][Video_Meta::PEERTUBE_PUBLICATION_PLAN] = $plan();
$GLOBALS['awvp_pub_blocks']['nested'] = array(array('blockName'=>'core/group','attrs'=>array(),'innerBlocks'=>array($block(108))));
$assert(true === $validator->validate(10, 'nested')['ready'], 'Nested ready AWVP block was not discovered correctly.');

$video(109);
$GLOBALS['awvp_pub_meta'][109][Video_Meta::DESTINATION] = array('version'=>1,'backend_id'=>'pt-main','channel_id'=>'7');
$GLOBALS['awvp_pub_meta'][109][Video_Meta::PEERTUBE_PUBLICATION_PLAN] = $plan(10, 'pt-main', '7', array('tags'=>false));
$GLOBALS['awvp_pub_posts'][500] = (object) array('ID'=>500,'post_type'=>'wp_block','post_status'=>'publish','post_content'=>'ref-content');
$GLOBALS['awvp_pub_blocks']['sync'] = array(array('blockName'=>'core/block','attrs'=>array('ref'=>500),'innerBlocks'=>array()));
$GLOBALS['awvp_pub_blocks']['ref-content'] = array($block(109));
$synced = $validator->validate(10, 'sync');
$assert(false === $synced['ready'] && in_array('tags', $codes($synced), true), 'Referenced wp_block content bypassed review validation.');

$video(110);
$GLOBALS['awvp_pub_meta'][110][Video_Meta::DESTINATION] = array('bad'=>true);
$GLOBALS['awvp_pub_blocks']['bad-destination'] = array($block(110));
$bad_destination = $validator->validate(10, 'bad-destination');
$assert(false === $bad_destination['ready'] && in_array('destination', $codes($bad_destination), true), 'Malformed present destination did not fail closed.');

$video(111);
$GLOBALS['awvp_pub_meta'][111][Video_Meta::DESTINATION] = array('version'=>1,'backend_id'=>'pt-main','channel_id'=>'7');
$GLOBALS['awvp_pub_meta'][111][Video_Meta::PEERTUBE_PUBLICATION_PLAN] = array('version'=>99,'future'=>true);
$GLOBALS['awvp_pub_blocks']['bad-plan'] = array($block(111));
$bad_plan = $validator->validate(10, 'bad-plan');
$assert(false === $bad_plan['ready'] && in_array('invalid_plan', $codes($bad_plan), true), 'Malformed/future publication plan did not fail closed.');

// Scanner bounds are safety limits, not bypasses. A block graph that exceeds
// the reviewed nesting depth must fail closed instead of silently ignoring
// whatever may exist beyond the boundary.
$deep = $block(105);
for ($depth = 0; $depth < 35; ++$depth) {
    $deep = array('blockName'=>'core/group','attrs'=>array(),'innerBlocks'=>array($deep));
}
$GLOBALS['awvp_pub_blocks']['too-deep'] = array($deep);
$too_deep = $validator->validate(10, 'too-deep');
$assert(false === $too_deep['ready'] && in_array('block_structure', $codes($too_deep), true), 'Over-depth block graph bypassed AWVP publication validation.');

$source = (string) file_get_contents(dirname(__DIR__) . '/includes/Editorial_Publish_Validator.php');
foreach (array('wp_remote_', 'PeerTube_Api_Client', 'PeerTube_Publication_Catalog', 'PeerTube_Task', 'PeerTube_Publication_Catalog_Store', 'Task_Repository') as $forbidden) {
    $assert(! str_contains($source, $forbidden), 'Editorial validator acquired remote/readiness authority: ' . $forbidden);
}

fwrite(STDOUT, "R46 editorial publish validator tests passed.\n");
