<?php
/** Focused dependency-free tests for the R46.3b dynamic AWVP Video block. */
declare(strict_types=1);

const ARGENT_VIDEO_DIR = __DIR__ . '/../';

$GLOBALS['awvp_block_registered'] = array();
$GLOBALS['awvp_block_posts'] = array();
$GLOBALS['awvp_block_meta'] = array();

function register_block_type(string $path, array $args = array()): object|false
{
    $GLOBALS['awvp_block_registered'][] = array($path, $args);
    return (object) array('name' => 'argentwolf-video-processor/video');
}
function get_post(int $id): object|false { return $GLOBALS['awvp_block_posts'][$id] ?? false; }
function get_post_meta(int $id, string $key, bool $single = false): mixed
{
    $value = $GLOBALS['awvp_block_meta'][$id][$key] ?? ($single ? '' : array());
    return $single ? $value : array($value);
}
function get_post_mime_type(int $id): string|false
{
    $post = $GLOBALS['awvp_block_posts'][$id] ?? null;
    return is_object($post) ? (string) ($post->post_mime_type ?? '') : false;
}
function wp_get_attachment_url(int $id): string|false
{
    $post = $GLOBALS['awvp_block_posts'][$id] ?? null;
    return is_object($post) ? (string) ($post->url ?? '') : false;
}
function get_block_wrapper_attributes(array $extra = array()): string
{
    return 'class="wp-block-argentwolf-video-processor-video" data-awvp-video-id="' . htmlspecialchars((string) ($extra['data-awvp-video-id'] ?? ''), ENT_QUOTES) . '"';
}
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function __(string $value, string $domain = ''): string { unset($domain); return $value; }
function get_the_title(int $id): string { return 'Video ' . $id; }
function sanitize_key(mixed $value): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)) ?? '';
}
function sanitize_text_field(mixed $value): string { return trim(strip_tags((string) $value)); }

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/Video_Post_Type.php';
require_once dirname(__DIR__) . '/includes/Video_Meta.php';
require_once dirname(__DIR__) . '/includes/Video_Serving_Resolver.php';
require_once dirname(__DIR__) . '/includes/Video_Block.php';

use ArgentVideo\Video_Block;
use ArgentVideo\Video_Meta;
use ArgentVideo\Video_Post_Type;
use ArgentVideo\Video_Serving_Resolver;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

$block = new Video_Block();
$block->register();
$assert(1 === count($GLOBALS['awvp_block_registered']), 'AWVP block did not register exactly once.');
[$path, $args] = $GLOBALS['awvp_block_registered'][0];
$assert(realpath(dirname(__DIR__) . '/blocks/video') === realpath($path), 'AWVP block did not register from canonical block.json directory.');
$assert(isset($args['render_callback']) && is_array($args['render_callback']), 'AWVP block did not register a dynamic render callback.');

$GLOBALS['awvp_block_posts'][101] = (object) array('ID'=>101,'post_type'=>Video_Post_Type::POST_TYPE,'post_status'=>'publish');
$GLOBALS['awvp_block_posts'][20] = (object) array(
    'ID'=>20,'post_type'=>'attachment','post_status'=>'inherit','post_mime_type'=>'video/mp4','url'=>'https://example.test/uploads/local.mp4',
);
$GLOBALS['awvp_block_meta'][101][Video_Meta::ATTACHMENT_ID] = 20;
$GLOBALS['awvp_block_meta'][101][Video_Meta::DESTINATION] = array('version'=>1,'backend_id'=>'pt-primary','channel_id'=>'41');

$html = $block->render(array('videoId'=>101));
$assert(str_contains($html, 'data-awvp-video-id="101"'), 'Rendered AWVP block lost stable AWVP Video ID.');
$assert(str_contains($html, 'https://example.test/uploads/local.mp4'), 'Remote destination changed the local serving source prematurely.');
$assert(str_contains($html, '<video controls playsinline preload="metadata">'), 'Dynamic block did not render the AWVP-owned native local player.');
$assert(! str_contains(strtolower($html), 'autoplay'), 'AWVP local player unexpectedly enables autoplay.');

final class Awvp_Block_Remote_Serving implements Video_Serving_Resolver { public function peertube_embed_url(int $video_id): string { return 101 === $video_id ? 'https://video.example.org/videos/embed/123e4567-e89b-42d3-a456-426614174000' : ''; } }
$remote_block = new Video_Block(new Awvp_Block_Remote_Serving());
$remote_html = $remote_block->render(array('videoId'=>101));
$assert(str_contains($remote_html, '<iframe') && str_contains($remote_html, 'video.example.org/videos/embed/123e4567-e89b-42d3-a456-426614174000'), 'Verified serving resolver did not switch the AWVP block to PeerTube embed output.');
$assert(! str_contains(strtolower($remote_html), 'autoplay'), 'PeerTube embed unexpectedly enables autoplay.');
$assert(! str_contains($remote_html, 'allow="autoplay'), 'PeerTube iframe grants autoplay capability.');
$GLOBALS['awvp_block_posts'][20]->url = '';
$GLOBALS['awvp_block_posts'][20]->post_mime_type = 'application/octet-stream';
$remote_without_source = $remote_block->render(array('videoId'=>101));
$assert(str_contains($remote_without_source, '<iframe') && str_contains($remote_without_source, 'video.example.org/videos/embed/'), 'Verified PeerTube serving incorrectly depends on readable local source bytes.');
$GLOBALS['awvp_block_posts'][20]->url = 'https://example.test/uploads/local.mp4';
$GLOBALS['awvp_block_posts'][20]->post_mime_type = 'video/mp4';

$assert('' === $block->render(array()), 'Unbound block unexpectedly rendered frontend output.');
$assert('' === $block->render(array('videoId'=>999)), 'Unknown AWVP Video unexpectedly rendered frontend output.');
$GLOBALS['awvp_block_posts'][20]->post_mime_type = 'image/jpeg';
$assert('' === $block->render(array('videoId'=>101)), 'Non-video attachment was rendered through AWVP Video block.');

$metadata = json_decode((string) file_get_contents(dirname(__DIR__) . '/blocks/video/block.json'), true);
$assert(is_array($metadata), 'Canonical AWVP block.json is not valid JSON.');
$assert(Video_Block::NAME === ($metadata['name'] ?? null), 'block.json name drifted from PHP registration identity.');
$assert(array('videoId'=>array('type'=>'integer','default'=>0)) === ($metadata['attributes'] ?? null), 'Serialized block acquired state beyond stable AWVP Video ID.');
$assert('file:./index.js' === ($metadata['editorScript'] ?? null), 'AWVP block editorScript metadata drifted.');
$asset = require dirname(__DIR__) . '/blocks/video/index.asset.php';
$assert(is_array($asset), 'AWVP block dependency manifest is invalid.');
$assert(
    array('wp-api-fetch','wp-block-editor','wp-blocks','wp-components','wp-data','wp-element','wp-i18n') === ($asset['dependencies'] ?? null),
    'AWVP block dependency manifest drifted from reviewed WordPress-package surface.'
);
$expected_script_version = substr(hash_file('sha256', dirname(__DIR__) . '/blocks/video/index.js'), 0, 16);
$assert($expected_script_version === ($asset['version'] ?? null), 'AWVP block script version does not match shipped editor bytes.');

$js = (string) file_get_contents(dirname(__DIR__) . '/blocks/video/index.js');
$assert(str_contains($js, "setAttributes({ videoId: Number(response.video.id) })"), 'Editor does not persist stable AWVP Video ID after binding.');
$assert(str_contains($js, "value: '',\n                disabled: true"), 'Invalid stored destination has no explicit unresolved selector state.');
$assert(str_contains($js, "videoId < 1 || saving || value === ''"), 'Unresolved selector value can trigger a destination mutation.');
$assert(str_contains($js, "const draftDirty = JSON.stringify(publicationDraft) !== JSON.stringify(persistedDraft)"), 'Unsaved publication edits can masquerade as persisted ready state.');
$assert(str_contains($js, "publication.plan_status === 'channel_mismatch'"), 'Publication wizard does not surface destination/plan channel mismatch.');
$assert(str_contains($js, "lockPostSaving(editorialLockName)") && str_contains($js, "unlockPostSaving(editorialLockName)"), 'Editor does not lock only the publicational save boundary while review is unresolved.');
$assert(str_contains($js, "['publish', 'future', 'private']"), 'Editor publication lock does not cover publish/schedule/private transitions.');
$assert(str_contains($js, "origin_post_id"), 'Editor publication lock cannot distinguish the original anchor from reused blocks.');
$assert(str_contains($js, 'tagsDraftText') && str_contains($js, 'setTagsDraftText(value)'), 'PeerTube tag textarea does not preserve raw in-progress spaces/newlines.');
$assert(1 === substr_count($js, "label: __('I reviewed these PeerTube publishing settings'"), 'Publication wizard does not expose one consolidated explicit-review checkbox.');
foreach (array('I reviewed the PeerTube title','I reviewed the channel','I reviewed the PeerTube tags','I reviewed the final privacy','I reviewed the sensitive-content declaration') as $old_review_label) {
    $assert(! str_contains($js, $old_review_label), 'Publication wizard retained a superseded per-field explicit-review checkbox: ' . $old_review_label);
}
$assert(str_contains($js, 'Publishing options have not been loaded from this PeerTube server yet.'), 'Missing-provider-catalog editor notice is not actionable/user-facing.');
foreach (array('access_token','refresh_token','secret_ref','PeerTube_Api_Client','peertube_upload_advance',"wp.data.dispatch('core/editor').savePost") as $forbidden) {
    $assert(! str_contains($js, $forbidden), 'Block editor acquired forbidden secret/dispatch/editor-publish authority: ' . $forbidden);
}

$bootstrap = (string) file_get_contents(dirname(__DIR__) . '/argentwolf-video-processor.php');
$plugin = (string) file_get_contents(dirname(__DIR__) . '/includes/Plugin.php');
$build = (string) file_get_contents(dirname(__DIR__) . '/build/build-plugin.sh');
$assert(str_contains($bootstrap, "includes/Video_Block.php") && str_contains($bootstrap, "includes/Video_Block_Editor_Rest.php"), 'Block/editor REST classes are not loaded by plugin bootstrap.');
$assert(str_contains($bootstrap, "includes/PeerTube_Publication_Editor_Service.php") && str_contains($bootstrap, "includes/PeerTube_Publication_Editor_Rest.php"), 'Publication editor classes are not loaded by plugin bootstrap.');
$assert(str_contains($bootstrap, "includes/Editorial_Publish_Validator.php") && str_contains($bootstrap, "includes/Editorial_Publish_Gate.php"), 'Editorial publication gate classes are not loaded by plugin bootstrap.');
$assert(str_contains($bootstrap, "includes/Video_Serving_Authority.php") && str_contains($bootstrap, "includes/Video_Serving_Service.php") && str_contains($bootstrap, "includes/PeerTube_Serving_Cutover_Service.php"), 'R46.6 serving/cutover classes are not loaded by plugin bootstrap.');
$block_source = (string) file_get_contents(dirname(__DIR__) . '/includes/Video_Block.php');
$assert(str_contains($block_source, 'peertube_embed_url') && str_contains($block_source, '<iframe'), 'AWVP block has no verified PeerTube serving path.');
$assert(! str_contains($block_source, 'wp_video_shortcode'), 'AWVP block still layers hls.js on WordPress MediaElement shortcode output.');
$assert(str_contains($block_source, 'render_attachment_player'), 'AWVP block does not use the shared AWVP native local-player renderer.');
foreach (array('wp_remote_', 'PeerTube_Api_Client', 'update_publication', 'update_privacy') as $forbidden) { $assert(! str_contains($block_source, $forbidden), 'Frontend block acquired provider-network/mutation authority: ' . $forbidden); }
$renderer_source = (string) file_get_contents(dirname(__DIR__) . '/includes/Renderer.php');
$player_source = (string) file_get_contents(dirname(__DIR__) . '/assets/js/argent-video-player.js');
$assert(str_contains($renderer_source, 'data-argent-fallback') && str_contains($player_source, "getAttribute('data-argent-fallback')"), 'HLS player lacks an explicit generated progressive fallback after fatal adaptive playback failure.');
$assert(str_contains($renderer_source, 'autoplay'), 'Renderer does not explicitly remove inherited autoplay attributes.');
$assert(str_contains($plugin, 'new Video_Block($video_serving, $renderer)'), 'Production block wiring does not inject the AWVP native renderer.');
$assert(str_contains($plugin, "add_action('init', array(\$video_block, 'register'), 7)"), 'Dynamic AWVP block is not registered from Plugin boot.');
$assert(str_contains($plugin, "add_action('rest_api_init', array(\$video_block_editor_rest, 'register'))"), 'AWVP block REST boundary is not registered from Plugin boot.');
$assert(str_contains($plugin, "add_action('rest_api_init', array(\$peertube_publication_editor_rest, 'register'))"), 'PeerTube publication editor REST boundary is not registered from Plugin boot.');
$assert(str_contains($plugin, "\$editorial_publish_gate->register();"), 'Editorial publication gate is not registered from Plugin boot.');
$assert(str_contains($build, 'rsync -a "${ROOT_DIR}/blocks/" "${STAGE_DIR}/blocks/"'), 'Release builder does not ship Gutenberg block assets.');

fwrite(STDOUT, "R46 dynamic AWVP Video block tests passed.\n");
