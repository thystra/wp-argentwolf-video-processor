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
    \ArgentVideo\Local_Retention_Default_Policy_Store::class,
);
foreach ($required_classes as $class) {
    awvp_release_assert(class_exists($class) || interface_exists($class), "Required RC12 live-fix runtime class/interface is not packaged: {$class}");
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
    'RC12 processing viability state is not available.'
);

$priority_store = new \ArgentVideo\Backend_Serving_Priority_Store();
awvp_release_assert(0 === $priority_store->priority(\ArgentVideo\Backend_Registry::LOCAL_ID), 'Local serving priority must remain fixed at zero.');
awvp_release_assert(
    \ArgentVideo\Backend_Serving_Priority_Store::DEFAULT_REMOTE_PRIORITY === $priority_store->priority('release-fixture'),
    'Unconfigured remote backend serving priority did not use the safe RC12 default.'
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

awvp_release_assert(false !== wp_next_scheduled(\ArgentVideo\Activator::REMOTE_HEALTH_HOOK), 'Hourly remote-publication health cron is not scheduled.');
awvp_release_assert(false !== wp_next_scheduled(\ArgentVideo\Activator::BACKEND_MAINTENANCE_HOOK), 'Daily backend-maintenance cron is not scheduled.');

$republish_meta = registered_meta_key_exists('post', \ArgentVideo\Video_Meta::REMOTE_REPUBLISH_REQUEST, \ArgentVideo\Video_Post_Type::POST_TYPE);
awvp_release_assert($republish_meta, 'Durable remote-republish request meta is not registered for AWVP Video posts.');

awvp_release_assert(
    'operator_abandoned' === \ArgentVideo\PeerTube_Staged_Upload_State_Machine::PHASE_OPERATOR_ABANDONED
    && 'operator_abandon' === \ArgentVideo\PeerTube_Staged_Upload_State_Machine::EVENT_OPERATOR_ABANDON,
    'RC12 safe indeterminate-upload operator-retirement state contract is not packaged.'
);
awvp_release_assert(
    'argentwolf_video_processor_overview_resolve_upload' === \ArgentVideo\PeerTube_Overview_Admin::ACTION_RESOLVE_UPLOAD,
    'RC12 Overview indeterminate-upload resolution action is not packaged.'
);

echo "AWVP_RC12_LIVEFIX_CONTRACT_PASS\n";
