<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

global $wpdb;

$base_version = awvp_release_env('AWVP_TEST_BASE_VERSION');
$base_db = awvp_release_env('AWVP_TEST_BASE_DB_VERSION');

awvp_release_assert(defined('ARGENT_VIDEO_VERSION'), 'Base plugin version constant missing.');
awvp_release_assert($base_version === ARGENT_VIDEO_VERSION, "Upgrade base is not {$base_version}.");
awvp_release_assert(
    $base_db === (string) get_option(\ArgentVideo\Activator::DB_OPTION, ''),
    "Base DB schema-version option is not {$base_db}."
);
awvp_release_assert(
    ! class_exists(\ArgentVideo\Model_Activator::class, false),
    '1.0 upgrade base unexpectedly loaded the 2.0 model activator.'
);
awvp_release_assert(
    false === get_option('argent_video_processor_model_db_version', false),
    '1.0 upgrade base unexpectedly has the 2.0 model DB version option.'
);
foreach (array('argent_video_remote_assets', 'argent_video_tasks') as $suffix) {
    awvp_release_assert(
        ! awvp_release_table_exists($wpdb->prefix . $suffix),
        "1.0 upgrade base unexpectedly has {$suffix}."
    );
}

$settings = \ArgentVideo\Settings::all();
$settings['auto_queue'] = false;
$settings['auto_dispatch'] = false;
$settings['max_width'] = 1024;
$settings['max_height'] = 576;
$settings['profile'] = 'compatibility';
$settings['nice_level'] = 13;
update_option(\ArgentVideo\Settings::OPTION, $settings, false);
update_option(
    'awvp_rc_expected_settings_hash',
    hash('sha256', wp_json_encode(get_option(\ArgentVideo\Settings::OPTION, array()))),
    false
);

$uploads = wp_upload_dir();
awvp_release_assert(is_array($uploads) && empty($uploads['error']), 'WordPress uploads are unavailable.');
$base_dir = rtrim(wp_normalize_path((string) $uploads['basedir']), '/');
$base_url = rtrim((string) $uploads['baseurl'], '/');
$fixture_dir = $base_dir . '/awvp-release-validation';
awvp_release_assert(wp_mkdir_p($fixture_dir), 'Could not create legacy fixture uploads directory.');

$source = $fixture_dir . '/' . wp_unique_filename($fixture_dir, 'legacy-core-video.mp4');
$source_bytes = "AWVP 1.0 legacy core/video source fixture\n";
awvp_release_assert(false !== file_put_contents($source, $source_bytes), 'Could not create legacy source fixture.');
$source = wp_normalize_path($source);
$relative_source = ltrim(substr($source, strlen($base_dir)), '/');
$source_url = $base_url . '/' . implode('/', array_map('rawurlencode', explode('/', $relative_source)));

$attachment_id = wp_insert_post(
    array(
        'post_type'      => 'attachment',
        'post_status'    => 'inherit',
        'post_title'     => 'AWVP 1.0 Legacy Core Video',
        'post_mime_type' => 'video/mp4',
        'guid'           => $source_url,
    ),
    true
);
awvp_release_assert(! is_wp_error($attachment_id) && (int) $attachment_id > 0, 'Could not create legacy video attachment.');
$attachment_id = (int) $attachment_id;
update_attached_file($attachment_id, $source);
wp_update_attachment_metadata(
    $attachment_id,
    array(
        'file'   => $relative_source,
        'width'  => 1280,
        'height' => 720,
    )
);

$managed_dir = \ArgentVideo\Storage::ensure_attachment_directory($attachment_id);
$managed_output = \ArgentVideo\Storage::assert_managed_path($managed_dir . '/legacy-processed.mp4');
\ArgentVideo\Storage::write_file($managed_output, "AWVP 1.0 managed derivative fixture\n");
$managed_url = \ArgentVideo\Storage::url_for_path($managed_output);
$outputs = array(
    'mp4' => array(
        'path' => $managed_output,
        'url'  => $managed_url,
        'mime' => 'video/mp4',
    ),
);

$signature = hash(
    'sha256',
    wp_normalize_path($source) . '|' . filesize($source) . '|' . filemtime($source)
);
update_post_meta($attachment_id, '_argent_video_status', 'complete');
update_post_meta($attachment_id, '_argent_video_source_signature', $signature);
update_post_meta($attachment_id, '_argent_video_outputs', $outputs);

$jobs = $wpdb->prefix . 'argent_video_jobs';
$now = current_time('mysql', true);
$inserted = $wpdb->insert(
    $jobs,
    array(
        'attachment_id'   => $attachment_id,
        'source_path'     => $source,
        'source_signature'=> $signature,
        'profile'         => 'compatibility',
        'status'          => 'complete',
        'attempts'        => 1,
        'started_at'      => $now,
        'completed_at'    => $now,
        'output_json'     => wp_json_encode($outputs),
        'created_at'      => $now,
        'updated_at'      => $now,
    ),
    array('%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s')
);
awvp_release_assert(false !== $inserted, 'Could not insert completed 1.0 queue sentinel.');
$job_id = (int) $wpdb->insert_id;
awvp_release_assert($job_id > 0, 'Completed 1.0 queue sentinel ID is invalid.');
update_post_meta($attachment_id, '_argent_video_job_id', $job_id);

$block_content = sprintf(
    '<!-- wp:video {"id":%1$d,"preload":"metadata"} -->' . "\n"
    . '<figure class="wp-block-video"><video controls preload="metadata" src="%2$s"></video><figcaption class="wp-element-caption">AWVP 1.0 legacy Core Video sentinel</figcaption></figure>' . "\n"
    . '<!-- /wp:video -->',
    $attachment_id,
    esc_url($source_url)
);
$post_id = wp_insert_post(
    array(
        'post_type'    => 'post',
        'post_status'  => 'publish',
        'post_title'   => 'AWVP 1.0 Legacy Video Block Sentinel',
        // wp_insert_post() expects slashed post data and unslashes it before persistence.
        'post_content' => wp_slash($block_content),
    ),
    true
);
awvp_release_assert(! is_wp_error($post_id) && (int) $post_id > 0, 'Could not create legacy Core Video post.');
$post_id = (int) $post_id;

$stored_post = get_post($post_id);
awvp_release_assert(is_object($stored_post), 'Could not reload legacy Core Video post after seed insert.');
$stored_content = (string) $stored_post->post_content;
awvp_release_assert(
    $block_content === $stored_content,
    '1.0 legacy Core Video fixture was not stored byte-for-byte before the upgrade test.'
);

$parsed = parse_blocks($stored_content);
awvp_release_assert(
    1 === count($parsed)
    && 'core/video' === ($parsed[0]['blockName'] ?? '')
    && $attachment_id === (int) ($parsed[0]['attrs']['id'] ?? 0),
    '1.0 legacy Core Video fixture is not a valid core/video block.'
);
$rendered = do_blocks($stored_content);
awvp_release_assert(
    str_contains($rendered, esc_url($managed_url)),
    '1.0 processed Core Video fixture did not render its managed derivative.'
);

update_option('awvp_rc_legacy_attachment_id', $attachment_id, false);
update_option('awvp_rc_legacy_post_id', $post_id, false);
update_option('awvp_rc_legacy_job_id', $job_id, false);
update_option('awvp_rc_legacy_source_path', $source, false);
update_option('awvp_rc_legacy_source_sha256', hash_file('sha256', $source), false);
update_option('awvp_rc_legacy_managed_path', $managed_output, false);
update_option('awvp_rc_legacy_managed_url', $managed_url, false);
update_option('awvp_rc_legacy_managed_sha256', hash_file('sha256', $managed_output), false);
update_option('awvp_rc_legacy_post_content', $stored_content, false);
update_option('awvp_rc_legacy_post_content_sha256', hash('sha256', $stored_content), false);
update_option('awvp_rc_legacy_attachment_meta_sha256', awvp_rc_post_meta_hash($attachment_id), false);
update_option('awvp_rc_legacy_signature', $signature, false);

echo "AWVP_RC_1_0_UPGRADE_SEED_PASS attachment_id={$attachment_id} post_id={$post_id} job_id={$job_id}\n";
