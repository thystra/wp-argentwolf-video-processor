<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

awvp_rc_assert_candidate_common();

$admin = get_user_by('login', 'awvpadmin');
awvp_release_assert(is_object($admin) && (int) $admin->ID > 0, 'Release-validation administrator is missing.');
$admin_id = (int) $admin->ID;
wp_set_current_user($admin_id);

$origin_post_id = wp_insert_post(
    array(
        'post_type'    => 'post',
        'post_status'  => 'draft',
        'post_title'   => 'AWVP RC13.10 external embedding origin',
        'post_content' => '',
        'post_author'  => $admin_id,
    ),
    true
);
awvp_release_assert(! is_wp_error($origin_post_id) && (int) $origin_post_id > 0, 'Could not create external-embedding origin post.');
$origin_post_id = (int) $origin_post_id;

// Exercise the packaged production REST/application path for a provider that
// requires no provider HTTP merely to embed.
if (! did_action('rest_api_init')) {
    do_action('rest_api_init');
}

$request = new WP_REST_Request('POST', '/argentwolf-video-processor/v1/editor/external-videos');
$request->set_param('url', 'https://youtu.be/dQw4w9WgXcQ');
$request->set_param('origin_post_id', $origin_post_id);
$response = rest_do_request($request);
awvp_release_assert(! is_wp_error($response), 'YouTube external-video REST request returned WP_Error.');
awvp_release_assert(200 === $response->get_status(), 'YouTube external-video REST request did not return HTTP 200.');
$data = $response->get_data();
awvp_release_assert(is_array($data) && is_array($data['video'] ?? null), 'YouTube external-video REST response is malformed.');
$youtube_id = (int) ($data['video']['id'] ?? 0);
awvp_release_assert($youtube_id > 0, 'YouTube external-video REST response returned no AWVP Video ID.');
awvp_release_assert(true === ($data['video']['external'] ?? false), 'YouTube AWVP Video is not classified as external.');
awvp_release_assert(false === ($data['video']['remote_only'] ?? true), 'YouTube AWVP Video was misclassified as managed remote-only.');
awvp_release_assert('youtube' === ($data['video']['external_provider'] ?? ''), 'YouTube AWVP Video lost provider identity.');
awvp_release_assert(
    'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' === ($data['video']['remote_embed_url'] ?? ''),
    'YouTube AWVP Video did not expose the privacy-enhanced canonical embed URL.'
);
awvp_release_assert(null === ($data['video']['destination'] ?? null), 'External YouTube AWVP Video acquired a publishing destination.');

// Equivalent URL forms must deduplicate through the production REST path.
$request2 = new WP_REST_Request('POST', '/argentwolf-video-processor/v1/editor/external-videos');
$request2->set_param('url', 'https://www.youtube.com/watch?v=dQw4w9WgXcQ');
$request2->set_param('origin_post_id', $origin_post_id);
$response2 = rest_do_request($request2);
awvp_release_assert(! is_wp_error($response2) && 200 === $response2->get_status(), 'Equivalent YouTube URL could not be rebound.');
$data2 = $response2->get_data();
awvp_release_assert(is_array($data2) && $youtube_id === (int) ($data2['video']['id'] ?? 0), 'Equivalent YouTube URL created a duplicate AWVP Video.');

awvp_release_assert(
    'existing_remote' === \ArgentVideo\Video_Meta::sanitize_ingest_kind(get_post_meta($youtube_id, \ArgentVideo\Video_Meta::INGEST_KIND, true)),
    'External YouTube AWVP Video has the wrong ingest kind.'
);
awvp_release_assert(
    'external_archive' === \ArgentVideo\Video_Meta::sanitize_master_authority(get_post_meta($youtube_id, \ArgentVideo\Video_Meta::MASTER_AUTHORITY, true)),
    'External YouTube AWVP Video has the wrong master authority.'
);
awvp_release_assert(
    'external' === \ArgentVideo\Video_Meta::sanitize_source_state(get_post_meta($youtube_id, \ArgentVideo\Video_Meta::SOURCE_STATE, true)),
    'External YouTube AWVP Video has the wrong source state.'
);
awvp_release_assert(
    0 === \ArgentVideo\Video_Meta::sanitize_positive_id(get_post_meta($youtube_id, \ArgentVideo\Video_Meta::ATTACHMENT_ID, true)),
    'External YouTube AWVP Video unexpectedly acquired a WordPress attachment.'
);
awvp_release_assert(
    ! metadata_exists('post', $youtube_id, \ArgentVideo\Video_Meta::DESTINATION),
    'External YouTube AWVP Video persisted a publishing destination.'
);

$source = \ArgentVideo\External_Video_Source::sanitize(
    get_post_meta($youtube_id, \ArgentVideo\Video_Meta::EXTERNAL_SOURCE, true)
);
awvp_release_assert(array() !== $source, 'External YouTube durable source record is missing or malformed.');
awvp_release_assert(
    'v1|youtube|https://www.youtube.com|dQw4w9WgXcQ' === ($source['identity']['canonical_key'] ?? ''),
    'External YouTube canonical identity drifted.'
);

// The common AWVP block must render solely from durable identity, with no
// autoplay permission or query parameter.
$block_markup = sprintf(
    '<!-- wp:argentwolf-video-processor/video {"videoId":%d} /-->',
    $youtube_id
);
$rendered = do_blocks($block_markup);
awvp_release_assert(str_contains($rendered, 'youtube-nocookie.com/embed/dQw4w9WgXcQ'), 'YouTube AWVP block did not render the durable provider iframe.');
awvp_release_assert(str_contains($rendered, 'loading="lazy"'), 'Provider iframe lost lazy loading.');
awvp_release_assert(str_contains($rendered, 'referrerpolicy="strict-origin-when-cross-origin"'), 'Provider iframe lost reviewed referrer policy.');
awvp_release_assert(str_contains($rendered, 'allow="fullscreen; picture-in-picture"'), 'Provider iframe allow list drifted.');
awvp_release_assert(! str_contains(strtolower($rendered), 'autoplay'), 'Provider iframe acquired autoplay authority.');

// Existing-video selection must include external records through the packaged
// production service/REST boundary.
$options_request = new WP_REST_Request('GET', '/argentwolf-video-processor/v1/editor/video-options');
$options_response = rest_do_request($options_request);
awvp_release_assert(! is_wp_error($options_response) && 200 === $options_response->get_status(), 'External existing-video options request failed.');
$options_data = $options_response->get_data();
$found_youtube = false;
foreach ((array) ($options_data['videos'] ?? array()) as $option) {
    if ($youtube_id === (int) ($option['id'] ?? 0)) {
        $found_youtube = 'external' === ($option['source'] ?? '') && 'youtube' === ($option['provider'] ?? '');
        break;
    }
}
awvp_release_assert($found_youtube, 'Existing-video selector omitted the external YouTube AWVP Video.');

// External records must remain outside the existing publishing-destination path.
$registry = new \ArgentVideo\Backend_Registry();
$defaults = new \ArgentVideo\Video_Publishing_Defaults_Store($registry);
$editor = new \ArgentVideo\Video_Block_Editor_Service($registry, $defaults);
$destination_result = $editor->set_destination($youtube_id, 'local');
awvp_release_assert(
    \ArgentVideo\Video_Block_Editor_Service::REFUSED === ($destination_result['status'] ?? ''),
    'External YouTube AWVP Video was allowed to acquire a local publishing destination.'
);

// Vimeo must use the same durable provider-neutral application/rendering path.
$stub_verifier = new \ArgentVideo\PeerTube_Public_Video_Verifier(
    static function (string $origin): object {
        unset($origin);
        return new class {
            public function get_public_video(string $candidate): array
            {
                unset($candidate);
                return array('ok' => false, 'http_status' => 503, 'body' => '');
            }
        };
    }
);
$external_service = new \ArgentVideo\External_Video_Source_Service(
    new \ArgentVideo\Video_Embed_Resolver(),
    $stub_verifier,
    $registry,
    new \ArgentVideo\Remote_Asset_Repository()
);
$vimeo = $external_service->bind_url('https://vimeo.com/76979871', $origin_post_id, $admin_id, 1789350000);
awvp_release_assert(
    \ArgentVideo\External_Video_Source_Service::APPLIED === ($vimeo['status'] ?? '') && (int) ($vimeo['video_id'] ?? 0) > 0,
    'Vimeo external binding was not applied.'
);
$vimeo_id = (int) $vimeo['video_id'];
$vimeo_rendered = do_blocks(sprintf('<!-- wp:argentwolf-video-processor/video {"videoId":%d} /-->', $vimeo_id));
awvp_release_assert(str_contains($vimeo_rendered, 'https://player.vimeo.com/video/76979871'), 'Vimeo AWVP block did not render canonical provider iframe.');
awvp_release_assert(! str_contains(strtolower($vimeo_rendered), 'autoplay'), 'Vimeo provider iframe acquired autoplay authority.');

// PeerTube-shaped URLs must be positively verified before persistence. Exercise
// the packaged verifier/application path with a deterministic in-process public
// API response so this disposable qualification performs no PeerTube action.
$authoritative_uuid = '1d7d52d3-6790-4da0-bc47-063298a7512a';
$peertube_verifier = new \ArgentVideo\PeerTube_Public_Video_Verifier(
    static function (string $origin) use ($authoritative_uuid): object {
        return new class($origin, $authoritative_uuid) {
            public function __construct(private string $origin, private string $uuid) {}
            public function get_public_video(string $candidate): array
            {
                awvp_release_assert('https://video.example.com' === $this->origin, 'PeerTube verifier used the wrong provider origin.');
                awvp_release_assert('4DcZjJeFLKUg9wibvspbbE' === $candidate, 'PeerTube verifier lost the pasted short identity.');
                return array(
                    'ok' => true,
                    'http_status' => 200,
                    'body' => wp_json_encode(array(
                        'uuid' => $this->uuid,
                        'name' => 'AWVP RC13.10 PeerTube qualification',
                        'privacy' => array('id' => 1, 'label' => 'Public'),
                    )),
                );
            }
        };
    }
);
$peertube_service = new \ArgentVideo\External_Video_Source_Service(
    new \ArgentVideo\Video_Embed_Resolver(),
    $peertube_verifier,
    $registry,
    new \ArgentVideo\Remote_Asset_Repository()
);
$peertube = $peertube_service->bind_url(
    'https://video.example.com/w/4DcZjJeFLKUg9wibvspbbE',
    $origin_post_id,
    $admin_id,
    1789350100
);
awvp_release_assert(
    \ArgentVideo\External_Video_Source_Service::APPLIED === ($peertube['status'] ?? '') && (int) ($peertube['video_id'] ?? 0) > 0,
    'Verified public PeerTube binding was not applied.'
);
$peertube_id = (int) $peertube['video_id'];
$peertube_source = \ArgentVideo\External_Video_Source::sanitize(
    get_post_meta($peertube_id, \ArgentVideo\Video_Meta::EXTERNAL_SOURCE, true)
);
awvp_release_assert(
    $authoritative_uuid === ($peertube_source['identity']['video_id'] ?? ''),
    'PeerTube alias did not converge to authoritative UUID.'
);
awvp_release_assert(
    'https://video.example.com/videos/embed/' . $authoritative_uuid === ($peertube_source['identity']['embed_url'] ?? ''),
    'PeerTube verified identity did not regenerate canonical embed URL.'
);
$peertube_rendered = do_blocks(sprintf('<!-- wp:argentwolf-video-processor/video {"videoId":%d} /-->', $peertube_id));
awvp_release_assert(str_contains($peertube_rendered, '/videos/embed/' . $authoritative_uuid), 'Verified PeerTube external AWVP Video did not render from durable identity.');
awvp_release_assert(! str_contains(strtolower($peertube_rendered), 'autoplay'), 'PeerTube external provider iframe acquired autoplay authority.');

// External identity is not local-retention authority. The package must refuse
// a destructive retention configuration before persisting a policy/task.
$serving = new class implements \ArgentVideo\Video_Serving_Resolver {
    public function peertube_embed_url(int $video_id): string
    {
        unset($video_id);
        return '';
    }
};
$local_retention = new \ArgentVideo\Local_Retention_Service(
    new \ArgentVideo\Task_Repository(),
    $serving,
    new \ArgentVideo\Job_Repository(),
    null,
    new \ArgentVideo\Video_Reference_Index()
);
$retention = $local_retention->configure(
    $youtube_id,
    'delete_all',
    0,
    'external_archive',
    $admin_id,
    1789350200
);
awvp_release_assert(
    \ArgentVideo\Local_Retention_Service::REFUSED === ($retention['status'] ?? ''),
    'External video was accepted into local-retention cleanup authority.'
);
awvp_release_assert(
    ! metadata_exists('post', $youtube_id, \ArgentVideo\Video_Meta::LOCAL_RETENTION_POLICY),
    'Rejected external retention request still persisted a retention policy.'
);

// Vimeo unlisted/private access-token forms must continue to fail closed.
$resolver = new \ArgentVideo\Video_Embed_Resolver();
awvp_release_assert(
    null === $resolver->recognize('https://player.vimeo.com/video/76979871?h=secret'),
    'Vimeo private/unlisted token URL was accepted as a public durable identity.'
);

wp_delete_post($origin_post_id, true);

echo "AWVP_RC13_10_EXTERNAL_EMBEDDING_PASS\n";
