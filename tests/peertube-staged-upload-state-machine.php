<?php
/**
 * Focused tests for the R42 staged-upload mutation/reconciliation state contract.
 */

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Input.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Source_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Upload_State_Machine.php';

use ArgentVideo\PeerTube_Staged_Upload_State_Machine as Machine;

$assert = static function (bool $condition, string $message): void {
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$source = array(
    'kind'          => 'wordpress_staging',
    'relative_path' => '77/staging/source.mp4',
    'sha256'        => str_repeat('a', 64),
    'bytes'         => 123456,
);

$make = static function (string $operation_id = 'upload_11111111111111111111111111111111') use ($source, $assert): array {
    $record = Machine::create(
        array(
            'operation_id'   => $operation_id,
            'video_post_id'  => 77,
            'backend_id'     => 'peertube-primary',
            'origin'         => 'https://video.example.org',
            'destination_id' => '41',
            'source'         => $source,
        ),
        7,
        1000
    );
    $assert(is_array($record), 'Could not create a valid staged-upload record.');
    return $record;
};

$remote = array(
    'id'   => '901',
    'uuid' => '12345678-1234-4abc-9def-1234567890ab',
);
$cap1 = str_repeat('1', 64);
$cap2 = str_repeat('2', 64);

$record = $make();
$assert(Machine::PHASE_READY === $record['phase'], 'New upload operation did not start ready.');
$assert(1 === $record['record_revision'], 'New upload operation revision drifted.');
$assert(0 === $record['upload_attempt_no'], 'New upload operation has an attempt before claim.');
$assert('' === $record['remote_identity']['id'], 'New upload operation contains remote identity.');
$assert(1 === preg_match('/^[a-f0-9]{64}$/D', $record['intent_sha256']), 'Intent commitment is not a SHA-256.');

$claimed = Machine::apply(
    $record,
    Machine::EVENT_CLAIM_UPLOAD,
    array('attempt_capability' => $cap1),
    1001
);
$assert(is_array($claimed), 'Upload claim was refused.');
$assert(Machine::PHASE_UPLOAD_IN_FLIGHT === $claimed['phase'], 'Upload claim did not enter in-flight.');
$assert(1 === $claimed['upload_attempt_no'], 'Upload claim did not increment attempt count.');
$assert('' !== $claimed['upload_attempt_id'], 'Upload claim did not persist an attempt commitment.');
$assert($cap1 !== $claimed['upload_attempt_id'], 'Raw attempt capability leaked into durable state.');

$wrong_cap = Machine::apply(
    $claimed,
    Machine::EVENT_REMOTE_CREATED,
    array('attempt_capability' => $cap2, 'remote_identity' => $remote),
    1002
);
$assert(null === $wrong_cap, 'A stale/wrong attempt capability could commit remote identity.');

$indeterminate = Machine::apply(
    $claimed,
    Machine::EVENT_UPLOAD_INDETERMINATE,
    array(
        'attempt_capability' => $cap1,
        'code'               => 'peertube.upload.indeterminate',
        'http_status'        => 0,
    ),
    1002
);
$assert(is_array($indeterminate), 'Uncertain upload outcome was not durably representable.');
$assert(Machine::PHASE_UPLOAD_INDETERMINATE === $indeterminate['phase'], 'Uncertain outcome did not fence replay.');
$assert(
    null === Machine::apply(
        $indeterminate,
        Machine::EVENT_CLAIM_UPLOAD,
        array('attempt_capability' => $cap2),
        1003
    ),
    'An indeterminate upload could be claimed again for silent replay.'
);
$assert(
    null === Machine::apply(
        $indeterminate,
        Machine::EVENT_UPLOAD_RETRY_SAFE,
        array(
            'attempt_capability' => $cap1,
            'code'               => 'peertube.upload.request_not_sent',
            'http_status'        => 0,
            'retry_after'        => 0,
        ),
        1003
    ),
    'An indeterminate upload could be downgraded to retryable without reconciliation.'
);

$reconciled = Machine::apply(
    $indeterminate,
    Machine::EVENT_RECONCILE_REMOTE_FOUND,
    array('remote_identity' => $remote),
    1003
);
$assert(is_array($reconciled), 'Read-only reconciliation could not record a found remote identity.');
$assert(Machine::PHASE_REMOTE_CREATED === $reconciled['phase'], 'Found remote identity did not enter remote-created state.');
$assert($remote === $reconciled['remote_identity'], 'Reconciled remote identity drifted.');
$assert(0 === $reconciled['remote_asset_id'], 'Remote identity was falsely treated as a committed AWVP remote asset.');

$committed = Machine::apply(
    $reconciled,
    Machine::EVENT_COMMIT_REMOTE_ASSET,
    array('remote_asset_id' => 55),
    1004
);
$assert(is_array($committed), 'Durable remote-asset commit acknowledgement was refused.');
$assert(Machine::PHASE_REMOTE_COMMITTED === $committed['phase'], 'Remote asset commit phase drifted.');
$assert(55 === $committed['remote_asset_id'], 'Remote asset ID was not retained.');

$processing = Machine::apply($committed, Machine::EVENT_PROCESSING_OBSERVED, array(), 1005);
$assert(is_array($processing) && Machine::PHASE_PROCESSING === $processing['phase'], 'Processing observation failed.');
$ready = Machine::apply($processing, Machine::EVENT_READY_VERIFIED, array(), 1006);
$assert(is_array($ready) && Machine::PHASE_READY_VERIFIED === $ready['phase'], 'Positive ready verification failed.');
$assert(1006 === $ready['verified_at'], 'Positive ready verification timestamp drifted.');

$assert(
    null === Machine::apply($committed, Machine::EVENT_PLAN_SOURCE_CLEANUP, array(), 1005),
    'Source cleanup was allowed before positive ready verification.'
);
$cleanup = Machine::apply($ready, Machine::EVENT_PLAN_SOURCE_CLEANUP, array(), 1007);
$assert(is_array($cleanup) && Machine::PHASE_CLEANUP_PENDING === $cleanup['phase'], 'Cleanup gate did not open after verification.');
$complete = Machine::apply($cleanup, Machine::EVENT_CONFIRM_SOURCE_CLEANUP, array(), 1008);
$assert(is_array($complete) && Machine::PHASE_COMPLETE === $complete['phase'], 'Cleanup confirmation did not complete the operation.');

// A future uploader may classify a reviewed 429 response as retry-safe only
// when that concrete HTTP contract proves the response created no remote video.
// The bounded wait clears the old attempt commitment; the retry is a new explicit claim.
$rate = $make('upload_22222222222222222222222222222222');
$rate = Machine::apply($rate, Machine::EVENT_CLAIM_UPLOAD, array('attempt_capability' => $cap1), 1100);
$assert(is_array($rate), 'Rate-limit fixture claim failed.');
$rate = Machine::apply(
    $rate,
    Machine::EVENT_UPLOAD_RETRY_SAFE,
    array(
        'attempt_capability' => $cap1,
        'code'               => 'peertube.upload.rate_limited',
        'http_status'        => 429,
        'retry_after'        => 30,
    ),
    1101
);
$assert(is_array($rate) && Machine::PHASE_RETRY_WAIT === $rate['phase'], '429 did not enter retry wait.');
$assert('' === $rate['upload_attempt_id'] && 0 === $rate['upload_started_at'], 'Retry-safe result did not clear the current attempt claim.');
$assert(null === Machine::apply($rate, Machine::EVENT_RESUME_AFTER_WAIT, array(), 1130), 'Retry wait resumed too early.');
$rate = Machine::apply($rate, Machine::EVENT_RESUME_AFTER_WAIT, array(), 1131);
$assert(is_array($rate) && Machine::PHASE_READY === $rate['phase'], 'Retry wait did not resume at the exact boundary.');
$rate2 = Machine::apply($rate, Machine::EVENT_CLAIM_UPLOAD, array('attempt_capability' => $cap2), 1132);
$assert(is_array($rate2) && 2 === $rate2['upload_attempt_no'], 'Explicit retry did not create a second distinct attempt.');

$forged_retry = $rate;
$forged_retry['last_error'] = array(
    'code'        => 'peertube.upload.request_not_sent',
    'http_status' => 500,
    'retry_after' => 0,
);
$assert(! Machine::valid($forged_retry), 'Forged retry-safe evidence with a remote HTTP status was accepted.');

// A successful upload result is accepted only from the exact in-flight claim.
$success = $make('upload_33333333333333333333333333333333');
$success = Machine::apply($success, Machine::EVENT_CLAIM_UPLOAD, array('attempt_capability' => $cap1), 1200);
$success = is_array($success) ? Machine::apply(
    $success,
    Machine::EVENT_REMOTE_CREATED,
    array('attempt_capability' => $cap1, 'remote_identity' => $remote),
    1201
) : null;
$assert(is_array($success) && Machine::PHASE_REMOTE_CREATED === $success['phase'], 'Exact upload success was not accepted.');

// Secrets and authentication artifacts are structurally forbidden from the
// durable operation record and event payloads.
$poison = $source;
$poison['access_token'] = 'plaintext-canary';
$assert(
    null === Machine::create(
        array(
            'operation_id'   => 'upload_44444444444444444444444444444444',
            'video_post_id'  => 77,
            'backend_id'     => 'peertube-primary',
            'origin'         => 'https://video.example.org',
            'destination_id' => '41',
            'source'         => $poison,
        ),
        7,
        1300
    ),
    'Forbidden credential-like durable input was accepted.'
);

fwrite(STDOUT, "PeerTube staged upload state-machine tests passed.\n");
