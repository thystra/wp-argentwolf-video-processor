<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

global $wpdb;

awvp_rc_assert_candidate_common();

$base_version = awvp_release_env('AWVP_TEST_BASE_VERSION');
$expected_settings_hash = (string) get_option('awvp_rc_expected_settings_hash', '');
$attachment_id = (int) get_option('awvp_rc_legacy_attachment_id', 0);
$post_id = (int) get_option('awvp_rc_legacy_post_id', 0);
$job_id = (int) get_option('awvp_rc_legacy_job_id', 0);
$source = (string) get_option('awvp_rc_legacy_source_path', '');
$source_sha = (string) get_option('awvp_rc_legacy_source_sha256', '');
$managed = (string) get_option('awvp_rc_legacy_managed_path', '');
$managed_url = (string) get_option('awvp_rc_legacy_managed_url', '');
$managed_sha = (string) get_option('awvp_rc_legacy_managed_sha256', '');
$expected_content = (string) get_option('awvp_rc_legacy_post_content', '');
$expected_content_sha = (string) get_option('awvp_rc_legacy_post_content_sha256', '');
$expected_meta_sha = (string) get_option('awvp_rc_legacy_attachment_meta_sha256', '');
$signature = (string) get_option('awvp_rc_legacy_signature', '');

awvp_release_assert('' !== $expected_settings_hash, '1.0 settings snapshot is missing.');
awvp_release_assert($attachment_id > 0 && $post_id > 0 && $job_id > 0, '1.0 fixture IDs are missing.');
awvp_release_assert('' !== $source && '' !== $managed && '' !== $expected_content, '1.0 file/content fixtures are missing.');

$raw_settings = get_option(\ArgentVideo\Settings::OPTION, array());
awvp_release_assert(
    hash('sha256', wp_json_encode($raw_settings)) === $expected_settings_hash,
    "Saved {$base_version} settings were rewritten during the 2.0 upgrade."
);

$post = get_post($post_id);
awvp_release_assert(is_object($post), 'Legacy Core Video post disappeared during upgrade.');
awvp_release_assert(
    $expected_content === (string) $post->post_content,
    '2.0 mutated stored legacy core/video post_content during upgrade.'
);
awvp_release_assert(
    $expected_content_sha === hash('sha256', (string) $post->post_content),
    'Legacy core/video post_content hash changed during upgrade.'
);

$parsed = parse_blocks((string) $post->post_content);
awvp_release_assert(1 === count($parsed), 'Legacy post no longer parses as exactly one block.');
awvp_release_assert('core/video' === ($parsed[0]['blockName'] ?? ''), 'Legacy block was converted away from core/video.');
awvp_release_assert(
    $attachment_id === (int) ($parsed[0]['attrs']['id'] ?? 0),
    'Legacy core/video attachment relationship changed during upgrade.'
);

$attachment = get_post($attachment_id);
awvp_release_assert(
    is_object($attachment)
    && 'attachment' === ($attachment->post_type ?? null)
    && 'video/mp4' === get_post_mime_type($attachment_id),
    'Legacy video attachment object changed during upgrade.'
);
awvp_release_assert(
    $source === wp_normalize_path((string) get_attached_file($attachment_id, true)),
    'Legacy attachment source path changed during upgrade.'
);
awvp_release_assert(is_file($source), 'Legacy attachment source disappeared during upgrade.');
awvp_release_assert($source_sha === hash_file('sha256', $source), 'Legacy attachment source bytes changed during upgrade.');
awvp_release_assert(is_file($managed), 'Legacy managed derivative disappeared during upgrade.');
awvp_release_assert($managed_sha === hash_file('sha256', $managed), 'Legacy managed derivative bytes changed during upgrade.');
awvp_release_assert(
    $expected_meta_sha === awvp_rc_post_meta_hash($attachment_id),
    '2.0 mutated legacy attachment metadata merely because the plugin was upgraded.'
);

$jobs = $wpdb->prefix . 'argent_video_jobs';
$job = $wpdb->get_row($wpdb->prepare("SELECT * FROM `{$jobs}` WHERE id = %d", $job_id), ARRAY_A);
awvp_release_assert(is_array($job), 'Completed 1.0 queue record disappeared during upgrade.');
awvp_release_assert($attachment_id === (int) $job['attachment_id'], 'Legacy queue attachment ID changed.');
awvp_release_assert($source === wp_normalize_path((string) $job['source_path']), 'Legacy queue source path changed.');
awvp_release_assert($signature === (string) $job['source_signature'], 'Legacy queue signature changed.');
awvp_release_assert('compatibility' === (string) $job['profile'], 'Legacy queue profile changed.');
awvp_release_assert('complete' === (string) $job['status'], 'Legacy completed queue row changed status.');

awvp_release_assert(
    0 === (int) $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM `{$wpdb->posts}` WHERE post_type = %s",
            \ArgentVideo\Video_Post_Type::POST_TYPE
        )
    ),
    '2.0 mass-created AWVP Video objects from legacy core/video content during upgrade.'
);
awvp_release_assert(
    ! metadata_exists('post', $attachment_id, \ArgentVideo\Video_Block_Editor_Service::ATTACHMENT_ASSET_META),
    '2.0 silently adopted the legacy core/video attachment during upgrade.'
);

$remote_table = $wpdb->prefix . \ArgentVideo\Model_Activator::REMOTE_ASSETS_TABLE;
$tasks_table = $wpdb->prefix . \ArgentVideo\Model_Activator::TASKS_TABLE;
awvp_release_assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$remote_table}`"), 'Upgrade created remote assets without operator action.');
awvp_release_assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tasks_table}`"), 'Upgrade created PeerTube tasks without operator action.');

$rendered = do_blocks((string) $post->post_content);
awvp_release_assert(
    str_contains($rendered, esc_url($managed_url)),
    'Legacy processed core/video no longer renders its existing local derivative after upgrade.'
);
$post_after_render = get_post($post_id);
awvp_release_assert(
    is_object($post_after_render) && $expected_content === (string) $post_after_render->post_content,
    'Rendering the legacy core/video mutated stored post content.'
);

echo "AWVP_RC_1_0_TO_2_0_UPGRADE_PRESERVATION_PASS\n";
