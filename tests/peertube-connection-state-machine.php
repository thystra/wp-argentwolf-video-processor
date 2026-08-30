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

    $capability_6 = str_repeat('0123456789abcdef', 4);
    $capability_7 = str_repeat('7', 64);
    $capability_8 = str_repeat('8', 64);
    $capability_9 = str_repeat('9', 64);
    $capability_a = str_repeat('a', 64);
    $capability_f = str_repeat('f', 64);

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
            'attempt_capability' => $capability_6,
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
            Machine::EVENT_GRANT_NOT_SENT,
            array(
                'attempt_capability' => $capability_6,
                'reason'             => 'local_prerequisite_changed',
                'http_status'        => 0,
                'retry_after'        => 0,
            ),
            1005
        ),
        'Definite grant-not-sent recovery was accepted before a durable grant attempt.'
    );
    $assert(
        null === Machine::apply(
            $disabled,
            Machine::EVENT_BEGIN_GRANT,
            array(
                'attempt_capability' => $capability_6,
                'password'           => 'bootstrap-password-must-not-persist',
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
                'attempt_capability' => $capability_6,
                'phase'              => Machine::PHASE_SECRET_STORED,
            ),
            1005
        ),
        'Grant event accepted a caller-selected phase.'
    );
    $referenced_attempt_capability = $capability_6;
    $referenced_grant_payload = array('attempt_capability' => &$referenced_attempt_capability);
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
            array('attempt_capability' => $capability_6),
            1003
        ),
        'Transition accepted a timestamp older than the durable record.'
    );
    foreach (array(str_repeat('6', 63), str_repeat('A', 64), 'not-hex') as $invalid_capability) {
        $assert(
            null === Machine::apply(
                $disabled,
                Machine::EVENT_BEGIN_GRANT,
                array('attempt_capability' => $invalid_capability),
                1005
            ),
            'Grant claim accepted a malformed attempt capability.'
        );
    }

    $indeterminate = $advance(
        $disabled,
        Machine::EVENT_BEGIN_GRANT,
        array('attempt_capability' => $capability_6),
        1005
    );
    $assert(Machine::PHASE_GRANT_IN_FLIGHT === $indeterminate['phase'], 'Grant did not enter in-flight.');
    $assert(
        'attempt_83ab3ecb297b53d05cdadd9735db6af4' === $indeterminate['grant_attempt_id']
            && ! str_contains(serialize($indeterminate), $capability_6),
        'Grant claim did not retain only the domain-separated capability commitment.'
    );
    $grant_in_flight_record = $indeterminate;
    $assert(
        null === Machine::apply(
            $grant_in_flight_record,
            Machine::EVENT_MARK_GRANT_REQUEST,
            array('attempt_capability' => $capability_7),
            1040
        ),
        'Request-start mark accepted an attempt identity other than the durable claim.'
    );
    $assert(
        null === Machine::apply(
            $grant_in_flight_record,
            Machine::EVENT_MARK_GRANT_REQUEST,
            array('attempt_capability' => str_repeat('A', 64)),
            1040
        ),
        'Request-start mark accepted a malformed attempt capability.'
    );
    $assert(
        null === Machine::apply(
            $grant_in_flight_record,
            Machine::EVENT_MARK_GRANT_REQUEST,
            array(
                'attempt_capability' => $capability_6,
                'username'           => 'credential-must-not-persist',
            ),
            1040
        ),
        'Request-start mark accepted a non-exact or credential-bearing payload.'
    );
    $marked_request = $advance(
        $grant_in_flight_record,
        Machine::EVENT_MARK_GRANT_REQUEST,
        array('attempt_capability' => $capability_6),
        1040
    );
    $assert(
        Machine::PHASE_GRANT_IN_FLIGHT === $marked_request['phase']
            && 1040 === $marked_request['grant_started_at']
            && 1040 === $marked_request['updated_at']
            && $grant_in_flight_record['grant_attempt_no'] === $marked_request['grant_attempt_no']
            && $grant_in_flight_record['grant_attempt_id'] === $marked_request['grant_attempt_id']
            && $grant_in_flight_record['last_mutation'] === $marked_request['last_mutation']
            && $grant_in_flight_record['last_error'] === $marked_request['last_error']
            && $grant_in_flight_record['secret_generation'] === $marked_request['secret_generation'],
        'Request-start mark did not refresh only the durable stale-attempt boundary.'
    );
    $assert(
        null === Machine::apply(
            $disabled,
            Machine::EVENT_MARK_GRANT_REQUEST,
            array('attempt_capability' => $capability_6),
            1040
        ),
        'Request-start mark was accepted outside an in-flight grant.'
    );

    $post_in_flight_record = $advance(
        $grant_in_flight_record,
        Machine::EVENT_MARK_GRANT_REQUEST,
        array('attempt_capability' => $capability_6),
        1006
    );
    foreach (
        array(
            array(
                'attempt_capability' => $capability_6,
                'reason' => 'transport_error', 'http_status' => 0, 'retry_after' => 0,
            ),
            array(
                'attempt_capability' => $capability_6,
                'reason' => 'local_prerequisite_changed', 'http_status' => 500, 'retry_after' => 0,
            ),
            array(
                'attempt_capability' => $capability_6,
                'reason' => 'local_prerequisite_changed', 'http_status' => 0, 'retry_after' => 1,
            ),
            array(
                'attempt_capability' => $capability_6,
                'reason' => 'local_prerequisite_changed',
                'http_status' => 0,
                'retry_after' => 0,
                'detail' => 'unbounded-local-detail',
            ),
        ) as $invalid_not_sent_payload
    ) {
        $assert(
            null === Machine::apply(
                $grant_in_flight_record,
                Machine::EVENT_GRANT_NOT_SENT,
                $invalid_not_sent_payload,
                1006
            ),
            'Grant-not-sent accepted a non-exact prerequisite-change payload.'
        );
    }

    $assert(
        null === Machine::apply(
            $grant_in_flight_record,
            Machine::EVENT_GRANT_NOT_SENT,
            array(
                'attempt_capability' => $capability_7,
                'reason'             => 'local_prerequisite_changed',
                'http_status'        => 0,
                'retry_after'        => 0,
            ),
            1006
        ),
        'Grant-not-sent accepted a forged attempt capability.'
    );
    $assert(
        null === Machine::apply(
            $grant_in_flight_record,
            Machine::EVENT_GRANT_NOT_SENT,
            array(
                'attempt_capability' => str_repeat('A', 64),
                'reason'             => 'local_prerequisite_changed',
                'http_status'        => 0,
                'retry_after'        => 0,
            ),
            1006
        ),
        'Grant-not-sent accepted a malformed attempt capability.'
    );

    $grant_not_sent = $advance(
        $grant_in_flight_record,
        Machine::EVENT_GRANT_NOT_SENT,
        array(
            'attempt_capability' => $capability_6,
            'reason'             => 'local_prerequisite_changed',
            'http_status'        => 0,
            'retry_after'        => 0,
        ),
        1006
    );
    $assert(
        Machine::PHASE_AWAITING_CREDENTIALS === $grant_not_sent['phase'],
        'A definite no-token grant outcome was not retryable.'
    );
    $assert(
        array('code' => 'peertube.auth.grant_not_sent', 'http_status' => 0, 'retry_after' => 0)
            === $grant_not_sent['last_error'],
        'Grant-not-sent did not retain its exact bounded diagnostic.'
    );
    $assert(
        $grant_in_flight_record['last_mutation'] === $grant_not_sent['last_mutation'],
        'Grant-not-sent changed the durable disabled-link evidence.'
    );
    $expired_request_window = Machine::apply(
        $post_in_flight_record,
        Machine::EVENT_GRANT_NOT_SENT,
        array(
            'attempt_capability' => $capability_6,
            'reason'             => 'request_window_expired',
            'http_status'        => 0,
            'retry_after'        => 0,
        ),
        1041
    );
    $assert(
        is_array($expired_request_window)
            && Machine::PHASE_AWAITING_CREDENTIALS === $expired_request_window['phase']
            && array('code' => 'peertube.auth.grant_not_sent', 'http_status' => 0, 'retry_after' => 0)
                === $expired_request_window['last_error'],
        'Expired request window did not become a capability-proved no-send result.'
    );

    $tampered_not_sent = $grant_not_sent;
    $tampered_not_sent['last_error']['http_status'] = 500;
    $assert(! Machine::valid($tampered_not_sent), 'Grant-not-sent record accepted a remote status.');
    $tampered_not_sent = $grant_not_sent;
    $tampered_not_sent['last_error']['code'] = 'peertube.connection.failed';
    $assert(! Machine::valid($tampered_not_sent), 'Grant-not-sent record accepted a substituted diagnostic.');

    $grant_not_sent_retry = $advance(
        $grant_not_sent,
        Machine::EVENT_BEGIN_GRANT,
        array('attempt_capability' => $capability_9),
        1007
    );
    $assert(
        2 === $grant_not_sent_retry['grant_attempt_no']
            && Machine::PHASE_GRANT_IN_FLIGHT === $grant_not_sent_retry['phase'],
        'An explicit retry did not advance from definite grant-not-sent recovery.'
    );
    $assert(
        null === Machine::apply(
            $post_in_flight_record,
            Machine::EVENT_OTP_REQUIRED,
            array('http_status' => 400, 'retry_after' => 0),
            1007
        ),
        'OTP-required event accepted a non-authoritative HTTP status.'
    );
    $assert(
        null === Machine::apply(
            $post_in_flight_record,
            Machine::EVENT_CREDENTIALS_REJECTED,
            array('reason' => 'rate_limited', 'http_status' => 400, 'retry_after' => 9),
            1007
        ),
        'Rate-limit event accepted a status other than 429.'
    );
    $assert(
        null === Machine::apply(
            $post_in_flight_record,
            Machine::EVENT_CREDENTIALS_REJECTED,
            array('reason' => 'permission_denied', 'http_status' => 401, 'retry_after' => 0),
            1007
        ),
        'Permission denial accepted an undocumented token status.'
    );
    $permission_denied_400_pending = $advance(
        $post_in_flight_record,
        Machine::EVENT_CREDENTIALS_REJECTED,
        array('reason' => 'permission_denied', 'http_status' => 400, 'retry_after' => 0),
        1007
    );
    $permission_denied_403_pending = $advance(
        $post_in_flight_record,
        Machine::EVENT_CREDENTIALS_REJECTED,
        array('reason' => 'permission_denied', 'http_status' => 403, 'retry_after' => 0),
        1007
    );
    $assert(
        Machine::PHASE_CREDENTIAL_RESULT_PENDING === $permission_denied_400_pending['phase']
            && Machine::PHASE_CREDENTIAL_RESULT_PENDING === $permission_denied_403_pending['phase'],
        'Definite credential results became retryable before local confirmation.'
    );
    $permission_denied_400 = $advance(
        $permission_denied_400_pending,
        Machine::EVENT_CONFIRM_GRANT_RESULT,
        array('attempt_capability' => $capability_6),
        1008
    );
    $permission_denied_403 = $advance(
        $permission_denied_403_pending,
        Machine::EVENT_CONFIRM_GRANT_RESULT,
        array('attempt_capability' => $capability_6),
        1008
    );
    $assert(
        'peertube.auth.permission_denied' === $permission_denied_400['last_error']['code']
            && 400 === $permission_denied_400['last_error']['http_status']
            && 'peertube.auth.permission_denied' === $permission_denied_403['last_error']['code']
            && 403 === $permission_denied_403['last_error']['http_status'],
        'Documented definite permission statuses did not retain bounded authority.'
    );
    $assert(
        null === Machine::apply(
            $post_in_flight_record,
            Machine::EVENT_GRANT_INDETERMINATE,
            array('reason' => 'remote_error', 'http_status' => 400),
            1007
        ),
        'Indeterminate remote error accepted a non-5xx status.'
    );
    $malformed_response = Machine::apply(
        $post_in_flight_record,
        Machine::EVENT_GRANT_INDETERMINATE,
        array('reason' => 'invalid_response', 'http_status' => 302),
        1007
    );
    $assert(
        is_array($malformed_response)
            && Machine::PHASE_GRANT_INDETERMINATE === $malformed_response['phase'],
        'Unexpected non-success token response was not conservatively indeterminate.'
    );
    $assert(
        null === Machine::apply(
            $post_in_flight_record,
            Machine::EVENT_GRANT_INDETERMINATE,
            array('reason' => 'invalid_response', 'http_status' => 429),
            1007
        ),
        'Authoritative 429 was accepted as an indeterminate grant outcome.'
    );

    $otp_result_pending = $advance(
        $post_in_flight_record,
        Machine::EVENT_OTP_REQUIRED,
        array('http_status' => 401, 'retry_after' => 0),
        1007
    );
    $assert(
        Machine::PHASE_OTP_RESULT_PENDING === $otp_result_pending['phase'],
        'OTP result became retryable before local confirmation.'
    );
    $assert(
        array('code' => 'peertube.auth.otp_required', 'http_status' => 401, 'retry_after' => 0)
            === $otp_result_pending['last_error'],
        'Pending OTP result did not retain its exact bounded evidence.'
    );
    $tampered_otp = $otp_result_pending;
    $tampered_otp['last_error']['http_status'] = 400;
    $assert(! Machine::valid($tampered_otp), 'Stored OTP evidence accepted a non-401 status.');
    $assert(
        null === Machine::apply(
            $otp_result_pending,
            Machine::EVENT_BEGIN_GRANT,
            array('attempt_capability' => $capability_7),
            1008
        ),
        'Unconfirmed OTP result permitted another credential-bearing attempt.'
    );
    $assert(
        null === Machine::apply(
            $otp_result_pending,
            Machine::EVENT_CONFIRM_GRANT_RESULT,
            array('attempt_capability' => $capability_6, 'retry' => true),
            1008
        ),
        'Grant-result confirmation accepted a non-empty payload.'
    );
    $assert(
        null === Machine::apply(
            $otp_result_pending,
            Machine::EVENT_CONFIRM_GRANT_RESULT,
            array('attempt_capability' => $capability_7),
            1008
        ),
        'Grant-result confirmation accepted a forged attempt capability.'
    );
    $assert(
        null === Machine::apply(
            $otp_result_pending,
            Machine::EVENT_CONFIRM_GRANT_RESULT,
            array('attempt_capability' => str_repeat('A', 64)),
            1008
        ),
        'Grant-result confirmation accepted a malformed attempt capability.'
    );
    $assert(
        null === Machine::apply(
            $otp_result_pending,
            Machine::EVENT_CONFIRM_GRANT_RESULT,
            array(),
            1008
        ),
        'Grant-result confirmation accepted a missing attempt capability.'
    );
    $assert(
        null === Machine::apply(
            $post_in_flight_record,
            Machine::EVENT_CONFIRM_GRANT_RESULT,
            array('attempt_capability' => $capability_6),
            1008
        ),
        'Grant-result confirmation was accepted without a pending result.'
    );
    $assert(
        null === Machine::apply(
            $otp_result_pending,
            Machine::EVENT_GRANT_INDETERMINATE,
            array('reason' => 'transport_error', 'http_status' => 0),
            1008
        ),
        'Pending OTP result accepted a non-local terminal uncertainty reason.'
    );
    $pending_otp_persistence_unknown = $advance(
        $otp_result_pending,
        Machine::EVENT_GRANT_INDETERMINATE,
        array('reason' => 'local_persistence_unknown', 'http_status' => 0),
        1008
    );
    $assert(
        Machine::PHASE_GRANT_INDETERMINATE === $pending_otp_persistence_unknown['phase'],
        'Unconfirmable pending OTP result did not fail closed.'
    );

    $indeterminate = $advance(
        $otp_result_pending,
        Machine::EVENT_CONFIRM_GRANT_RESULT,
        array('attempt_capability' => $capability_6),
        1008
    );
    $assert(Machine::PHASE_AWAITING_OTP === $indeterminate['phase'], 'OTP response was misclassified.');
    $awaiting_otp_record = $indeterminate;
    $assert(
        null === Machine::apply(
            $indeterminate,
            Machine::EVENT_BEGIN_GRANT,
            array('attempt_capability' => $capability_6),
            1009
        ),
        'Immediate grant retry reused the last attempt identity.'
    );

    $indeterminate = $advance(
        $indeterminate,
        Machine::EVENT_BEGIN_GRANT,
        array('attempt_capability' => $capability_7),
        1009
    );
    $indeterminate = $advance(
        $indeterminate,
        Machine::EVENT_MARK_GRANT_REQUEST,
        array('attempt_capability' => $capability_7),
        1010
    );
    $credential_result_pending = $advance(
        $indeterminate,
        Machine::EVENT_CREDENTIALS_REJECTED,
        array('reason' => 'invalid_otp', 'http_status' => 400, 'retry_after' => 0),
        1011
    );
    $assert(
        Machine::PHASE_CREDENTIAL_RESULT_PENDING === $credential_result_pending['phase'],
        'HTTP 400 invalid_two_factor result became retryable before local confirmation.'
    );
    $assert(
        array('code' => 'peertube.auth.invalid', 'http_status' => 400, 'retry_after' => 0)
            === $credential_result_pending['last_error'],
        'Pending credential result did not retain its exact bounded evidence.'
    );
    $assert(
        null === Machine::apply(
            $credential_result_pending,
            Machine::EVENT_BEGIN_GRANT,
            array('attempt_capability' => $capability_8),
            1012
        ),
        'Unconfirmed credential result permitted another credential-bearing attempt.'
    );
    $assert(
        null === Machine::apply(
            $credential_result_pending,
            Machine::EVENT_GRANT_INDETERMINATE,
            array('reason' => 'invalid_response', 'http_status' => 400),
            1012
        ),
        'Pending credential result accepted a non-local terminal uncertainty reason.'
    );
    $pending_credential_persistence_unknown = $advance(
        $credential_result_pending,
        Machine::EVENT_GRANT_INDETERMINATE,
        array('reason' => 'local_persistence_unknown', 'http_status' => 0),
        1012
    );
    $assert(
        Machine::PHASE_GRANT_INDETERMINATE === $pending_credential_persistence_unknown['phase'],
        'Unconfirmable pending credential result did not fail closed.'
    );
    $indeterminate = $advance(
        $credential_result_pending,
        Machine::EVENT_CONFIRM_GRANT_RESULT,
        array('attempt_capability' => $capability_7),
        1012
    );
    $assert(
        Machine::PHASE_AWAITING_CREDENTIALS === $indeterminate['phase'],
        'Confirmed invalid OTP result was not retained as retryable.'
    );
    $awaiting_credentials_record = $indeterminate;

    foreach (array($awaiting_otp_record, $awaiting_credentials_record) as $recorded_outcome) {
        $post_outcome_conflict = $advance(
            $recorded_outcome,
            Machine::EVENT_GRANT_INDETERMINATE,
            array('reason' => 'local_persistence_unknown', 'http_status' => 0),
            $recorded_outcome['updated_at'] + 1
        );
        $assert(
            Machine::PHASE_GRANT_INDETERMINATE === $post_outcome_conflict['phase']
                && $recorded_outcome['last_mutation'] === $post_outcome_conflict['last_mutation'],
            'A conflicting definite outcome observed after the token POST was not made terminal.'
        );
        $assert(
            null === Machine::apply(
                $recorded_outcome,
                Machine::EVENT_GRANT_INDETERMINATE,
                array('reason' => 'transport_error', 'http_status' => 0),
                $recorded_outcome['updated_at'] + 1
            ),
            'A recorded definite outcome accepted an unrelated indeterminate reason.'
        );
    }

    $indeterminate = $advance(
        $indeterminate,
        Machine::EVENT_BEGIN_GRANT,
        array('attempt_capability' => $capability_8),
        1013
    );
    $indeterminate = $advance(
        $indeterminate,
        Machine::EVENT_MARK_GRANT_REQUEST,
        array('attempt_capability' => $capability_8),
        1014
    );
    $indeterminate = $advance(
        $indeterminate,
        Machine::EVENT_GRANT_INDETERMINATE,
        array('reason' => 'process_interrupted', 'http_status' => 0),
        1015
    );
    $assert(
        Machine::PHASE_GRANT_INDETERMINATE === $indeterminate['phase'],
        'Interrupted grant did not become indeterminate.'
    );
    $assert(3 === $indeterminate['grant_attempt_no'], 'Grant attempt number was not monotonic.');

    foreach (
        array(
            $grant_in_flight_record,
            $marked_request,
            $grant_not_sent,
            $otp_result_pending,
            $credential_result_pending,
            $awaiting_otp_record,
            $awaiting_credentials_record,
            $permission_denied_400,
            $permission_denied_403,
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
        array(Machine::EVENT_BEGIN_GRANT, array('attempt_capability' => $capability_9)),
        array(Machine::EVENT_MARK_GRANT_REQUEST, array(
            'attempt_capability' => $capability_8,
        )),
        array(Machine::EVENT_GRANT_NOT_SENT, array(
            'attempt_capability' => $capability_8,
            'reason' => 'local_prerequisite_changed', 'http_status' => 0, 'retry_after' => 0,
        )),
        array(Machine::EVENT_OTP_REQUIRED, array('http_status' => 401, 'retry_after' => 0)),
        array(Machine::EVENT_CREDENTIALS_REJECTED, array(
            'reason' => 'invalid_credentials', 'http_status' => 400, 'retry_after' => 0,
        )),
        array(Machine::EVENT_CONFIRM_GRANT_RESULT, array('attempt_capability' => $capability_8)),
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
            null === Machine::apply($indeterminate, $event, $payload, $indeterminate['updated_at'] + 1),
            'Grant-indeterminate state allowed an automatic transition: ' . $event
        );
    }

    $attempt_limited = $disabled;
    for ($attempt = 1; $attempt <= 8; $attempt++) {
        $attempt_capability = str_pad(dechex($attempt), 64, '0', STR_PAD_LEFT);
        $attempt_limited = $advance(
            $attempt_limited,
            Machine::EVENT_BEGIN_GRANT,
            array('attempt_capability' => $attempt_capability),
            1100 + ($attempt * 4)
        );
        $attempt_limited = $advance(
            $attempt_limited,
            Machine::EVENT_MARK_GRANT_REQUEST,
            array('attempt_capability' => $attempt_capability),
            1101 + ($attempt * 4)
        );
        $attempt_limited = $advance(
            $attempt_limited,
            Machine::EVENT_CREDENTIALS_REJECTED,
            array('reason' => 'invalid_credentials', 'http_status' => 400, 'retry_after' => 0),
            1102 + ($attempt * 4)
        );
        $attempt_limited = $advance(
            $attempt_limited,
            Machine::EVENT_CONFIRM_GRANT_RESULT,
            array('attempt_capability' => $attempt_capability),
            1103 + ($attempt * 4)
        );
    }
    $assert(8 === $attempt_limited['grant_attempt_no'], 'Grant attempt ceiling was not reached exactly.');
    $assert(
        null === Machine::apply(
            $attempt_limited,
            Machine::EVENT_BEGIN_GRANT,
            array('attempt_capability' => $capability_f),
            1200
        ),
        'Ninth grant attempt exceeded the bounded operation ceiling.'
    );

    $happy = $advance(
        $disabled,
        Machine::EVENT_BEGIN_GRANT,
        array('attempt_capability' => $capability_a),
        1005
    );
    $happy = $advance(
        $happy,
        Machine::EVENT_MARK_GRANT_REQUEST,
        array('attempt_capability' => $capability_a),
        1006
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
    $secret_write_planned = $happy;
    foreach (
        array(
            array('reason' => 'transport_error', 'http_status' => 0),
            array('reason' => 'remote_error', 'http_status' => 500),
            array('reason' => 'invalid_response', 'http_status' => 200),
            array('reason' => 'process_interrupted', 'http_status' => 0),
            array('reason' => 'local_persistence_unknown', 'http_status' => 200),
        ) as $invalid_secret_indeterminate_payload
    ) {
        $assert(
            null === Machine::apply(
                $secret_write_planned,
                Machine::EVENT_GRANT_INDETERMINATE,
                $invalid_secret_indeterminate_payload,
                1007
            ),
            'Planned secret write accepted an inexact terminal uncertainty reason/status.'
        );
    }

    $secret_persistence_unknown = $advance(
        $secret_write_planned,
        Machine::EVENT_GRANT_INDETERMINATE,
        array('reason' => 'local_persistence_unknown', 'http_status' => 0),
        1007
    );
    $assert(
        Machine::PHASE_GRANT_INDETERMINATE === $secret_persistence_unknown['phase'],
        'Unknown post-grant secret persistence did not become terminal.'
    );
    $assert(
        $secret_write_planned['last_mutation'] === $secret_persistence_unknown['last_mutation']
            && 'secret_commit' === $secret_persistence_unknown['last_mutation']['kind'],
        'Terminal post-grant uncertainty did not retain exact secret-commit evidence.'
    );
    $assert(
        0 === $secret_persistence_unknown['secret_generation']
            && '' === $secret_persistence_unknown['secret_record_sha256'],
        'Unknown secret persistence was falsely confirmed as stored.'
    );

    $tampered_secret_indeterminate = $secret_persistence_unknown;
    $tampered_secret_indeterminate['last_error']['code'] = 'peertube.connection.failed';
    $assert(
        ! Machine::valid($tampered_secret_indeterminate),
        'Secret-commit terminal state accepted a non-persistence diagnostic.'
    );
    $tampered_secret_indeterminate = $secret_persistence_unknown;
    $tampered_secret_indeterminate['last_mutation']['after_sha256'] = 'not-a-sha256';
    $assert(
        ! Machine::valid($tampered_secret_indeterminate),
        'Secret-commit terminal state accepted malformed prospective evidence.'
    );

    foreach ($valid_events_from_indeterminate as [$event, $payload]) {
        if (Machine::EVENT_CONFIRM_SECRET_STORED === $event) {
            continue;
        }

        $assert(
            null === Machine::apply($secret_persistence_unknown, $event, $payload, 1008),
            'Secret-persistence-indeterminate state allowed an automatic transition: ' . $event
        );
    }
    $assert(
        null === Machine::apply(
            $secret_persistence_unknown,
            Machine::EVENT_GRANT_INDETERMINATE,
            array('reason' => 'local_persistence_unknown', 'http_status' => 0),
            1008
        ),
        'Secret-persistence-indeterminate state accepted duplicate terminal classification.'
    );
    $assert(
        null === Machine::apply(
            $secret_persistence_unknown,
            Machine::EVENT_CONFIRM_SECRET_STORED,
            array('unexpected' => true),
            1008
        ),
        'Recovered secret confirmation accepted a non-empty payload.'
    );
    $recovered_secret = $advance(
        $secret_persistence_unknown,
        Machine::EVENT_CONFIRM_SECRET_STORED,
        array(),
        1008
    );
    $assert(
        Machine::PHASE_SECRET_STORED === $recovered_secret['phase']
            && 1 === $recovered_secret['secret_generation']
            && str_repeat('d', 64) === $recovered_secret['secret_record_sha256']
            && array('code' => '', 'http_status' => 0, 'retry_after' => 0)
                === $recovered_secret['last_error']
            && $secret_persistence_unknown['last_mutation'] === $recovered_secret['last_mutation'],
        'Exact secret-commit evidence did not recover indeterminate persistence to stored.'
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
    $assert(19 === $happy['record_revision'], 'Happy-path revision history was not exact.');
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

    $serialized = serialize($happy)
        . serialize($indeterminate)
        . serialize($grant_not_sent)
        . serialize($otp_result_pending)
        . serialize($credential_result_pending)
        . serialize($secret_persistence_unknown);
    foreach (
        array(
            'bootstrap-password-must-not-persist',
            'returned-access-token-must-not-persist',
            'arbitrary remote detail',
            'arbitrary persistence detail',
            $capability_6,
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
