<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

global $wpdb;

awvp_release_assert_candidate_common();

$repo = new \ArgentVideo\Worker_Log_Repository();
$table = $wpdb->prefix . \ArgentVideo\Worker_Log_Repository::TABLE_SUFFIX;

$repo->clear_retained();
$wpdb->query(
    "DELETE FROM `{$table}` WHERE status IN ('launching', 'running')"
);

// Capture -> DB -> cleanup lifecycle.
$capture_run = $repo->create('manual');
$capture_path = $repo->allocate_capture($capture_run);
awvp_release_assert(is_file($capture_path), 'Temporary diagnostic capture was not created.');
$evidence = 'awvp-release-capture-' . wp_generate_uuid4();
awvp_release_assert(
    false !== file_put_contents($capture_path, $evidence),
    'Could not write temporary diagnostic evidence.'
);
$capture_reflection = new ReflectionClass($repo);
$safe_capture_path = $capture_reflection->getMethod('safe_capture_path');
$safe_capture_path->setAccessible(true);
$read_capture = $capture_reflection->getMethod('read_capture');
$read_capture->setAccessible(true);

$validated_capture_path = (string) $safe_capture_path->invoke($repo, $capture_path);
awvp_release_assert(
    '' !== $validated_capture_path,
    sprintf(
        'Temporary capture failed safety validation. path=%s basename=%s temp_dir=%s',
        $capture_path,
        basename($capture_path),
        get_temp_dir()
    )
);

$precomplete_output = (string) $read_capture->invoke($repo, $validated_capture_path);
awvp_release_assert(
    str_contains($precomplete_output, $evidence),
    sprintf(
        'Temporary capture could not be read before persistence. path=%s bytes=%d',
        $validated_capture_path,
        strlen($precomplete_output)
    )
);

$repo->mark_running($capture_run, max(1, getmypid()));
$repo->complete(
    $capture_run,
    array('processed' => 1, 'failed' => 0, 'recovered' => 0)
);
$capture_row = awvp_release_worker_row($capture_run);
awvp_release_assert('complete' === $capture_row['status'], 'Capture run did not complete.');
awvp_release_assert(
    str_contains((string) $capture_row['diagnostic_output'], $evidence),
    sprintf(
        'Temporary capture evidence was not persisted. status=%s message=%s output_bytes=%d capture_path=%s',
        (string) ($capture_row['status'] ?? ''),
        (string) ($capture_row['message'] ?? ''),
        strlen((string) ($capture_row['diagnostic_output'] ?? '')),
        (string) ($capture_row['capture_path'] ?? '')
    )
);
awvp_release_assert(
    null === $capture_row['capture_path'] || '' === (string) $capture_row['capture_path'],
    'Completed capture run retained a live capture path.'
);
awvp_release_assert(! is_file($capture_path), 'Persisted temporary capture was not deleted.');

// Stale/incomplete reconciliation must preserve evidence.
$stale_run = $repo->create('automatic');
$stale_path = $repo->allocate_capture($stale_run);
$stale_evidence = 'awvp-release-stale-' . wp_generate_uuid4();
awvp_release_assert(
    false !== file_put_contents($stale_path, $stale_evidence),
    'Could not write stale-run evidence.'
);
$repo->mark_running($stale_run, max(1, getmypid()));
$wpdb->update(
    $table,
    array('updated_at' => gmdate('Y-m-d H:i:s', time() - 600)),
    array('id' => $stale_run),
    array('%s'),
    array('%d')
);
$reconciled = $repo->reconcile_incomplete(false);
awvp_release_assert($reconciled >= 1, 'Stale worker run was not reconciled.');
$stale_row = awvp_release_worker_row($stale_run);
awvp_release_assert('failed' === $stale_row['status'], 'Stale worker run did not become failed.');
awvp_release_assert(
    str_contains((string) $stale_row['diagnostic_output'], $stale_evidence),
    'Stale worker evidence was lost during reconciliation.'
);
awvp_release_assert(! is_file($stale_path), 'Reconciled stale capture was not deleted.');

// Start retention fixture from a clean retained-history state.
$repo->clear_retained();
awvp_release_assert(0 === awvp_release_count_worker_bucket('success'), 'Could not clear success history.');
awvp_release_assert(0 === awvp_release_count_worker_bucket('error'), 'Could not clear error history.');

for ($i = 0; $i < 6; $i++) {
    $id = $repo->create('manual');
    $repo->mark_running($id, max(1, getmypid()));
    $repo->complete($id, array('processed' => 1, 'failed' => 0, 'recovered' => 0));
}

for ($i = 0; $i < 7; $i++) {
    $id = $repo->create('cli');
    $repo->mark_running($id, max(1, getmypid()));
    if (0 === $i % 2) {
        $repo->fail($id, 'release retention error sentinel', 1);
    } else {
        $repo->complete($id, array('processed' => 1, 'failed' => 1, 'recovered' => 0));
    }
}

$repo->prune(
    array(
        'worker_log_success_limit' => 2,
        'worker_log_error_limit'   => 3,
    )
);

awvp_release_assert(
    2 === awvp_release_count_worker_bucket('success'),
    'Successful worker retention limit was not enforced.'
);
awvp_release_assert(
    3 === awvp_release_count_worker_bucket('error'),
    'Error/job-error worker retention limit was not enforced.'
);
awvp_release_assert(
    0 === (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM `{$table}` WHERE capture_path IS NOT NULL AND capture_path <> ''"
    ),
    'Completed retention fixture left live capture paths.'
);

$repo->clear_retained();
awvp_release_assert(0 === awvp_release_count_worker_bucket('success'), 'Final success-history cleanup failed.');
awvp_release_assert(0 === awvp_release_count_worker_bucket('error'), 'Final error-history cleanup failed.');

echo "AWVP_RELEASE_WORKER_DIAGNOSTICS_PASS\n";
