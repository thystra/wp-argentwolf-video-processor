<?php
/** Focused dependency-free tests for the R46.3b dynamic AWVP Video block. */
declare(strict_types=1);

const ARGENT_VIDEO_DIR = __DIR__ . '/../';

$GLOBALS['awvp_block_registered'] = array();
$GLOBALS['awvp_block_posts'] = array();
$GLOBALS['awvp_block_meta'] = array();
$GLOBALS['awvp_block_shortcode_calls'] = array();

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
function wp_video_shortcode(array $atts): string
{
    $GLOBALS['awvp_block_shortcode_calls'][] = $atts;
    return '<video src="' . htmlspecialchars((string) ($atts['src'] ?? ''), ENT_QUOTES) . '"></video>';
}
function get_block_wrapper_attributes(array $extra = array()): string
{
    return 'class="wp-block-argentwolf-video-processor-video" data-awvp-video-id="' . htmlspecialchars((string) ($extra['data-awvp-video-id'] ?? ''), ENT_QUOTES) . '"';
}
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function sanitize_key(mixed $value): string
{
    return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)) ?? '';
}
function sanitize_text_field(mixed $value): string { return trim(strip_tags((string) $value)); }

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/Video_Post_Type.php';
require_once dirname(__DIR__) . '/includes/Video_Meta.php';
require_once dirname(__DIR__) . '/includes/Video_Block.php';

use ArgentVideo\Video_Block;
use ArgentVideo\Video_Meta;
use ArgentVideo\Video_Post_Type;

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
$assert(1 === count($GLOBALS['awvp_block_shortcode_calls']), 'Dynamic block bypassed the established WordPress video shortcode compatibility path.');
$assert('metadata' === ($GLOBALS['awvp_block_shortcode_calls'][0]['preload'] ?? ''), 'AWVP block local player preload policy drifted.');

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
foreach (array('access_token','refresh_token','secret_ref','PeerTube_Api_Client','peertube_upload_advance',"wp.data.dispatch('core/editor').savePost") as $forbidden) {
    $assert(! str_contains($js, $forbidden), 'Block editor acquired forbidden secret/dispatch/editor-publish authority: ' . $forbidden);
}

$bootstrap = (string) file_get_contents(dirname(__DIR__) . '/argentwolf-video-processor.php');
$plugin = (string) file_get_contents(dirname(__DIR__) . '/includes/Plugin.php');
$build = (string) file_get_contents(dirname(__DIR__) . '/build/build-plugin.sh');
$assert(str_contains($bootstrap, "includes/Video_Block.php") && str_contains($bootstrap, "includes/Video_Block_Editor_Rest.php"), 'Block/editor REST classes are not loaded by plugin bootstrap.');
$assert(str_contains($plugin, "add_action('init', array(\$video_block, 'register'), 7)"), 'Dynamic AWVP block is not registered from Plugin boot.');
$assert(str_contains($plugin, "add_action('rest_api_init', array(\$video_block_editor_rest, 'register'))"), 'AWVP block REST boundary is not registered from Plugin boot.');
$assert(str_contains($build, 'rsync -a "${ROOT_DIR}/blocks/" "${STAGE_DIR}/blocks/"'), 'Release builder does not ship Gutenberg block assets.');

fwrite(STDOUT, "R46 dynamic AWVP Video block tests passed.\n");
