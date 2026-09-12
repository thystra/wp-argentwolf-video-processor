<?php
/**
 * Focused R46.1 destination and PeerTube publication-plan model tests.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
require_once dirname(__DIR__) . '/includes/Video_Destination.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Plan.php';

use ArgentVideo\Backend_Registry;
use ArgentVideo\PeerTube_Publication_Plan;
use ArgentVideo\Video_Destination;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$local = Video_Destination::local();
$assert(
    array('version' => 1, 'backend_id' => Backend_Registry::LOCAL_ID) === $local,
    'Canonical local destination changed.'
);
$assert(
    $local === Video_Destination::resolve(null, false),
    'Missing legacy destination metadata must resolve to WordPress/local.'
);
$assert(
    array() === Video_Destination::resolve(array('version' => 1, 'backend_id' => 'BAD ID'), true),
    'Malformed present destination metadata must fail closed rather than silently become local.'
);
$assert(
    array('version' => 1, 'backend_id' => 'home-pt', 'channel_id' => '123')
        === Video_Destination::sanitize(array('version' => 1, 'backend_id' => 'home-pt', 'channel_id' => '123')),
    'Canonical remote destination changed.'
);
$assert(
    array() === Video_Destination::sanitize(array('version' => 1, 'backend_id' => 'local', 'channel_id' => '123')),
    'Local destination must reject a remote channel identifier.'
);
$assert(Video_Destination::is_local($local), 'Local destination detection failed.');

$base_plan = array(
    'version'                 => 1,
    'backend_id'              => 'home-pt',
    'channel_id'              => '123',
    'title'                   => 'Farm Tour',
    'description_markdown'    => "A **reviewed** PeerTube description.\n",
    'tags'                    => array('Farm', 'Colorado'),
    'support'                 => array('mode' => 'preset', 'preset_id' => 'wolf-raven', 'markdown' => ''),
    'final_privacy_id'        => '1',
    'licence_id'              => '2',
    'category_id'             => '15',
    'language'                => 'en',
    'thumbnail_attachment_id' => 44,
    'download_enabled'        => true,
    'originally_published_at' => '2026-08-01T12:34:56Z',
    'comments_policy'         => 'enabled',
    'moderation'              => array(
        'reviewed'          => true,
        'sensitive'         => false,
        'reason'            => '',
        'violent'           => false,
        'sexually_explicit' => false,
    ),
    'embed'                   => array('restricted' => false, 'domains' => array()),
    'review'                  => array(
        'title'      => true,
        'channel'    => true,
        'tags'       => true,
        'privacy'    => true,
        'moderation' => true,
    ),
    'dispatch_policy'         => PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,
    'release_policy'          => PeerTube_Publication_Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,
    'anchor_post_id'          => 99,
);

$sanitized = PeerTube_Publication_Plan::sanitize($base_plan);
$assert(array() !== $sanitized, 'Valid PeerTube publication plan was rejected.');
$assert(PeerTube_Publication_Plan::ready_for_dispatch($sanitized), 'Fully reviewed plan should be dispatch-ready.');
$assert(array() === PeerTube_Publication_Plan::missing_review($sanitized), 'Ready plan reported missing review.');
$assert(array('Farm', 'Colorado') === ($sanitized['tags'] ?? null), 'PeerTube tags were silently rewritten.');
$assert('when_wordpress_published' === ($sanitized['release_policy'] ?? ''), 'Release policy changed.');
$assert(! array_key_exists('pre_publish_privacy_id', $sanitized), 'Legacy version-1 plan gained a field that would change its existing lifecycle hash.');
$unlisted_stage = $base_plan; $unlisted_stage['pre_publish_privacy_id'] = PeerTube_Publication_Plan::PRE_PUBLISH_UNLISTED;
$unlisted_stage = PeerTube_Publication_Plan::sanitize($unlisted_stage);
$assert(PeerTube_Publication_Plan::PRE_PUBLISH_UNLISTED === PeerTube_Publication_Plan::pre_publish_privacy_id($unlisted_stage), 'Reviewed Unlisted pre-publication visibility was not preserved.');
$bad_stage = $base_plan; $bad_stage['pre_publish_privacy_id'] = '1';
$assert(array() === PeerTube_Publication_Plan::sanitize($bad_stage), 'Pre-publication visibility allowed a value other than Private or Unlisted.');

$zero_tag_plan = $base_plan;
$zero_tag_plan['tags'] = array();
$assert(
    PeerTube_Publication_Plan::ready_for_dispatch(PeerTube_Publication_Plan::sanitize($zero_tag_plan)),
    'Explicitly reviewed zero-tag video must be allowed.'
);

$too_many_tags = $base_plan;
$too_many_tags['tags'] = array('one', 'two', 'three', 'four', 'five', 'six');
$assert(array() === PeerTube_Publication_Plan::sanitize($too_many_tags), 'More than five PeerTube tags must fail closed.');

$short_title = $base_plan;
$short_title['title'] = 'Hi';
$assert(array() === PeerTube_Publication_Plan::sanitize($short_title), 'PeerTube title shorter than three characters must fail closed.');

$bad_date = $base_plan;
$bad_date['originally_published_at'] = '2026-02-31T12:34:56Z';
$assert(array() === PeerTube_Publication_Plan::sanitize($bad_date), 'Invalid original publication timestamp must fail closed.');

$duplicate_tags = $base_plan;
$duplicate_tags['tags'] = array('Farm', 'farm');
$assert(array() === PeerTube_Publication_Plan::sanitize($duplicate_tags), 'PeerTube tags must be unique case-insensitively.');

$unreviewed_tags = $base_plan;
$unreviewed_tags['review']['tags'] = false;
$unreviewed_tags = PeerTube_Publication_Plan::sanitize($unreviewed_tags);
$assert(array() !== $unreviewed_tags, 'Unreviewed draft plan should remain persistable.');
$assert(! PeerTube_Publication_Plan::ready_for_dispatch($unreviewed_tags), 'Unreviewed tags must block remote dispatch readiness.');
$assert(array('tags') === PeerTube_Publication_Plan::missing_review($unreviewed_tags), 'Missing tag review was not reported exactly.');

$unreviewed_sensitive = $base_plan;
$unreviewed_sensitive['moderation']['reviewed'] = false;
$unreviewed_sensitive = PeerTube_Publication_Plan::sanitize($unreviewed_sensitive);
$assert(array() !== $unreviewed_sensitive, 'Unreviewed moderation draft should remain persistable.');
$assert(! PeerTube_Publication_Plan::ready_for_dispatch($unreviewed_sensitive), 'Unreviewed sensitive-content declaration must block remote dispatch readiness.');
$assert(array('moderation') === PeerTube_Publication_Plan::missing_review($unreviewed_sensitive), 'Missing moderation review was not reported exactly.');

$sensitive = $base_plan;
$sensitive['moderation'] = array(
    'reviewed'          => true,
    'sensitive'         => true,
    'reason'            => 'Contains discussion of an injury.',
    'violent'           => true,
    'sexually_explicit' => false,
);
$assert(array() !== PeerTube_Publication_Plan::sanitize($sensitive), 'Reviewed sensitive-content metadata was rejected.');

$bad_nonsensitive = $base_plan;
$bad_nonsensitive['moderation']['violent'] = true;
$assert(array() === PeerTube_Publication_Plan::sanitize($bad_nonsensitive), 'Non-sensitive plan must not retain sensitive labels.');

$restricted_embed = $base_plan;
$restricted_embed['embed'] = array('restricted' => true, 'domains' => array('example.com', 'video.example.org'));
$assert(array() !== PeerTube_Publication_Plan::sanitize($restricted_embed), 'Valid restricted-embed policy was rejected.');

$bad_embed = $base_plan;
$bad_embed['embed'] = array('restricted' => true, 'domains' => array('https://example.com'));
$assert(array() === PeerTube_Publication_Plan::sanitize($bad_embed), 'Embed-domain policy must reject schemes/URLs.');

$send_now = $base_plan;
$send_now['dispatch_policy'] = PeerTube_Publication_Plan::DISPATCH_SEND_NOW;
$assert(array() !== PeerTube_Publication_Plan::sanitize($send_now), 'Explicit send-now dispatch policy was rejected.');

$local_plan = $base_plan;
$local_plan['backend_id'] = Backend_Registry::LOCAL_ID;
$assert(array() === PeerTube_Publication_Plan::sanitize($local_plan), 'PeerTube publication plan must not target local backend.');

fwrite(STDOUT, "R46 destination/publication-plan tests passed.\n");

// EOF: tests/video-destination-publication-plan.php
