<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

awvp_rc_assert_candidate_common();

$required_classes = array(
    \ArgentVideo\Serving_Viability::class,
    \ArgentVideo\Serving_Health_Adapter::class,
    \ArgentVideo\Serving_Health_Adapter_Factory::class,
    \ArgentVideo\Remote_Publication_Health_Repository::class,
    \ArgentVideo\Remote_Publication_Health_Service::class,
    \ArgentVideo\PeerTube_Publication_Health_Probe::class,
    \ArgentVideo\Backend_Serving_Priority_Store::class,
    \ArgentVideo\Backend_Processing_Estimator::class,
    \ArgentVideo\Backend_Health_Incident_Store::class,
    \ArgentVideo\Remote_Health_Notification_Policy_Store::class,
    \ArgentVideo\Remote_Health_Notification_State_Store::class,
    \ArgentVideo\Remote_Health_Notification_Service::class,
    \ArgentVideo\PeerTube_Daily_Maintenance_Service::class,
    \ArgentVideo\Backend_Maintenance_Status_Store::class,
    \ArgentVideo\Overview_Disposition_Store::class,
    \ArgentVideo\Remote_Republish_Request::class,
    \ArgentVideo\Remote_Republish_Service::class,
    \ArgentVideo\PeerTube_Publication_Finalizer_Recovery::class,
    \ArgentVideo\PeerTube_Serving_Cutover_Service::class,
    \ArgentVideo\Video_Serving_Authority::class,
    \ArgentVideo\Archive_Of_Record_Policy_Store::class,
    \ArgentVideo\Local_Retention_Default_Policy_Store::class,
    \ArgentVideo\Local_Retention_Service::class,
    \ArgentVideo\Local_Retention_Admin::class,
    \ArgentVideo\Local_Delivery_Evidence::class,
    \ArgentVideo\Video_Reference_Index::class,
    \ArgentVideo\Video_Routing_Admin::class,
    \ArgentVideo\Publication_History_Admin::class,
    \ArgentVideo\Operator_Time::class,
    \ArgentVideo\Remote_Health_Operator_Check::class,
    \ArgentVideo\Remote_Health_Operator_Service::class,
    \ArgentVideo\Verified_Remote_Asset_Refresher::class,
    \ArgentVideo\PeerTube_Verified_Remote_Asset_Refresher::class,
    \ArgentVideo\Local_Delivery_Rebuild_Request::class,
    \ArgentVideo\Local_Delivery_Rebuild_Service::class,
    \ArgentVideo\Source_Retirement_Record::class,
);
foreach ($required_classes as $class) {
    awvp_release_assert(class_exists($class) || interface_exists($class), "Required RC13.4 live-fix runtime class/interface is not packaged: {$class}");
}

$processing = \ArgentVideo\Serving_Viability::create(
    \ArgentVideo\Serving_Viability::PROCESSING,
    'provider_processing',
    'Public serving resource is still processing.',
    200
);
awvp_release_assert(
    $processing instanceof \ArgentVideo\Serving_Viability
    && ! $processing->viable()
    && \ArgentVideo\Serving_Viability::PROCESSING === $processing->status(),
    'RC13.4 processing viability state is not available.'
);

$priority_store = new \ArgentVideo\Backend_Serving_Priority_Store();
awvp_release_assert(0 === $priority_store->priority(\ArgentVideo\Backend_Registry::LOCAL_ID), 'Local serving priority must remain fixed at zero.');
awvp_release_assert(
    \ArgentVideo\Backend_Serving_Priority_Store::DEFAULT_REMOTE_PRIORITY === $priority_store->priority('release-fixture'),
    'Unconfigured remote backend serving priority did not use the safe RC13.4 default.'
);

$retention_default = new \ArgentVideo\Local_Retention_Default_Policy_Store();
awvp_release_assert(
    \ArgentVideo\Local_Retention_Policy::MODE_KEEP === $retention_default->mode(),
    'Local-retention default policy did not fail closed to keep.'
);

$notification_defaults = \ArgentVideo\Remote_Health_Notification_Policy_Store::defaults();
awvp_release_assert(
    \ArgentVideo\Remote_Health_Notification_Policy_Store::DELAYED === $notification_defaults['administrator']
    && \ArgentVideo\Remote_Health_Notification_Policy_Store::DELAYED === $notification_defaults['publishing_user']
    && \ArgentVideo\Remote_Health_Notification_Policy_Store::OFF === $notification_defaults['origin_author'],
    'Remote-publication health email defaults changed unexpectedly.'
);
awvp_release_assert(
    10 === \ArgentVideo\Backend_Processing_Estimator::MAX_SAMPLES
    && 7776000 === \ArgentVideo\Backend_Processing_Estimator::MAX_SAMPLE_AGE,
    'Backend processing estimator history bounds changed unexpectedly.'
);
$readiness_bands = \ArgentVideo\Backend_Processing_Estimator::size_bands();
awvp_release_assert(
    5 === count($readiness_bands)
    && 1 === ($readiness_bands[0]['min_bytes'] ?? 0)
    && 67108864 === ($readiness_bands[0]['max_bytes'] ?? 0)
    && 4294967296 === (($readiness_bands[4]['min_bytes'] ?? 0) - 1),
    'Backend readiness display bands no longer match the estimator bucket contract.'
);
awvp_release_assert(
    300 === \ArgentVideo\PeerTube_Publication_Authority_Repair::SEND_CATALOG_MAX_AGE,
    'PeerTube send-time catalog freshness window changed unexpectedly.'
);

awvp_release_assert(false !== wp_next_scheduled(\ArgentVideo\Activator::REMOTE_HEALTH_HOOK), 'Hourly remote-publication health cron is not scheduled.');
awvp_release_assert(false !== wp_next_scheduled(\ArgentVideo\Activator::BACKEND_MAINTENANCE_HOOK), 'Daily backend-maintenance cron is not scheduled.');

$republish_meta = registered_meta_key_exists('post', \ArgentVideo\Video_Meta::REMOTE_REPUBLISH_REQUEST, \ArgentVideo\Video_Post_Type::POST_TYPE);
awvp_release_assert($republish_meta, 'Durable remote-republish request meta is not registered for AWVP Video posts.');

awvp_release_assert(
    'operator_abandoned' === \ArgentVideo\PeerTube_Staged_Upload_State_Machine::PHASE_OPERATOR_ABANDONED
    && 'operator_abandon' === \ArgentVideo\PeerTube_Staged_Upload_State_Machine::EVENT_OPERATOR_ABANDON,
    'RC13.4 safe indeterminate-upload operator-retirement state contract is not packaged.'
);
awvp_release_assert(
    'argentwolf_video_processor_overview_resolve_upload' === \ArgentVideo\PeerTube_Overview_Admin::ACTION_RESOLVE_UPLOAD,
    'RC13.4 Overview indeterminate-upload resolution action is not packaged.'
);
awvp_release_assert(
    'argentwolf_video_processor_overview_reset_readiness' === \ArgentVideo\PeerTube_Overview_Admin::ACTION_RESET_READINESS,
    'RC13.4 Overview readiness-statistics reset action is not packaged.'
);


awvp_release_assert(
    'missing' === \ArgentVideo\PeerTube_Publication_Finalizer_Recovery::MISSING
    && 'terminal' === \ArgentVideo\PeerTube_Publication_Finalizer_Recovery::TERMINAL
    && method_exists(\ArgentVideo\PeerTube_Publication_Finalizer_Recovery::class, 'restore_missing'),
    'RC13.4 missing/terminal publication-finalizer recovery contract is not packaged.'
);
awvp_release_assert(
    method_exists(\ArgentVideo\PeerTube_Publication_Task_Coordinator::class, 'finalize_idempotency_key'),
    'RC13.4 exact-generation finalizer idempotency contract is not packaged.'
);


awvp_release_assert(
    0 === \ArgentVideo\Local_Retention_Policy::MIN_GRACE_DAYS
    && 365 === \ArgentVideo\Local_Retention_Policy::MAX_GRACE_DAYS
    && 'delete_source_keep_delivery' === \ArgentVideo\Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY,
    'RC13.4 local-retention Never/source-prune policy contract is not packaged.'
);
$never_cleanup = \ArgentVideo\Local_Retention_Policy::create(
    \ArgentVideo\Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY,
    0,
    1,
    1000
);
$delayed_cleanup = \ArgentVideo\Local_Retention_Policy::create(
    \ArgentVideo\Local_Retention_Policy::MODE_DELETE_SOURCE_KEEP_DELIVERY,
    7,
    1,
    1000
);
awvp_release_assert(
    array() !== $never_cleanup
    && ! \ArgentVideo\Local_Retention_Policy::automatic_cleanup_enabled($never_cleanup)
    && \ArgentVideo\Local_Retention_Policy::keeps_local_delivery($never_cleanup)
    && \ArgentVideo\Local_Retention_Policy::deletes_source($never_cleanup)
    && ! \ArgentVideo\Local_Retention_Policy::deletes_managed($never_cleanup)
    && \ArgentVideo\Local_Retention_Policy::automatic_cleanup_enabled($delayed_cleanup),
    'RC13.4 zero-day Never and keep-local-delivery retention semantics are unavailable.'
);
awvp_release_assert(
    0 === \ArgentVideo\Archive_Of_Record_Policy_Store::DEFAULT_GRACE_DAYS,
    'RC13.4 fresh-install retention grace does not fail closed to manual cleanup only.'
);
awvp_release_assert(
    2 === \ArgentVideo\Local_Retention_Service::PAYLOAD_VERSION
    && 1 === \ArgentVideo\Local_Retention_Service::LEGACY_PAYLOAD_VERSION
    && method_exists(\ArgentVideo\Local_Retention_Service::class, 'cleanup_now')
    && 'argent_video_bulk_cleanup_local_retention' === \ArgentVideo\Local_Retention_Admin::ACTION_BULK_CLEANUP,
    'RC13.4 manual cleanup-now / legacy retention-task compatibility contract is not packaged.'
);
awvp_release_assert(
    2 === \ArgentVideo\Video_Serving_Authority::OPERATOR_VERSION
    && 'operator_verified_remote' === \ArgentVideo\Video_Serving_Authority::BASIS_OPERATOR_VERIFIED
    && method_exists(\ArgentVideo\PeerTube_Serving_Cutover_Service::class, 'adopt_verified_remote'),
    'RC13.4 operator-verified serving-authority adoption contract is not packaged.'
);
awvp_release_assert(
    'publication-history' === \ArgentVideo\Settings_Hub::TAB_HISTORY
    && 'videos-routing' === \ArgentVideo\Settings_Hub::TAB_VIDEOS
    && method_exists(\ArgentVideo\PeerTube_Event_Repository::class, 'recent_global')
    && 100 === \ArgentVideo\PeerTube_Event_Repository::MAX_GLOBAL_RECENT,
    'RC13.4 Videos & Routing / History & Logs operator surfaces are not packaged.'
);
awvp_release_assert(
    'argentwolf_video_processor_overview_check_health' === \ArgentVideo\PeerTube_Overview_Admin::ACTION_CHECK_HEALTH
    && 'argentwolf_video_processor_overview_restore_health' === \ArgentVideo\PeerTube_Overview_Admin::ACTION_RESTORE_HEALTH
    && 'argentwolf_video_processor_overview_rebuild_local' === \ArgentVideo\PeerTube_Overview_Admin::ACTION_REBUILD_LOCAL
    && 600 === \ArgentVideo\Remote_Health_Operator_Service::RESTORE_WINDOW,
    'RC13.4 explicit remote-health/local-rebuild operator actions are not packaged.'
);
awvp_release_assert(
    registered_meta_key_exists('post', \ArgentVideo\Video_Meta::REMOTE_HEALTH_OPERATOR_CHECK, \ArgentVideo\Video_Post_Type::POST_TYPE)
    && registered_meta_key_exists('post', \ArgentVideo\Video_Meta::LOCAL_DELIVERY_REBUILD_REQUEST, \ArgentVideo\Video_Post_Type::POST_TYPE),
    'RC13.4 recovery audit/request metadata is not registered for AWVP Video posts.'
);
awvp_release_assert(
    '3' === \ArgentVideo\PeerTube_Publication_Plan::PRE_PUBLISH_PRIVATE
    && '2' === \ArgentVideo\PeerTube_Publication_Plan::PRE_PUBLISH_UNLISTED
    && \ArgentVideo\PeerTube_Publication_Plan::PRE_PUBLISH_PRIVATE === \ArgentVideo\PeerTube_Publication_Plan::pre_publish_privacy_id(array())
    && \ArgentVideo\PeerTube_Publication_Plan::PRE_PUBLISH_UNLISTED === \ArgentVideo\PeerTube_Publication_Plan::pre_publish_privacy_id(array('pre_publish_privacy_id'=>'2')),
    'RC13.4 explicit pre-publication Private/Unlisted visibility contract is not packaged.'
);
awvp_release_assert(
    method_exists(\ArgentVideo\Video_Reference_Index::class, 'posts_for')
    && method_exists(\ArgentVideo\Video_Reference_Index::class, 'attachment_posts_for')
    && method_exists(\ArgentVideo\Video_Routing_Admin::class, 'render_tab')
    && method_exists(\ArgentVideo\Publication_History_Admin::class, 'render_tab')
    && method_exists(\ArgentVideo\Remote_Health_Operator_Service::class, 'check_now')
    && method_exists(\ArgentVideo\Remote_Health_Operator_Service::class, 'restore_now')
    && method_exists(\ArgentVideo\PeerTube_Verified_Remote_Asset_Refresher::class, 'refresh')
    && method_exists(\ArgentVideo\Local_Delivery_Rebuild_Service::class, 'request')
    && method_exists(\ArgentVideo\Local_Retention_Service::class, 'remove_local_copies_now')
    && method_exists(\ArgentVideo\Local_Retention_Service::class, 'reconcile_completed_retirements'),
    'RC13.8 operator recovery/retention/source-retirement runtime methods are unavailable.'
);
awvp_release_assert(
    defined(\ArgentVideo\Video_Meta::class . '::SOURCE_TOMBSTONE')
    && '_argentwolf_video_processor_source_tombstone' === \ArgentVideo\Video_Meta::SOURCE_TOMBSTONE
    && registered_meta_key_exists('post', \ArgentVideo\Video_Meta::SOURCE_TOMBSTONE, \ArgentVideo\Video_Post_Type::POST_TYPE),
    'RC13.8 source-retirement tombstone metadata is not registered.'
);
$service_source = (string) file_get_contents(WP_PLUGIN_DIR . '/argentwolf-video-processor/includes/Local_Retention_Service.php');
awvp_release_assert(
    str_contains($service_source, 'wp_delete_attachment($attachment_id,true)')
    && str_contains($service_source, 'Source_Retirement_Record::capture')
    && ! str_contains($service_source, 'DELETE FROM'),
    'RC13.8 WordPress attachment lifecycle contract is not packaged.'
);

awvp_release_assert(
    interface_exists(\ArgentVideo\Video_Serving_Resolver::class)
    && class_exists(\ArgentVideo\Video_Block::class)
    && method_exists(\ArgentVideo\Video_Serving_Resolver::class, 'peertube_embed_url'),
    'RC13.8 remote-only block serving interfaces are not packaged.'
);

$remote_only_video_id = wp_insert_post(
    array(
        'post_type'   => \ArgentVideo\Video_Post_Type::POST_TYPE,
        'post_status' => 'publish',
        'post_title'  => 'RC13.8 remote-only qualification fixture',
    ),
    true
);
awvp_release_assert(
    ! is_wp_error($remote_only_video_id) && (int) $remote_only_video_id > 0,
    'RC13.8 remote-only block qualification fixture could not be created.'
);

$remote_only_resolver = new class implements \ArgentVideo\Video_Serving_Resolver {
    public function peertube_embed_url(int $video_id): string
    {
        unset($video_id);
        return 'https://video.example.test/videos/embed/rc13-8-remote-only';
    }
};
$remote_only_block = new \ArgentVideo\Video_Block($remote_only_resolver);
$remote_only_html = $remote_only_block->render(array('videoId' => (int) $remote_only_video_id));
wp_delete_post((int) $remote_only_video_id, true);

awvp_release_assert(
    str_contains($remote_only_html, 'awvp-peertube-embed')
    && str_contains($remote_only_html, 'https://video.example.test/videos/embed/rc13-8-remote-only')
    && ! str_contains($remote_only_html, '<video'),
    'RC13.8 remote-only AWVP Video did not render through its verified-remote serving resolver without a local attachment.'
);

echo "AWVP_RC13_8_LIVEFIX_CONTRACT_PASS\n";
