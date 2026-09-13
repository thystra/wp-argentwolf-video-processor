<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

global $wpdb, $post;

awvp_rc_assert_candidate_common();

$attachment_id = (int) get_option('awvp_rc_legacy_attachment_id', 0);
$post_id = (int) get_option('awvp_rc_legacy_post_id', 0);
$job_id = (int) get_option('awvp_rc_legacy_job_id', 0);
$source = (string) get_option('awvp_rc_legacy_source_path', '');
$source_sha = (string) get_option('awvp_rc_legacy_source_sha256', '');
$managed_url = (string) get_option('awvp_rc_legacy_managed_url', '');
$expected_content = (string) get_option('awvp_rc_legacy_post_content', '');
$expected_content_sha = (string) get_option('awvp_rc_legacy_post_content_sha256', '');
$signature = (string) get_option('awvp_rc_legacy_signature', '');

awvp_release_assert($attachment_id > 0 && $post_id > 0 && $job_id > 0, 'Legacy migration fixture IDs are missing.');
awvp_release_assert('' !== $source && '' !== $source_sha && '' !== $expected_content, 'Legacy migration fixture files/content are missing.');

$jobs = $wpdb->prefix . 'argent_video_jobs';
$tasks = $wpdb->prefix . \ArgentVideo\Model_Activator::TASKS_TABLE;
$remote = $wpdb->prefix . \ArgentVideo\Model_Activator::REMOTE_ASSETS_TABLE;

$legacy = new \ArgentVideo\Legacy_Video_Adoption_Service();
$inspection = $legacy->inspect($attachment_id);
awvp_release_assert(
    'eligible' === ($inspection['status'] ?? null) && $post_id === (int) ($inspection['anchor_post_id'] ?? 0),
    'Legacy fixture was not eligible immediately before explicit adoption.'
);
awvp_release_assert(
    ! metadata_exists('post', $attachment_id, \ArgentVideo\Legacy_Video_Adoption_Service::ATTACHMENT_ASSET_META),
    'Legacy fixture was already adopted before the explicit planning phase.'
);

$backend_id = 'pt-rc10-fixture';
$channel_id = '77';
$origin = 'https://video.example.org';
$registry_value = array(
    'version' => \ArgentVideo\Backend_Registry::VERSION,
    'backends' => array(
        'local' => array(
            'id' => 'local',
            'type' => 'local',
            'label' => 'Local AWVP',
            'state' => 'active',
            'default_destination' => '',
            'secret_ref' => '',
            'config_version' => 1,
            'config' => array(),
        ),
        $backend_id => array(
            'id' => $backend_id,
            'type' => 'peertube',
            'label' => 'RC10 Fixture PeerTube',
            'state' => 'active',
            'default_destination' => $channel_id,
            'secret_ref' => 'managed:pt-rc10-fixture',
            'config_version' => 1,
            'config' => array('origin' => $origin),
        ),
    ),
);
update_option(\ArgentVideo\Backend_Registry::OPTION, $registry_value, false);
$registry = new \ArgentVideo\Backend_Registry();
awvp_release_assert(
    $backend_id === (string) ($registry->get($backend_id)['id'] ?? ''),
    'Could not establish the release-validation PeerTube backend context.'
);

$defaults = \ArgentVideo\Video_Publishing_Defaults::defaults();
$defaults['backend_overrides'][$backend_id] = array(
    'channel_id' => $channel_id,
    'final_privacy_id' => '1',
    'licence_id' => null,
    'category_id' => null,
);
$defaults_store = new \ArgentVideo\Video_Publishing_Defaults_Store($registry);
$saved_defaults = $defaults_store->save($defaults);
awvp_release_assert(
    in_array($saved_defaults['status'] ?? '', array(
        \ArgentVideo\Video_Publishing_Defaults_Store::APPLIED,
        \ArgentVideo\Video_Publishing_Defaults_Store::PRESENT,
    ), true),
    'Could not establish RC10 migration publishing defaults.'
);

$catalog = array(
    'version' => \ArgentVideo\PeerTube_Publication_Catalog::VERSION,
    'backend_id' => $backend_id,
    'origin' => $origin,
    'secret_generation' => 3,
    'server_version' => '8.2.4',
    'refreshed_at' => time(),
    'stale' => false,
    'stale_since' => null,
    'stale_reason' => '',
    'channels' => array(
        array('id' => $channel_id, 'name' => 'main', 'display_name' => 'Main', 'authority' => 'owned'),
    ),
    'privacies' => array('1' => 'Public', '2' => 'Unlisted', '3' => 'Private', '5' => 'Password protected'),
    'licences' => array('1' => 'Attribution'),
    'categories' => array('2' => 'People'),
    'languages' => array('en' => 'English'),
    'capabilities' => array('sensitive_content' => true, 'sensitive_flags' => true, 'password_privacy' => true),
);
$catalog_store = new \ArgentVideo\PeerTube_Publication_Catalog_Store();
awvp_release_assert(
    $catalog_store->save_last_known_good($backend_id, $catalog),
    'Could not establish RC10 migration publication catalog.'
);
$catalog = $catalog_store->get($backend_id);
awvp_release_assert(is_array($catalog), 'RC10 migration publication catalog did not round-trip.');

$planner = new \ArgentVideo\PeerTube_Migration_Planner($registry, $defaults_store, $catalog_store, $legacy);
$candidates = $planner->candidates(20, 0);
$candidate_keys = array_map(
    static fn (array $row): string => is_string($row['candidate_key'] ?? null) ? $row['candidate_key'] : '',
    $candidates['items']
);
awvp_release_assert(
    in_array('legacy:' . $attachment_id, $candidate_keys, true),
    'Explicit migration planning could not see the legacy fixture discovered during upgrade preservation.'
);

$before_content = (string) get_post($post_id)->post_content;
$before_source_sha = hash_file('sha256', $source);
$planned = $planner->plan_candidates(array('legacy:' . $attachment_id), $backend_id, $channel_id, time());
awvp_release_assert(1 === count($planned['applied']), 'Explicit legacy migration planning did not adopt exactly one fixture.');
$video_id = (int) $planned['applied'][0];
awvp_release_assert($video_id > 0, 'Explicit legacy migration planning returned no AWVP Video ID.');
awvp_release_assert(
    $video_id === (int) get_post_meta($attachment_id, \ArgentVideo\Legacy_Video_Adoption_Service::ATTACHMENT_ASSET_META, true),
    'Explicit legacy adoption did not establish the attachment reverse binding.'
);
awvp_release_assert(
    $attachment_id === \ArgentVideo\Video_Meta::sanitize_positive_id(get_post_meta($video_id, \ArgentVideo\Video_Meta::ATTACHMENT_ID, true))
    && $post_id === \ArgentVideo\Video_Meta::sanitize_positive_id(get_post_meta($video_id, \ArgentVideo\Video_Meta::ORIGIN_POST_ID, true)),
    'Explicit legacy adoption lost attachment/origin identity.'
);
awvp_release_assert(
    \ArgentVideo\Video_Destination::is_local(
        \ArgentVideo\Video_Destination::sanitize(get_post_meta($video_id, \ArgentVideo\Video_Meta::DESTINATION, true))
    ),
    'Legacy adoption did not remain on the local destination before migration execution.'
);
awvp_release_assert(
    1 === (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM `{$jobs}` WHERE attachment_id = %d", $attachment_id)),
    'Legacy adoption created an additional local FFmpeg job.'
);
awvp_release_assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tasks}`"), 'Legacy adoption/planning dispatched PeerTube work.');
awvp_release_assert(
    $before_content === (string) get_post($post_id)->post_content
    && $expected_content_sha === hash('sha256', (string) get_post($post_id)->post_content),
    'Legacy adoption/planning rewrote historical post_content.'
);
awvp_release_assert(
    is_file($source) && $source_sha === hash_file('sha256', $source) && $before_source_sha === hash_file('sha256', $source),
    'Legacy adoption/planning changed the WordPress original source.'
);
awvp_release_assert('complete' === (string) get_post_meta($attachment_id, '_argent_video_status', true), 'Legacy completion metadata changed during adoption.');
awvp_release_assert($signature === (string) get_post_meta($attachment_id, '_argent_video_source_signature', true), 'Legacy source signature changed during adoption.');

$migration = \ArgentVideo\PeerTube_Migration_Plan::sanitize(
    get_post_meta($video_id, \ArgentVideo\Video_Meta::PEERTUBE_MIGRATION_PLAN, true)
);
awvp_release_assert(array() !== $migration && is_array($migration['publication_plan'] ?? null), 'Explicit migration planning did not produce a reviewable publication plan.');
$publication_plan = $migration['publication_plan'];
$publication_plan['review'] = array(
    'title' => true,
    'channel' => true,
    'tags' => true,
    'privacy' => true,
    'moderation' => true,
);
$publication_plan['moderation']['reviewed'] = true;
$review = $planner->review($video_id, $publication_plan, time() + 1);
awvp_release_assert(
    \ArgentVideo\PeerTube_Migration_Planner::APPLIED === ($review['status'] ?? null)
    && array() === ($review['issues'] ?? array('unexpected')),
    'Explicit migration review did not reach the ready state.'
);
$migration = \ArgentVideo\PeerTube_Migration_Plan::sanitize(
    get_post_meta($video_id, \ArgentVideo\Video_Meta::PEERTUBE_MIGRATION_PLAN, true)
);
awvp_release_assert(
    \ArgentVideo\PeerTube_Migration_Plan::STATUS_READY === ($migration['status'] ?? null),
    'Reviewed legacy migration plan is not ready.'
);
$publication_plan = $migration['publication_plan'];
awvp_release_assert(
    \ArgentVideo\PeerTube_Publication_Plan::ready_for_dispatch($publication_plan),
    'Reviewed legacy migration publication plan is not dispatch-ready.'
);

// Simulate the durable state that exists after a provider publication has been
// positively verified. This phase performs no network call and creates no task;
// it exercises the real runtime serving resolver/legacy block filter against
// the exact DB/meta contracts used by a completed publication.
$destination = \ArgentVideo\Video_Destination::sanitize(array(
    'version' => \ArgentVideo\Video_Destination::VERSION,
    'backend_id' => $backend_id,
    'channel_id' => $channel_id,
));
awvp_release_assert(array() !== $destination, 'Could not build simulated PeerTube destination.');
update_post_meta($video_id, \ArgentVideo\Video_Meta::DESTINATION, $destination);
update_post_meta($video_id, \ArgentVideo\Video_Meta::PEERTUBE_PUBLICATION_PLAN, $publication_plan);

$manifest = \ArgentVideo\PeerTube_Publication_Manifest::build($publication_plan, $catalog, $defaults_store->get());
awvp_release_assert(array() !== $manifest, 'Could not build verified-publication manifest for legacy serving fixture.');
$plan_sha = \ArgentVideo\PeerTube_Publication_Lifecycle::plan_sha256($publication_plan);
awvp_release_assert('' !== $plan_sha && $plan_sha === (string) $manifest['plan_sha256'], 'Legacy serving fixture plan hash mismatch.');

$now = time() + 2;
$lifecycle = \ArgentVideo\PeerTube_Publication_Lifecycle::sanitize(array(
    'version' => \ArgentVideo\PeerTube_Publication_Lifecycle::VERSION,
    'generation' => 1,
    'backend_id' => $backend_id,
    'anchor_post_id' => $post_id,
    'plan_sha256' => $plan_sha,
    'dispatch_policy' => (string) $publication_plan['dispatch_policy'],
    'wordpress_status' => 'publish',
    'upload_authorized' => true,
    'reveal_authorized' => true,
    'target_privacy_id' => (string) $publication_plan['final_privacy_id'],
    'task_pending' => false,
    'updated_at' => $now,
));
awvp_release_assert(array() !== $lifecycle, 'Could not build verified-publication lifecycle fixture.');

$remote_uuid = '24a7e04e-639e-4fc4-ad7f-82ad2030a66a';
$embed_url = $origin . '/videos/embed/5wwQY8261uRv7aPsVWhEiY';
$mysql_now = current_time('mysql', true);
$inserted = $wpdb->insert(
    $remote,
    array(
        'video_post_id' => $video_id,
        'backend_id' => $backend_id,
        'channel_id' => $channel_id,
        'remote_id' => $remote_uuid,
        'role' => 'secondary',
        'state' => 'ready',
        'desired_privacy' => 'public',
        'actual_privacy' => 'public',
        'remote_processing_state' => '1:published',
        'remote_url' => $origin . '/w/' . $remote_uuid,
        'embed_url' => $embed_url,
        'last_synced_at' => $mysql_now,
        'last_verified_at' => $mysql_now,
        'error_code' => null,
        'error_message' => null,
        'created_at' => $mysql_now,
        'updated_at' => $mysql_now,
    ),
    array('%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s','%s')
);
awvp_release_assert(false !== $inserted, 'Could not create verified remote-asset fixture.');
$remote_asset_id = (int) $wpdb->insert_id;
awvp_release_assert($remote_asset_id > 0, 'Verified remote-asset fixture has no ID.');

$execution = \ArgentVideo\PeerTube_Publication_Execution::create($manifest, $now);
$execution = \ArgentVideo\PeerTube_Publication_Execution::with_remote($execution, $remote_asset_id, $remote_uuid, $now + 1);
$execution = \ArgentVideo\PeerTube_Publication_Execution::mark_applied($execution, $manifest, $now + 2);
awvp_release_assert(array() !== $execution, 'Could not build applied publication execution fixture.');

$asset = (new \ArgentVideo\Remote_Asset_Repository())->find($remote_asset_id);
awvp_release_assert(is_array($asset), 'Could not read verified remote-asset fixture through the production repository.');
$authority = \ArgentVideo\Video_Serving_Authority::create($video_id, $lifecycle, $execution, $asset, $now + 3);
awvp_release_assert(array() !== $authority, 'Could not establish verified serving authority for the legacy fixture.');
update_post_meta($video_id, \ArgentVideo\Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE, $lifecycle);
update_post_meta($video_id, \ArgentVideo\Video_Meta::PEERTUBE_PUBLICATION_EXECUTION, $execution);
update_post_meta($video_id, \ArgentVideo\Video_Meta::SERVING_AUTHORITY, $authority);

$legacy_post = get_post($post_id);
awvp_release_assert(is_object($legacy_post), 'Legacy post disappeared before runtime serving verification.');
$post = $legacy_post;
setup_postdata($post);
$rendered = do_blocks((string) $legacy_post->post_content);
wp_reset_postdata();
awvp_release_assert(
    str_contains($rendered, '<iframe') && str_contains($rendered, esc_url($embed_url)),
    'Verified adopted legacy Core Video did not switch to the PeerTube iframe at runtime.'
);
awvp_release_assert(
    ! str_contains($rendered, esc_url($managed_url)),
    'Verified adopted legacy Core Video continued serving the old managed derivative after cutover.'
);
awvp_release_assert(! str_contains(strtolower($rendered), 'autoplay'), 'Legacy PeerTube runtime bridge introduced autoplay.');
awvp_release_assert(
    str_contains($rendered, 'awvp-peertube-embed') && str_contains($rendered, 'data-awvp-video-id="' . $video_id . '"'),
    'Legacy PeerTube runtime bridge is missing the responsive wrapper or stable video identity.'
);
$after_post = get_post($post_id);
awvp_release_assert(
    is_object($after_post)
    && $expected_content === (string) $after_post->post_content
    && $expected_content_sha === hash('sha256', (string) $after_post->post_content),
    'Legacy runtime serving cutover rewrote stored historical post_content.'
);
awvp_release_assert(is_file($source) && $source_sha === hash_file('sha256', $source), 'Legacy runtime cutover changed/deleted the WordPress original source.');
awvp_release_assert(0 === (int) $wpdb->get_var("SELECT COUNT(*) FROM `{$tasks}`"), 'Simulated verified legacy cutover unexpectedly created PeerTube tasks.');

update_option('awvp_rc_legacy_adopted_video_id', $video_id, false);
update_option('awvp_rc_legacy_remote_asset_id', $remote_asset_id, false);

echo "AWVP_RC_LEGACY_DISCOVERY_ADOPTION_SERVING_PASS video_id={$video_id} remote_asset_id={$remote_asset_id}\n";
