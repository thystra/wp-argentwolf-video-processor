<?php
/**
 * Focused dependency-free tests for the PeerTube connection state contract.
 */

declare(strict_types=1);

namespace {
    require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
    require_once dirname(__DIR__) . '/includes/PeerTube_Connection_State_Machine.php';

    use ArgentVideo\PeerTube_Connection_State_Machine as Machine;

    $assert = static function (bool $condition, string $message): void {
        if (! $condition) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    /** @return array<string, mixed> */
    $intent = static function (): array {
        return array(
            'operation_id'    => 'connection_11111111111111111111111111111111',
            'backend_id'      => 'peertube-primary',
            'origin'          => 'https://video.example.org',
            'label'           => 'Primary PeerTube',
            'secret_ref'      => 'managed_22222222222222222222222222222222',
            'provisioning_id' => 'provision_33333333333333333333333333333333',
        );
    };

    /** @return array<string, mixed> */
    $mutation = static function (
        string $kind,
        string $id_digit,
        bool $before_exists,
        string $before_digit,
        string $after_digit
    ): array {
        return array(
            'kind'          => $kind,
            'mutation_id'   => 'mutation_' . str_repeat($id_digit, 32),
            'before_exists' => $before_exists,
            'before_sha256' => $before_exists ? str_repeat($before_digit, 64) : '',
            'before_bytes'  => $before_exists ? 512 : 0,
            'after_exists'  => true,
            'after_sha256'  => str_repeat($after_digit, 64),
            'after_bytes'   => 640,
        );
    };

    $identity = array(
        'user_id'      => '17',
        'username'     => 'awvp_service',
        'account_id'   => '23',
        'account_name' => 'awvp_service',
    );

    /**
     * @param array<string, mixed> $record
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    $advance = static function (
        array $record,
        string $event,
        array $payload,
        int $now
    ) use ($assert): array {
        $before = $record;
        $next = Machine::apply($record, $event, $payload, $now);

        $assert(is_array($next), 'Expected event to be accepted: ' . $event);
        $assert($before === $record, 'State-machine input was mutated: ' . $event);
        $assert(
            $before['record_revision'] + 1 === $next['record_revision'],
            'Accepted event did not increment the revision exactly once: ' . $event
        );
        $assert($now === $next['updated_at'], 'Accepted event did not record its exact timestamp: ' . $event);
        $assert(Machine::valid($next), 'Accepted event produced an invalid journal record: ' . $event);

        return $next;
    };

    /** @param array<string, mixed> $record */
    $assert_secret_hash_bound = static function (array $record) use ($assert): void {
        $tampered = $record;
        $tampered['secret_record_sha256'] = str_repeat('e', 64);
        $assert(
            ! Machine::valid($tampered),
            'Pre-activation phase accepted secret evidence that did not match its commit: '
                . $record['phase']
        );
    };

    /** @return array<string, mixed> */
    $disabled_record = static function () use ($advance, $intent, $mutation): array {
        $record = Machine::create($intent(), 7, 1000);
        if (! is_array($record)) {
            throw new \RuntimeException('Valid journal creation failed.');
        }

        $record = $advance(
            $record,
            Machine::EVENT_PLAN_SECRET_RESERVATION,
            $mutation('secret_reserve', '4', false, '0', 'a'),
            1001
        );
        $record = $advance($record, Machine::EVENT_CONFIRM_SECRET_RESERVED, array(), 1002);
        $record = $advance(
            $record,
            Machine::EVENT_PLAN_DISABLED_LINK,
            $mutation('registry_link', '5', true, 'b', 'c'),
            1003
        );
        return $advance($record, Machine::EVENT_CONFIRM_DISABLED_LINK, array(), 1004);
    };

    $record = Machine::create($intent(), 7, 1000);
    $assert(is_array($record), 'Valid version-1 journal record was not created.');
    $assert(Machine::PHASE_PREPARED === $record['phase'], 'New record did not begin prepared.');
    $assert(1 === $record['record_revision'], 'New record revision must begin at one.');
    $assert(Machine::valid($record), 'New record did not pass strict validation.');

    $invalid_intent = $intent();
    $invalid_intent['password'] = 'bootstrap-password-must-not-persist';
    $assert(
        null === Machine::create($invalid_intent, 7, 1000),
        'Create accepted an unexpected password field.'
    );

    $invalid_intent = $intent();
    $invalid_intent['access_token'] = 'returned-access-token-must-not-persist';
    unset($invalid_intent['label']);
    $assert(
        null === Machine::create($invalid_intent, 7, 1000),
        'Create accepted a token-bearing payload.'
    );

    $referenced_label = 'Primary PeerTube';
    $referenced_intent = $intent();
    $referenced_intent['label'] =& $referenced_label;
    $assert(
        null === Machine::create($referenced_intent, 7, 1000),
        'Create accepted a reference-bearing intent.'
    );

    foreach (
        array(
            array('backend_id', 'local'),
            array('backend_id', 'PeerTube-Primary'),
            array('origin', 'https://video.example.org/path'),
            array('origin', 'HTTPS://Video.Example.Org/'),
            array('label', "Bad\nLabel"),
            array('label', "Bad\xB1Label"),
            array('secret_ref', 'managed_not-hex'),
            array('provisioning_id', 'provision_not-hex'),
        ) as [$field, $value]
    ) {
        $candidate = $intent();
        $candidate[$field] = $value;
        $assert(null === Machine::create($candidate, 7, 1000), 'Invalid create field was accepted: ' . $field);
    }

    $assert(null === Machine::create($intent(), 0, 1000), 'Zero actor ID was accepted.');
    $assert(null === Machine::create($intent(), 7, 0), 'Zero creation timestamp was accepted.');

    $tampered = $record;
    $tampered['version'] = 2;
    $assert(! Machine::valid($tampered), 'Future journal version was accepted.');

    $tampered = $record;
    $tampered['record_revision'] = 0;
    $assert(! Machine::valid($tampered), 'Zero journal revision was accepted.');

    $tampered = $record;
    $tampered['phase'] = Machine::PHASE_COMPLETE;
    $assert(! Machine::valid($tampered), 'A caller-selected terminal phase passed validation.');

    $tampered = $record;
    $tampered['access_token'] = 'returned-access-token-must-not-persist';
    $assert(! Machine::valid($tampered), 'Token-bearing journal record passed validation.');

    $tampered = $record;
    $tampered['last_error']['detail'] = 'arbitrary remote detail';
    $assert(! Machine::valid($tampered), 'Arbitrary error detail passed journal validation.');

    $tampered = $record;
    $tampered['selected_destination'] = '41';
    $assert(! Machine::valid($tampered), 'Unrequested destination passed phase validation.');

    $referenced_record = $record;
    $referenced_record['created_by'] =& $referenced_record['record_revision'];
    $referenced_record_before = serialize($referenced_record);
    $assert(! Machine::valid($referenced_record), 'Reference-bearing journal record passed validation.');
    $assert(
        null === Machine::apply(
            $referenced_record,
            Machine::EVENT_PLAN_SECRET_RESERVATION,
            $mutation('secret_reserve', '4', false, '0', 'a'),
            1001
        ),
        'Reference-bearing journal record was accepted for transition.'
    );
    $assert(
        $referenced_record_before === serialize($referenced_record),
        'Rejected reference-bearing journal input was mutated.'
    );

    $assert(
        null === Machine::apply($record, Machine::EVENT_BEGIN_GRANT, array(
            'attempt_id' => 'attempt_66666666666666666666666666666666',
        ), 1001),
        'Prepared record skipped the local pregrant phases.'
    );
    $assert(
        null === Machine::apply($record, Machine::EVENT_CONFIRM_SECRET_RESERVED, array(), 1001),
        'Prepared record accepted an out-of-order confirmation.'
    );

    $bad_mutation = $mutation('secret_reserve', '4', false, '0', 'a');
    $bad_mutation['after_bytes'] = 1048577;
    $assert(
        null === Machine::apply($record, Machine::EVENT_PLAN_SECRET_RESERVATION, $bad_mutation, 1001),
        'Oversized mutation evidence was accepted.'
    );

    $maximum_mutation = $mutation('secret_reserve', '4', false, '0', 'a');
    $maximum_mutation['after_bytes'] = 1048576;
    $assert(
        is_array(Machine::apply(
            $record,
            Machine::EVENT_PLAN_SECRET_RESERVATION,
            $maximum_mutation,
            1001
        )),
        'Exact 1 MiB Atomic_Option_Store evidence ceiling was refused.'
    );

    $bad_mutation = $mutation('secret_reserve', '4', true, '9', 'a');
    $assert(
        null === Machine::apply($record, Machine::EVENT_PLAN_SECRET_RESERVATION, $bad_mutation, 1001),
        'Secret reservation accepted a pre-existing option snapshot.'
    );

    $bad_mutation = $mutation('secret_reserve', '4', true, 'a', 'a');
    $bad_mutation['after_bytes'] = $bad_mutation['before_bytes'];
    $assert(
        null === Machine::apply($record, Machine::EVENT_PLAN_SECRET_RESERVATION, $bad_mutation, 1001),
        'Mutation plan accepted identical before/after evidence.'
    );

    $bad_mutation = $mutation('secret_reserve', '4', false, '0', 'a');
    $bad_mutation['detail'] = 'arbitrary persistence detail';
    $assert(
        null === Machine::apply($record, Machine::EVENT_PLAN_SECRET_RESERVATION, $bad_mutation, 1001),
        'Mutation evidence accepted arbitrary detail.'
    );

    // A definite registry CAS conflict retains prospective ordering but needs
    // a new mutation identity and freshly derived evidence before confirmation.
    $link_replan = $advance(
        $record,
        Machine::EVENT_PLAN_SECRET_RESERVATION,
        $mutation('secret_reserve', '4', false, '0', 'a'),
        1001
    );
    $link_replan = $advance(
        $link_replan,
        Machine::EVENT_CONFIRM_SECRET_RESERVED,
        array(),
        1002
    );
    $assert(
        null === Machine::apply(
            $link_replan,
            Machine::EVENT_REPLAN_DISABLED_LINK,
            $mutation('registry_link', '6', true, 'b', 'c'),
            1003
        ),
        'Disabled-link replan was accepted before any durable plan.'
    );
    $link_replan = $advance(
        $link_replan,
        Machine::EVENT_PLAN_DISABLED_LINK,
        $mutation('registry_link', '5', true, 'b', 'c'),
        1003
    );
    $assert(
        null === Machine::apply(
            $link_replan,
            Machine::EVENT_REPLAN_DISABLED_LINK,
            $mutation('registry_link', '5', true, 'c', 'd'),
            1004
        ),
        'Disabled-link replan reused the stale mutation identity.'
    );
    $link_replan = $advance(
        $link_replan,
        Machine::EVENT_REPLAN_DISABLED_LINK,
        $mutation('registry_link', '6', true, 'c', 'd'),
        1004
    );
    $assert(
        Machine::PHASE_LINK_PLANNED === $link_replan['phase']
        && 'mutation_' . str_repeat('6', 32) === $link_replan['last_mutation']['mutation_id'],
        'Fresh disabled-link replan did not replace stale mutation evidence.'
    );
    $link_replan = $advance(
        $link_replan,
        Machine::EVENT_CONFIRM_DISABLED_LINK,
        array(),
        1005
    );
    $assert(
        Machine::PHASE_DISABLED === $link_replan['phase'],
        'Fresh disabled-link replan could not be confirmed.'
    );

    $disabled = $disabled_record();
    $assert(Machine::PHASE_DISABLED === $disabled['phase'], 'Local pregrant sequence did not reach disabled.');
    $assert(5 === $disabled['record_revision'], 'Local pregrant revision sequence was not monotonic.');
    $assert(
        null === Machine::apply(
            $disabled,
            Machine::EVENT_BEGIN_GRANT,
            array(
                'attempt_id' => 'attempt_66666666666666666666666666666666',
                'password'   => 'bootstrap-password-must-not-persist',
            ),
            1005
        ),
        'Grant event accepted a password.'
    );
    $assert(
        null === Machine::apply(
            $disabled,
            Machine::EVENT_BEGIN_GRANT,
            array(
                'attempt_id' => 'attempt_66666666666666666666666666666666',
                'phase'      => Machine::PHASE_SECRET_STORED,
            ),
            1005
        ),
        'Grant event accepted a caller-selected phase.'
    );
    $referenced_attempt_id = 'attempt_66666666666666666666666666666666';
    $referenced_grant_payload = array('attempt_id' => &$referenced_attempt_id);
    $assert(
        null === Machine::apply(
            $disabled,
            Machine::EVENT_BEGIN_GRANT,
            $referenced_grant_payload,
            1005
        ),
        'Grant event accepted a reference-bearing payload.'
    );
    $assert(
        null === Machine::apply(
            $disabled,
            Machine::EVENT_BEGIN_GRANT,
            array('attempt_id' => 'attempt_66666666666666666666666666666666'),
            1003
        ),
        'Transition accepted a timestamp older than the durable record.'
    );

    $indeterminate = $advance(
        $disabled,
        Machine::EVENT_BEGIN_GRANT,
        array('attempt_id' => 'attempt_66666666666666666666666666666666'),
        1005
    );
    $assert(Machine::PHASE_GRANT_IN_FLIGHT === $indeterminate['phase'], 'Grant did not enter in-flight.');
    $grant_in_flight_record = $indeterminate;
    $assert(
        null === Machine::apply(
            $indeterminate,
            Machine::EVENT_OTP_REQUIRED,
            array('http_status' => 400, 'retry_after' => 0),
            1006
        ),
        'OTP-required event accepted a non-authoritative HTTP status.'
    );
    $assert(
        null === Machine::apply(
            $indeterminate,
            Machine::EVENT_CREDENTIALS_REJECTED,
            array('reason' => 'rate_limited', 'http_status' => 400, 'retry_after' => 9),
            1006
        ),
        'Rate-limit event accepted a status other than 429.'
    );
    $assert(
        null === Machine::apply(
            $indeterminate,
            Machine::EVENT_GRANT_INDETERMINATE,
            array('reason' => 'remote_error', 'http_status' => 400),
            1006
        ),
        'Indeterminate remote error accepted a non-5xx status.'
    );
    $malformed_response = Machine::apply(
        $indeterminate,
        Machine::EVENT_GRANT_INDETERMINATE,
        array('reason' => 'invalid_response', 'http_status' => 302),
        1006
    );
    $assert(
        is_array($malformed_response)
            && Machine::PHASE_GRANT_INDETERMINATE === $malformed_response['phase'],
        'Unexpected non-success token response was not conservatively indeterminate.'
    );
    $assert(
        null === Machine::apply(
            $indeterminate,
            Machine::EVENT_GRANT_INDETERMINATE,
            array('reason' => 'invalid_response', 'http_status' => 429),
            1006
        ),
        'Authoritative 429 was accepted as an indeterminate grant outcome.'
    );

    $indeterminate = $advance(
        $indeterminate,
        Machine::EVENT_OTP_REQUIRED,
        array('http_status' => 401, 'retry_after' => 0),
        1006
    );
    $assert(Machine::PHASE_AWAITING_OTP === $indeterminate['phase'], 'OTP response was misclassified.');
    $awaiting_otp_record = $indeterminate;
    $tampered_otp = $indeterminate;
    $tampered_otp['last_error']['http_status'] = 400;
    $assert(! Machine::valid($tampered_otp), 'Stored OTP evidence accepted a non-401 status.');
    $assert(
        null === Machine::apply(
            $indeterminate,
            Machine::EVENT_BEGIN_GRANT,
            array('attempt_id' => 'attempt_66666666666666666666666666666666'),
            1007
        ),
        'Immediate grant retry reused the last attempt identity.'
    );

    $indeterminate = $advance(
        $indeterminate,
        Machine::EVENT_BEGIN_GRANT,
        array('attempt_id' => 'attempt_77777777777777777777777777777777'),
        1007
    );
    $indeterminate = $advance(
        $indeterminate,
        Machine::EVENT_CREDENTIALS_REJECTED,
        array('reason' => 'invalid_otp', 'http_status' => 400, 'retry_after' => 0),
        1008
    );
    $assert(
        Machine::PHASE_AWAITING_CREDENTIALS === $indeterminate['phase'],
        'Definitive credential rejection was misclassified.'
    );
    $awaiting_credentials_record = $indeterminate;

    $indeterminate = $advance(
        $indeterminate,
        Machine::EVENT_BEGIN_GRANT,
        array('attempt_id' => 'attempt_88888888888888888888888888888888'),
        1009
    );
    $indeterminate = $advance(
        $indeterminate,
        Machine::EVENT_GRANT_INDETERMINATE,
        array('reason' => 'process_interrupted', 'http_status' => 0),
        1010
    );
    $assert(
        Machine::PHASE_GRANT_INDETERMINATE === $indeterminate['phase'],
        'Interrupted grant did not become indeterminate.'
    );
    $assert(3 === $indeterminate['grant_attempt_no'], 'Grant attempt number was not monotonic.');

    foreach (
        array(
            $grant_in_flight_record,
            $awaiting_otp_record,
            $awaiting_credentials_record,
            $indeterminate,
        ) as $grant_phase_record
    ) {
        $tampered_grant_evidence = $grant_phase_record;
        $tampered_grant_evidence['last_mutation'] = $mutation(
            'registry_activate',
            '9',
            true,
            '8',
            '9'
        );
        $assert(
            ! Machine::valid($tampered_grant_evidence),
            'Grant-family phase accepted unreachable non-link mutation evidence: '
                . $grant_phase_record['phase']
        );
    }

    $valid_events_from_indeterminate = array(
        array(Machine::EVENT_PLAN_SECRET_RESERVATION, $mutation('secret_reserve', '1', false, '0', '1')),
        array(Machine::EVENT_CONFIRM_SECRET_RESERVED, array()),
        array(Machine::EVENT_PLAN_DISABLED_LINK, $mutation('registry_link', '2', true, '2', '3')),
        array(Machine::EVENT_REPLAN_DISABLED_LINK, $mutation('registry_link', '2', true, '3', '4')),
        array(Machine::EVENT_CONFIRM_DISABLED_LINK, array()),
        array(Machine::EVENT_BEGIN_GRANT, array('attempt_id' => 'attempt_99999999999999999999999999999999')),
        array(Machine::EVENT_OTP_REQUIRED, array('http_status' => 401, 'retry_after' => 0)),
        array(Machine::EVENT_CREDENTIALS_REJECTED, array(
            'reason' => 'invalid_credentials', 'http_status' => 400, 'retry_after' => 0,
        )),
        array(Machine::EVENT_GRANT_INDETERMINATE, array('reason' => 'transport_error', 'http_status' => 0)),
        array(Machine::EVENT_PLAN_SECRET_STORAGE, $mutation('secret_commit', '3', true, '4', '5')),
        array(Machine::EVENT_CONFIRM_SECRET_STORED, array()),
        array(Machine::EVENT_BEGIN_VERIFICATION, array()),
        array(Machine::EVENT_VERIFICATION_FAILED, array(
            'reason' => 'transport_error', 'http_status' => 0, 'retry_after' => 0,
        )),
        array(Machine::EVENT_VERIFICATION_SUCCEEDED, array('identity' => $identity, 'secret_generation' => 1)),
        array(Machine::EVENT_SELECT_DESTINATION, array('destination_id' => '41', 'actor_id' => 7)),
        array(Machine::EVENT_PLAN_ACTIVATION, $mutation('registry_activate', '6', true, '7', '8')),
        array(Machine::EVENT_REPLAN_ACTIVATION, $mutation('registry_activate', '7', true, '8', '9')),
        array(Machine::EVENT_CONFIRM_ACTIVATION, array()),
        array(Machine::EVENT_COMPLETE, array()),
    );

    foreach ($valid_events_from_indeterminate as [$event, $payload]) {
        $assert(
            null === Machine::apply($indeterminate, $event, $payload, 1011),
            'Grant-indeterminate state allowed an automatic transition: ' . $event
        );
    }

    $attempt_limited = $disabled;
    for ($attempt = 1; $attempt <= 8; $attempt++) {
        $attempt_id = 'attempt_' . str_pad(dechex($attempt), 32, '0', STR_PAD_LEFT);
        $attempt_limited = $advance(
            $attempt_limited,
            Machine::EVENT_BEGIN_GRANT,
            array('attempt_id' => $attempt_id),
            1100 + ($attempt * 2)
        );
        $attempt_limited = $advance(
            $attempt_limited,
            Machine::EVENT_CREDENTIALS_REJECTED,
            array('reason' => 'invalid_credentials', 'http_status' => 400, 'retry_after' => 0),
            1101 + ($attempt * 2)
        );
    }
    $assert(8 === $attempt_limited['grant_attempt_no'], 'Grant attempt ceiling was not reached exactly.');
    $assert(
        null === Machine::apply(
            $attempt_limited,
            Machine::EVENT_BEGIN_GRANT,
            array('attempt_id' => 'attempt_ffffffffffffffffffffffffffffffff'),
            1200
        ),
        'Ninth grant attempt exceeded the bounded operation ceiling.'
    );

    $happy = $advance(
        $disabled,
        Machine::EVENT_BEGIN_GRANT,
        array('attempt_id' => 'attempt_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
        1005
    );
    $happy = $advance(
        $happy,
        Machine::EVENT_PLAN_SECRET_STORAGE,
        $mutation('secret_commit', '6', true, 'a', 'd'),
        1006
    );
    $assert(
        Machine::PHASE_SECRET_WRITE_PLANNED === $happy['phase'],
        'Token success did not arm secret persistence.'
    );
    $happy = $advance($happy, Machine::EVENT_CONFIRM_SECRET_STORED, array(), 1007);
    $assert(Machine::PHASE_SECRET_STORED === $happy['phase'], 'Secret confirmation did not reach stored.');
    $assert(1 === $happy['secret_generation'], 'Initial secret generation was not one.');
    $assert(str_repeat('d', 64) === $happy['secret_record_sha256'], 'Secret evidence hash was not retained.');
    $assert_secret_hash_bound($happy);

    $happy = $advance($happy, Machine::EVENT_BEGIN_VERIFICATION, array(), 1008);
    $assert_secret_hash_bound($happy);
    $assert(
        null === Machine::apply(
            $happy,
            Machine::EVENT_VERIFICATION_FAILED,
            array('reason' => 'rate_limited', 'http_status' => 400, 'retry_after' => 17),
            1009
        ),
        'Verification rate-limit event accepted a status other than 429.'
    );
    $assert(
        null === Machine::apply(
            $happy,
            Machine::EVENT_VERIFICATION_FAILED,
            array('reason' => 'authentication_required', 'http_status' => 403, 'retry_after' => 0),
            1009
        ),
        'Verification authentication event accepted a status other than 401.'
    );
    $happy = $advance(
        $happy,
        Machine::EVENT_VERIFICATION_FAILED,
        array('reason' => 'rate_limited', 'http_status' => 429, 'retry_after' => 17),
        1009
    );
    $assert(Machine::PHASE_VERIFICATION_FAILED === $happy['phase'], 'Verification failure was not retained.');
    $assert(17 === $happy['last_error']['retry_after'], 'Bounded retry evidence was not retained.');
    $assert_secret_hash_bound($happy);
    $assert(
        null === Machine::apply(
            $happy,
            Machine::EVENT_SELECT_DESTINATION,
            array('destination_id' => '41', 'actor_id' => 7),
            1010
        ),
        'Destination was selected before any successful owned-channel verification.'
    );

    $happy = $advance($happy, Machine::EVENT_BEGIN_VERIFICATION, array(), 1010);
    $assert(
        null === Machine::apply(
            $happy,
            Machine::EVENT_VERIFICATION_SUCCEEDED,
            array('identity' => $identity, 'secret_generation' => 2),
            1011
        ),
        'Verification accepted an identity bound to a different secret generation.'
    );

    $referenced_username = 'awvp_service';
    $referenced_identity = $identity;
    $referenced_identity['username'] =& $referenced_username;
    $assert(
        null === Machine::apply(
            $happy,
            Machine::EVENT_VERIFICATION_SUCCEEDED,
            array('identity' => $referenced_identity, 'secret_generation' => 1),
            1011
        ),
        'Verification accepted a recursively reference-bearing payload.'
    );

    $identity_with_token = $identity;
    $identity_with_token['access_token'] = 'returned-access-token-must-not-persist';
    $assert(
        null === Machine::apply(
            $happy,
            Machine::EVENT_VERIFICATION_SUCCEEDED,
            array('identity' => $identity_with_token, 'secret_generation' => 1),
            1011
        ),
        'Verification accepted token-bearing identity data.'
    );

    $happy = $advance(
        $happy,
        Machine::EVENT_VERIFICATION_SUCCEEDED,
        array('identity' => $identity, 'secret_generation' => 1),
        1011
    );
    $assert(
        Machine::PHASE_AWAITING_DESTINATION === $happy['phase'],
        'First verified identity did not require destination selection.'
    );
    $assert($identity === $happy['verified_identity'], 'Verified identity projection was not retained exactly.');
    $assert_secret_hash_bound($happy);

    $assert(
        null === Machine::apply(
            $happy,
            Machine::EVENT_PLAN_ACTIVATION,
            $mutation('registry_activate', '7', true, 'e', 'f'),
            1012
        ),
        'Activation was planned without a selected, freshly verified destination.'
    );
    $assert(
        null === Machine::apply(
            $happy,
            Machine::EVENT_SELECT_DESTINATION,
            array('destination_id' => 41, 'actor_id' => 7),
            1012
        ),
        'Numeric destination was silently rewritten into an opaque string ID.'
    );

    $happy = $advance(
        $happy,
        Machine::EVENT_SELECT_DESTINATION,
        array('destination_id' => '41', 'actor_id' => 9),
        1012
    );
    $assert(
        Machine::PHASE_VERIFICATION_IN_FLIGHT === $happy['phase'],
        'Destination selection did not force fresh verification.'
    );
    $assert(
        '' === $happy['verified_identity']['user_id'] && 0 === $happy['verified_at'],
        'Destination selection reused stale identity evidence.'
    );

    $happy = $advance(
        $happy,
        Machine::EVENT_VERIFICATION_SUCCEEDED,
        array('identity' => $identity, 'secret_generation' => 1),
        1013
    );
    $assert(Machine::PHASE_ACTIVATION_READY === $happy['phase'], 'Fresh destination proof was not activation-ready.');
    $assert('41' === $happy['selected_destination'], 'Selected destination changed unexpectedly.');
    $assert(9 === $happy['activation_requested_by'], 'Activation actor was not retained.');
    $assert_secret_hash_bound($happy);
    $assert(
        null === Machine::apply(
            $happy,
            Machine::EVENT_REPLAN_ACTIVATION,
            $mutation('registry_activate', '8', true, 'f', 'a'),
            1014
        ),
        'Activation replan was accepted before any durable activation plan.'
    );

    $happy = $advance(
        $happy,
        Machine::EVENT_PLAN_ACTIVATION,
        $mutation('registry_activate', '7', true, 'e', 'f'),
        1014
    );
    $assert(Machine::PHASE_ACTIVATION_PLANNED === $happy['phase'], 'Activation plan was not durable.');
    $assert(
        null === Machine::apply(
            $happy,
            Machine::EVENT_REPLAN_ACTIVATION,
            $mutation('registry_activate', '7', true, 'f', 'a'),
            1015
        ),
        'Activation replan reused the stale mutation identity.'
    );
    $happy = $advance(
        $happy,
        Machine::EVENT_REPLAN_ACTIVATION,
        $mutation('registry_activate', '8', true, 'f', 'a'),
        1015
    );
    $assert(
        Machine::PHASE_ACTIVATION_PLANNED === $happy['phase']
        && 'mutation_' . str_repeat('8', 32) === $happy['last_mutation']['mutation_id'],
        'Fresh activation replan did not replace stale mutation evidence.'
    );
    $happy = $advance($happy, Machine::EVENT_CONFIRM_ACTIVATION, array(), 1016);
    $assert(
        Machine::PHASE_ACTIVE_PENDING_CLOSE === $happy['phase'],
        'Activation confirmation skipped cross-store closure.'
    );
    $happy = $advance($happy, Machine::EVENT_COMPLETE, array(), 1017);
    $assert(Machine::PHASE_COMPLETE === $happy['phase'], 'Cross-store closure did not complete.');
    $assert(18 === $happy['record_revision'], 'Happy-path revision history was not exact.');
    $assert(
        null === Machine::apply($happy, Machine::EVENT_BEGIN_VERIFICATION, array(), 1018),
        'Completed operation accepted a further event.'
    );

    $tampered_complete = $happy;
    $tampered_complete['secret_generation'] = 0;
    $tampered_complete['secret_record_sha256'] = '';
    $tampered_complete['verified_secret_generation'] = 0;
    $assert(! Machine::valid($tampered_complete), 'Completed operation without a durable secret was accepted.');

    $tampered_time = $happy;
    $tampered_time['grant_started_at'] = $happy['updated_at'] + 1;
    $assert(! Machine::valid($tampered_time), 'Future grant timestamp passed strict record validation.');

    $tampered_verification_time = $happy;
    $tampered_verification_time['verified_at'] = $happy['activation_requested_at'] - 1;
    $assert(
        ! Machine::valid($tampered_verification_time),
        'Activation accepted verification evidence older than the activation request.'
    );

    $serialized = serialize($happy) . serialize($indeterminate);
    foreach (
        array(
            'bootstrap-password-must-not-persist',
            'returned-access-token-must-not-persist',
            'arbitrary remote detail',
            'arbitrary persistence detail',
        ) as $forbidden_value
    ) {
        $assert(
            ! str_contains($serialized, $forbidden_value),
            'Journal retained forbidden credential/detail fixture: ' . $forbidden_value
        );
    }

    echo "AWVP PeerTube connection state-machine tests passed.\n";
}

// EOF: tests/peertube-connection-state-machine.php
