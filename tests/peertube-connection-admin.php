<?php
/**
 * Focused dependency-free tests for the PeerTube administrator boundary.
 */

declare(strict_types=1);

define(
    'ARGENT_VIDEO_PEERTUBE_DEV_ORIGINS',
    array('http://peertube.test:9000')
);

$GLOBALS['awvp_admin_capable'] = true;
$GLOBALS['awvp_admin_user_id'] = 17;
$GLOBALS['awvp_admin_expected_nonce_action'] = '';
$GLOBALS['awvp_admin_nonce_valid'] = true;
$GLOBALS['awvp_admin_nonce_calls'] = array();
$GLOBALS['awvp_admin_pages'] = array();

final class Awvp_Admin_Die extends RuntimeException
{
}

final class Awvp_Admin_Redirect extends RuntimeException
{
    public function __construct(public readonly string $url, public readonly int $status)
    {
        parent::__construct('Synthetic redirect termination.');
    }
}

function __(string $text, string $domain = 'default'): string
{
    unset($domain);
    return $text;
}

function esc_html__(string $text, string $domain = 'default'): string
{
    return esc_html(__($text, $domain));
}

function esc_attr__(string $text, string $domain = 'default'): string
{
    return esc_attr(__($text, $domain));
}

function esc_html_e(string $text, string $domain = 'default'): void
{
    echo esc_html__($text, $domain);
}

function esc_attr_e(string $text, string $domain = 'default'): void
{
    echo esc_attr__($text, $domain);
}

function esc_html(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_attr(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function esc_url(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function sanitize_text_field(mixed $value): string
{
    return is_string($value) ? trim(strip_tags($value)) : '';
}

function sanitize_key(mixed $value): string
{
    return is_string($value)
        ? strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value) ?? '')
        : '';
}

function wp_unslash(mixed $value): mixed
{
    return is_string($value) ? stripslashes($value) : $value;
}

/** @return array<string, mixed>|int|string|false|null */
function wp_parse_url(string $url, int $component = -1): array|int|string|false|null
{
    return -1 === $component ? parse_url($url) : parse_url($url, $component);
}

function current_user_can(string $capability): bool
{
    return 'manage_options' === $capability && true === $GLOBALS['awvp_admin_capable'];
}

function get_current_user_id(): int
{
    return (int) $GLOBALS['awvp_admin_user_id'];
}

function wp_verify_nonce(string $nonce, string $action): int|false
{
    $GLOBALS['awvp_admin_nonce_calls'][] = array($nonce, $action);
    return true === $GLOBALS['awvp_admin_nonce_valid']
        && 'valid-nonce' === $nonce
        && $GLOBALS['awvp_admin_expected_nonce_action'] === $action
            ? 1
            : false;
}

function wp_die(string $message): never
{
    throw new Awvp_Admin_Die($message);
}

function wp_safe_redirect(string $url, int $status = 302, string|false $by = 'WordPress'): bool
{
    unset($url, $status, $by);
    throw new RuntimeException('The injected redirector should own focused test redirects.');
}

function admin_url(string $path = ''): string
{
    return 'https://wordpress.example/wp-admin/' . ltrim($path, '/');
}

/** @param array<string, scalar> $arguments */
function add_query_arg(array $arguments, string $url): string
{
    return $url . '?' . http_build_query($arguments, '', '&', PHP_QUERY_RFC3986);
}

function add_options_page(
    string $page_title,
    string $menu_title,
    string $capability,
    string $menu_slug,
    callable $callback
): string {
    $GLOBALS['awvp_admin_pages'][] = array(
        $page_title,
        $menu_title,
        $capability,
        $menu_slug,
        $callback,
    );
    return 'settings_page_' . $menu_slug;
}

function wp_nonce_field(
    string $action = '-1',
    string $name = '_wpnonce',
    bool $referer = true,
    bool $display = true
): string {
    unset($referer);
    $field = '<input type="hidden" name="' . esc_attr($name)
        . '" value="nonce-for-' . esc_attr($action) . '">';
    if ($display) {
        echo $field;
    }
    return $field;
}

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Input.php';
require_once dirname(__DIR__) . '/includes/Atomic_Option_Result.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_State_Machine.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Coordinator.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Password_Grant_Api.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Password_Grant_Service.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Identity_Destination_Api.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Identity_Destination_Service.php';
require_once dirname(__DIR__) . '/includes/Backend_Registry.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Backend_Activation_Service.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Upload_Policy.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Upload_Policy_Store.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Admin_Actions.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Admin.php';

use ArgentVideo\Atomic_Option_Result;
use ArgentVideo\PeerTube_Connection_Admin;
use ArgentVideo\PeerTube_Connection_Admin_Actions;
use ArgentVideo\PeerTube_Connection_Coordinator;
use ArgentVideo\PeerTube_Connection_Input;
use ArgentVideo\PeerTube_Connection_State_Machine as Machine;
use ArgentVideo\PeerTube_Identity_Destination_Service;
use ArgentVideo\PeerTube_Backend_Activation_Service;
use ArgentVideo\PeerTube_Password_Grant_Service;

final class Awvp_Admin_Fake_Actions implements PeerTube_Connection_Admin_Actions
{
    /** @var list<array<string, mixed>>|null */
    public ?array $operations = array();

    /** @var list<array{method:string,args:list<mixed>}> */
    public array $calls = array();

    /** @var array<string, mixed> */
    public array $start_result;

    /** @var array<string, mixed> */
    public array $resume_result;

    /** @var array<string, mixed> */
    public array $grant_result;

    /** @var array<string, mixed> */
    public array $reconcile_result;

    /** @var array<string, mixed> */
    public array $verify_result;

    /** @var array<string, mixed> */
    public array $discover_result;

    /** @var array<string, mixed> */
    public array $select_result;

    /** @var array<string, mixed> */
    public array $activate_result;

    /** @var array<string, mixed> */
    public array $refresh_result = array('status' => 'advanced');

    /** @var array<string, mixed> */
    public array $disconnect_result = array('status' => 'advanced');

    /** @var array{status:string} */
    public array $upload_policy_result = array('status' => 'applied');

    /** @var list<array<string,mixed>> */
    public array $backends = array();

    public bool $throw = false;

    public function __construct()
    {
        $this->start_result = awvp_admin_result(
            PeerTube_Connection_Coordinator::STATUS_ADVANCED,
            Atomic_Option_Result::MUTATION_APPLIED
        );
        $this->resume_result = $this->start_result;
        $this->grant_result = awvp_admin_grant_result(
            PeerTube_Password_Grant_Service::STATUS_READY_FOR_VERIFICATION,
            Atomic_Option_Result::MUTATION_APPLIED
        );
        $this->reconcile_result = awvp_admin_grant_result(
            PeerTube_Password_Grant_Service::STATUS_READY_FOR_VERIFICATION,
            Atomic_Option_Result::MUTATION_NONE
        );
        $this->verify_result = awvp_admin_identity_result(
            PeerTube_Identity_Destination_Service::STATUS_ADVANCED,
            Atomic_Option_Result::MUTATION_APPLIED,
            'connection_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            Machine::PHASE_VERIFICATION_IN_FLIGHT
        );
        $this->discover_result = awvp_admin_discovery_result();
        $this->select_result = $this->verify_result;
        $this->activate_result = awvp_admin_activation_result();
    }

    public function start(array $intent, int $actor_id, int $now): array
    {
        $this->called('start', array($intent, $actor_id, $now));
        return $this->start_result;
    }

    public function resume(string $operation_id, int $now): array
    {
        $this->called('resume', array($operation_id, $now));
        return $this->resume_result;
    }

    public function grant(
        string $operation_id,
        string $username,
        string $password,
        string $otp,
        int $now
    ): array {
        $this->called('grant', array($operation_id, $username, $password, $otp, $now));
        return $this->grant_result;
    }

    public function reconcile(string $operation_id, int $now): array
    {
        $this->called('reconcile', array($operation_id, $now));
        return $this->reconcile_result;
    }

    public function verify_identity(string $operation_id, int $now): array
    {
        $this->called('verify_identity', array($operation_id, $now));
        return $this->verify_result;
    }

    public function discover_destinations(string $operation_id, int $now): array
    {
        $this->called('discover_destinations', array($operation_id, $now));
        return $this->discover_result;
    }

    public function select_destination(
        string $operation_id,
        string $destination_id,
        int $actor_id,
        int $now
    ): array {
        $this->called('select_destination', array($operation_id, $destination_id, $actor_id, $now));
        return $this->select_result;
    }

    public function activate(string $operation_id, int $now): array
    {
        $this->called('activate', array($operation_id, $now));
        return $this->activate_result;
    }

    public function refresh_backend(string $backend_id, int $now): array
    {
        $this->called('refresh_backend', array($backend_id, $now));
        return $this->refresh_result;
    }

    public function disconnect_backend(string $backend_id, int $now): array
    {
        $this->called('disconnect_backend', array($backend_id, $now));
        return $this->disconnect_result;
    }

    public function save_upload_policy(string $backend_id, mixed $chunk_mib): array
    {
        $this->called('save_upload_policy', array($backend_id, $chunk_mib));
        return $this->upload_policy_result;
    }

    public function managed_backends(): array
    {
        $this->called('managed_backends', array());
        return $this->backends;
    }

    public function open_operations(): ?array
    {
        $this->called('open_operations', array());
        return $this->operations;
    }

    /** @param list<mixed> $arguments */
    private function called(string $method, array $arguments): void
    {
        $this->calls[] = array('method' => $method, 'args' => $arguments);
        if ($this->throw) {
            throw new RuntimeException('Synthetic admin action exception containing SECRET-CANARY.');
        }
    }
}

/** @return array<string, mixed> */
function awvp_admin_result(
    string $status,
    string $mutation,
    string $operation_id = 'connection_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    string $phase = Machine::PHASE_PREPARED
): array {
    return array(
        'status'          => $status,
        'mutation'        => $mutation,
        'operation_id'    => $operation_id,
        'backend_id'      => '' === $operation_id ? '' : 'peertube_primary',
        'phase'           => $phase,
        'record_revision' => '' === $operation_id ? 0 : 1,
    );
}

/** @return array<string, mixed> */
function awvp_admin_grant_result(
    string $status,
    string $mutation,
    string $operation_id = 'connection_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    string $phase = Machine::PHASE_SECRET_STORED
): array {
    return array_merge(
        awvp_admin_result($status, $mutation, $operation_id, $phase),
        array('retry_after' => 0)
    );
}

/** @return array<string, mixed> */
function awvp_admin_identity_result(
    string $status,
    string $mutation,
    string $operation_id = 'connection_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    string $phase = Machine::PHASE_VERIFICATION_IN_FLIGHT
): array {
    return array_merge(
        awvp_admin_result($status, $mutation, $operation_id, $phase),
        array('retry_after' => 0)
    );
}

/** @return array<string, mixed> */
function awvp_admin_activation_result(
    string $status = PeerTube_Backend_Activation_Service::STATUS_ADVANCED,
    string $mutation = Atomic_Option_Result::MUTATION_APPLIED,
    string $operation_id = 'connection_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    string $phase = Machine::PHASE_ACTIVATION_PLANNED,
    int $revision = 6
): array {
    return array(
        'status'          => $status,
        'mutation'        => $mutation,
        'operation_id'    => $operation_id,
        'backend_id'      => '' === $operation_id ? '' : 'peertube_primary',
        'phase'           => $phase,
        'record_revision' => '' === $operation_id ? 0 : $revision,
        'retry_after'     => 0,
    );
}

/** @return array<string, mixed> */
function awvp_admin_discovery_result(
    string $status = PeerTube_Identity_Destination_Service::STATUS_DESTINATIONS_READY
): array {
    $success = in_array(
        $status,
        array(
            PeerTube_Identity_Destination_Service::STATUS_DESTINATIONS_READY,
            PeerTube_Identity_Destination_Service::STATUS_NO_DESTINATIONS,
        ),
        true
    );
    return array_merge(
        awvp_admin_identity_result(
            $status,
            Atomic_Option_Result::MUTATION_NONE,
            'connection_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            Machine::PHASE_AWAITING_DESTINATION
        ),
        array(
            'identity' => $success
                ? array(
                    'user_id' => '17',
                    'username' => 'awvp_service',
                    'account_id' => '23',
                    'account_name' => 'awvp_service',
                )
                : array(),
            'destinations' => PeerTube_Identity_Destination_Service::STATUS_DESTINATIONS_READY === $status
                ? array(
                    array(
                        'id' => '41',
                        'name' => 'primary',
                        'display_name' => 'Primary & Owned',
                        'authority' => 'owned',
                    ),
                )
                : array(),
        )
    );
}

/** @return array<string, mixed> */
function awvp_admin_operation(
    string $operation_id = 'connection_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
    string $origin = 'https://video.example.org',
    string $phase = Machine::PHASE_DISABLED,
    int $attempt = 0,
    int $retry_after = 0
): array {
    return array(
        'operation_id'     => $operation_id,
        'backend_id'       => 'peertube_primary',
        'origin'           => $origin,
        'label'            => 'Primary & private',
        'phase'            => $phase,
        'record_revision'  => 5,
        'grant_attempt_no' => $attempt,
        'retry_after'      => $retry_after,
        'created_at'       => 1699999000,
        'updated_at'       => 1699999900,
    );
}

function awvp_admin_assert(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function awvp_admin_reset_request(): void
{
    $_GET = array();
    $_POST = array();
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $GLOBALS['awvp_admin_capable'] = true;
    $GLOBALS['awvp_admin_user_id'] = 17;
    $GLOBALS['awvp_admin_expected_nonce_action'] = '';
    $GLOBALS['awvp_admin_nonce_valid'] = true;
    $GLOBALS['awvp_admin_nonce_calls'] = array();
}

/** @param array<string, mixed> $fields */
function awvp_admin_post(string $action, string $nonce_action, array $fields): void
{
    $GLOBALS['awvp_admin_expected_nonce_action'] = $nonce_action;
    $_POST = array_merge(
        array(
            'action' => $action,
            PeerTube_Connection_Admin::NONCE_FIELD => 'valid-nonce',
        ),
        $fields
    );
}

/** @return PeerTube_Connection_Admin */
function awvp_admin_controller(Awvp_Admin_Fake_Actions $actions): PeerTube_Connection_Admin
{
    return new PeerTube_Connection_Admin(
        $actions,
        static fn (): int => 1700000000,
        static function (string $url, int $status): void {
            throw new Awvp_Admin_Redirect($url, $status);
        }
    );
}

/** @return Awvp_Admin_Redirect|Awvp_Admin_Die */
function awvp_admin_invoke(callable $callback): Awvp_Admin_Redirect|Awvp_Admin_Die
{
    try {
        $callback();
    } catch (Awvp_Admin_Redirect|Awvp_Admin_Die $result) {
        return $result;
    }

    throw new RuntimeException('Admin handler returned without terminating.');
}

function awvp_admin_call_count(Awvp_Admin_Fake_Actions $actions, string $method): int
{
    return count(
        array_filter(
            $actions->calls,
            static fn (array $call): bool => $method === $call['method']
        )
    );
}

// Shared exact validators reject transformations and preserve accepted bytes.
awvp_admin_assert(
    'connection_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'
        === PeerTube_Connection_Input::operation_id('connection_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
    'Exact operation ID was rejected.'
);
awvp_admin_assert(
    '' === PeerTube_Connection_Input::operation_id('connection_AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA'),
    'Noncanonical operation ID was accepted.'
);
awvp_admin_assert('41' === PeerTube_Connection_Input::destination_id('41'), 'Exact destination ID was rejected.');
foreach (array('', '0', '041', '41 ', '4.1', '9223372036854775808') as $invalid_destination) {
    awvp_admin_assert(
        '' === PeerTube_Connection_Input::destination_id($invalid_destination),
        'Noncanonical destination ID was accepted.'
    );
}
awvp_admin_assert(
    'Primary & private' === PeerTube_Connection_Input::label('Primary & private'),
    'Valid connection label was transformed.'
);
foreach (array('', ' padded', 'padded ', "control\n", '<tag>', str_repeat('a', 121)) as $invalid_label) {
    awvp_admin_assert('' === PeerTube_Connection_Input::label($invalid_label), 'Invalid label was accepted.');
}
awvp_admin_assert(
    PeerTube_Connection_Input::valid_credentials('admin', " p\\a'ss ", ''),
    'Valid credential bytes were rejected.'
);
awvp_admin_assert(
    ! PeerTube_Connection_Input::valid_credentials('admin user', 'password', ''),
    'Whitespace username was accepted.'
);
awvp_admin_assert(
    ! PeerTube_Connection_Input::valid_credentials('admin', "bad\npassword", ''),
    'Control-bearing password was accepted.'
);
awvp_admin_assert(
    PeerTube_Connection_Input::valid_credentials('admin', 'password', '123456'),
    'Valid OTP was rejected.'
);
awvp_admin_assert(
    ! PeerTube_Connection_Input::valid_credentials('admin', 'password', '12345'),
    'Invalid OTP was accepted.'
);

// The page is a distinct manage_options settings surface.
$actions = new Awvp_Admin_Fake_Actions();
$controller = awvp_admin_controller($actions);
$controller->menu();
awvp_admin_assert(1 === count($GLOBALS['awvp_admin_pages']), 'PeerTube settings page was not registered once.');
awvp_admin_assert(
    'manage_options' === $GLOBALS['awvp_admin_pages'][0][2]
        && PeerTube_Connection_Admin::PAGE_SLUG === $GLOBALS['awvp_admin_pages'][0][3],
    'PeerTube settings page capability or slug is incorrect.'
);

// Method and capability checks precede nonce verification and all actions.
awvp_admin_reset_request();
$_SERVER['REQUEST_METHOD'] = 'GET';
$result = awvp_admin_invoke(array($controller, 'start_action'));
awvp_admin_assert($result instanceof Awvp_Admin_Die, 'GET did not terminate.');
awvp_admin_assert([] === $GLOBALS['awvp_admin_nonce_calls'], 'GET reached nonce verification.');
awvp_admin_assert([] === $actions->calls, 'GET reached an admin action.');

awvp_admin_reset_request();
$GLOBALS['awvp_admin_capable'] = false;
$result = awvp_admin_invoke(array($controller, 'start_action'));
awvp_admin_assert($result instanceof Awvp_Admin_Die, 'Unauthorized POST did not terminate.');
awvp_admin_assert([] === $GLOBALS['awvp_admin_nonce_calls'], 'Unauthorized POST reached nonce verification.');
awvp_admin_assert([] === $actions->calls, 'Unauthorized POST reached an admin action.');

awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_START,
    'argentwolf_video_processor_peertube_connection_start',
    array('backend_id' => 'peertube_primary', 'origin' => 'https://video.example.org', 'label' => 'Primary')
);
$GLOBALS['awvp_admin_nonce_valid'] = false;
$result = awvp_admin_invoke(array($controller, 'start_action'));
awvp_admin_assert($result instanceof Awvp_Admin_Die, 'Bad nonce did not terminate.');
awvp_admin_assert([] === $actions->calls, 'Bad nonce reached an admin action.');

$operation_id = 'connection_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$remaining_handler_cases = array(
    array(
        'resume_action',
        PeerTube_Connection_Admin::ACTION_RESUME,
        'argentwolf_video_processor_peertube_connection_resume:' . $operation_id,
        array('operation_id' => $operation_id),
    ),
    array(
        'grant_action',
        PeerTube_Connection_Admin::ACTION_GRANT,
        'argentwolf_video_processor_peertube_connection_grant:' . $operation_id,
        array(
            'operation_id' => $operation_id,
            'username' => 'admin',
            'password' => 'password',
            'otp' => '',
            'authorize_external_service' => '1',
            'authorize_insecure_transport' => '0',
        ),
    ),
    array(
        'reconcile_action',
        PeerTube_Connection_Admin::ACTION_RECONCILE,
        'argentwolf_video_processor_peertube_connection_reconcile:' . $operation_id,
        array('operation_id' => $operation_id),
    ),
    array(
        'verify_identity_action',
        PeerTube_Connection_Admin::ACTION_VERIFY_IDENTITY,
        'argentwolf_video_processor_peertube_connection_verify_identity:' . $operation_id,
        array('operation_id' => $operation_id),
    ),
    array(
        'select_destination_action',
        PeerTube_Connection_Admin::ACTION_SELECT_DESTINATION,
        'argentwolf_video_processor_peertube_connection_select_destination:' . $operation_id,
        array('operation_id' => $operation_id, 'destination_id' => '41'),
    ),
    array(
        'activate_action',
        PeerTube_Connection_Admin::ACTION_ACTIVATE,
        'argentwolf_video_processor_peertube_connection_activate:' . $operation_id,
        array('operation_id' => $operation_id),
    ),
);
foreach ($remaining_handler_cases as [$method, $action, $nonce_action, $fields]) {
    $case_actions = new Awvp_Admin_Fake_Actions();
    $case_controller = awvp_admin_controller($case_actions);

    awvp_admin_reset_request();
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $result = awvp_admin_invoke(array($case_controller, $method));
    awvp_admin_assert($result instanceof Awvp_Admin_Die, "{$method} GET did not terminate.");
    awvp_admin_assert([] === $GLOBALS['awvp_admin_nonce_calls'], "{$method} GET reached nonce verification.");
    awvp_admin_assert([] === $case_actions->calls, "{$method} GET reached an admin action.");

    awvp_admin_reset_request();
    $GLOBALS['awvp_admin_capable'] = false;
    $result = awvp_admin_invoke(array($case_controller, $method));
    awvp_admin_assert($result instanceof Awvp_Admin_Die, "{$method} unauthorized POST did not terminate.");
    awvp_admin_assert([] === $GLOBALS['awvp_admin_nonce_calls'], "{$method} capability failure reached nonce verification.");
    awvp_admin_assert([] === $case_actions->calls, "{$method} capability failure reached an admin action.");

    awvp_admin_reset_request();
    awvp_admin_post($action, $nonce_action, $fields);
    $GLOBALS['awvp_admin_nonce_valid'] = false;
    $result = awvp_admin_invoke(array($case_controller, $method));
    awvp_admin_assert($result instanceof Awvp_Admin_Die, "{$method} bad nonce did not terminate.");
    awvp_admin_assert([] === $case_actions->calls, "{$method} bad nonce reached an admin action.");

    awvp_admin_reset_request();
    awvp_admin_post($action, $nonce_action, array_merge($fields, array('unexpected' => 'field')));
    $result = awvp_admin_invoke(array($case_controller, $method));
    awvp_admin_assert($result instanceof Awvp_Admin_Redirect, "{$method} unexpected field did not use PRG.");
    awvp_admin_assert(str_contains($result->url, 'invalid_request'), "{$method} unexpected field got wrong notice.");
    awvp_admin_assert([] === $case_actions->calls, "{$method} unexpected field reached an admin action.");
}

// Exact POST shape and canonical start fields are required.
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_START,
    'argentwolf_video_processor_peertube_connection_start',
    array(
        'backend_id' => 'peertube_primary',
        'origin' => 'https://video.example.org',
        'label' => 'Primary',
        'unexpected' => 'field',
    )
);
$result = awvp_admin_invoke(array($controller, 'start_action'));
awvp_admin_assert($result instanceof Awvp_Admin_Redirect, 'Unexpected start field did not use PRG.');
awvp_admin_assert(str_contains($result->url, 'invalid_request'), 'Unexpected start field got wrong notice.');
awvp_admin_assert([] === $actions->calls, 'Unexpected start field reached an action.');

foreach (
    array(
        array('backend_id' => 'local', 'origin' => 'https://video.example.org', 'label' => 'Primary'),
        array('backend_id' => 'peertube_primary', 'origin' => 'https://video.example.org/', 'label' => 'Primary'),
        array('backend_id' => 'peertube_primary', 'origin' => 'https://video.example.org', 'label' => '<Primary>'),
    ) as $invalid_start
) {
    awvp_admin_reset_request();
    awvp_admin_post(
        PeerTube_Connection_Admin::ACTION_START,
        'argentwolf_video_processor_peertube_connection_start',
        $invalid_start
    );
    $result = awvp_admin_invoke(array($controller, 'start_action'));
    awvp_admin_assert($result instanceof Awvp_Admin_Redirect, 'Invalid start did not redirect.');
    awvp_admin_assert(str_contains($result->url, 'invalid_request'), 'Invalid start got wrong notice.');
}
awvp_admin_assert([] === $actions->calls, 'Invalid starts reached an action.');

awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_START,
    'argentwolf_video_processor_peertube_connection_start',
    array('backend_id' => 'peertube_primary', 'origin' => 'https://video.example.org', 'label' => 'Primary')
);
$result = awvp_admin_invoke(array($controller, 'start_action'));
awvp_admin_assert($result instanceof Awvp_Admin_Redirect && 303 === $result->status, 'Valid start did not use 303 PRG.');
awvp_admin_assert(1 === awvp_admin_call_count($actions, 'start'), 'Valid start did not call exactly once.');
$start_call = $actions->calls[array_key_last($actions->calls)];
awvp_admin_assert(
    array('backend_id' => 'peertube_primary', 'origin' => 'https://video.example.org', 'label' => 'Primary')
        === $start_call['args'][0]
        && 17 === $start_call['args'][1]
        && 1700000000 === $start_call['args'][2],
    'Valid start inputs changed before the action boundary.'
);
awvp_admin_assert(
    ! str_contains($result->url, 'video.example.org') && ! str_contains($result->url, 'Primary'),
    'Start redirect exposed origin or label.'
);

// Resume is operation-bound, invokes one coordinator boundary, and treats a
// malformed or mutation-unknown projection as possibly changed.
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_RESUME,
    'argentwolf_video_processor_peertube_connection_resume:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($controller, 'resume_action'));
awvp_admin_assert($result instanceof Awvp_Admin_Redirect && 303 === $result->status, 'Resume did not use 303 PRG.');
awvp_admin_assert(1 === awvp_admin_call_count($actions, 'resume'), 'Resume did not invoke exactly once.');

$actions->resume_result = awvp_admin_result(
    PeerTube_Connection_Coordinator::STATUS_CONFLICT,
    Atomic_Option_Result::MUTATION_UNKNOWN,
    $operation_id
);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_RESUME,
    'argentwolf_video_processor_peertube_connection_resume:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($controller, 'resume_action'));
awvp_admin_assert(str_contains($result->url, 'state_may_have_changed'), 'Unknown mutation was downgraded.');

$actions->resume_result['password'] = 'RESULT-SECRET-CANARY';
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_RESUME,
    'argentwolf_video_processor_peertube_connection_resume:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($controller, 'resume_action'));
awvp_admin_assert(str_contains($result->url, 'state_may_have_changed'), 'Malformed result was trusted.');
awvp_admin_assert(! str_contains($result->url, 'RESULT-SECRET-CANARY'), 'Malformed result secret reached redirect.');

$actions->resume_result = awvp_admin_result(
    PeerTube_Connection_Coordinator::STATUS_READY_FOR_GRANT,
    Atomic_Option_Result::MUTATION_NONE,
    $operation_id,
    Machine::PHASE_PREPARED
);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_RESUME,
    'argentwolf_video_processor_peertube_connection_resume:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($controller, 'resume_action'));
awvp_admin_assert(
    str_contains($result->url, 'state_may_have_changed'),
    'Impossible positive status/phase projection was trusted.'
);

$actions->resume_result = awvp_admin_result(
    PeerTube_Connection_Coordinator::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_NONE,
    $operation_id,
    Machine::PHASE_ACTIVE_PENDING_CLOSE
);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_RESUME,
    'argentwolf_video_processor_peertube_connection_resume:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($controller, 'resume_action'));
awvp_admin_assert(
    str_contains($result->url, 'state_may_have_changed'),
    'Coordinator advanced result accepted a phase outside the R38 checkpoint.'
);

// Activation is exact-operation-bound and accepts only the reviewed R40
// phase/status projection. It never accepts an uncertain mutation as success.
$actions = new Awvp_Admin_Fake_Actions();
$controller = awvp_admin_controller($actions);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_ACTIVATE,
    'argentwolf_video_processor_peertube_connection_activate:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($controller, 'activate_action'));
awvp_admin_assert($result instanceof Awvp_Admin_Redirect && 303 === $result->status, 'Activation did not use 303 PRG.');
awvp_admin_assert(1 === awvp_admin_call_count($actions, 'activate'), 'Activation did not invoke exactly once.');
awvp_admin_assert(str_contains($result->url, 'activation_advanced'), 'Activation advance got the wrong notice.');

$actions->activate_result = awvp_admin_activation_result(
    PeerTube_Backend_Activation_Service::STATUS_ACTIVE,
    Atomic_Option_Result::MUTATION_APPLIED,
    $operation_id,
    Machine::PHASE_COMPLETE,
    8
);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_ACTIVATE,
    'argentwolf_video_processor_peertube_connection_activate:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($controller, 'activate_action'));
awvp_admin_assert(str_contains($result->url, 'backend_activated'), 'Completed activation got the wrong notice.');

$actions->activate_result = awvp_admin_activation_result(
    PeerTube_Backend_Activation_Service::STATUS_CONFLICT,
    Atomic_Option_Result::MUTATION_UNKNOWN,
    $operation_id,
    Machine::PHASE_ACTIVATION_PLANNED
);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_ACTIVATE,
    'argentwolf_video_processor_peertube_connection_activate:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($controller, 'activate_action'));
awvp_admin_assert(str_contains($result->url, 'state_may_have_changed'), 'Unknown activation mutation was downgraded.');

$actions->activate_result = awvp_admin_activation_result(
    PeerTube_Backend_Activation_Service::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_NONE,
    $operation_id,
    Machine::PHASE_AWAITING_DESTINATION
);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_ACTIVATE,
    'argentwolf_video_processor_peertube_connection_activate:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($controller, 'activate_action'));
awvp_admin_assert(str_contains($result->url, 'state_may_have_changed'), 'Activation accepted an impossible positive phase.');

// Credential submission preserves exact unslashed bytes, requires explicit
// service authorization, and invokes exactly one grant boundary.
$actions = new Awvp_Admin_Fake_Actions();
$actions->operations = array(awvp_admin_operation());
$controller = awvp_admin_controller($actions);
$username = 'credential-user-canary';
$password = " p\\a'ss ";
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_GRANT,
    'argentwolf_video_processor_peertube_connection_grant:' . $operation_id,
    array(
        'operation_id' => $operation_id,
        'username' => $username,
        'password' => addslashes($password),
        'otp' => '',
        'authorize_external_service' => '1',
        'authorize_insecure_transport' => '0',
    )
);
$result = awvp_admin_invoke(array($controller, 'grant_action'));
awvp_admin_assert($result instanceof Awvp_Admin_Redirect && 303 === $result->status, 'Grant did not use 303 PRG.');
awvp_admin_assert(1 === awvp_admin_call_count($actions, 'grant'), 'Grant did not invoke exactly once.');
$grant_calls = array_values(array_filter($actions->calls, static fn (array $call): bool => 'grant' === $call['method']));
awvp_admin_assert(
    array($operation_id, $username, $password, '', 1700000000) === $grant_calls[0]['args'],
    'Credential bytes changed before the grant boundary.'
);
awvp_admin_assert(
    ! str_contains($result->url, $username)
        && ! str_contains($result->url, rawurlencode($username))
        && ! str_contains($result->url, rawurlencode($password))
        && ! str_contains($result->url, 'username=')
        && ! str_contains($result->url, 'password=')
        && ! str_contains($result->url, 'otp='),
    'Credential material reached the grant redirect.'
);

$actions->grant_result = awvp_admin_grant_result(
    PeerTube_Password_Grant_Service::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_NONE,
    $operation_id,
    Machine::PHASE_ACTIVE_PENDING_CLOSE
);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_GRANT,
    'argentwolf_video_processor_peertube_connection_grant:' . $operation_id,
    array(
        'operation_id' => $operation_id,
        'username' => 'admin',
        'password' => 'password',
        'otp' => '',
        'authorize_external_service' => '1',
        'authorize_insecure_transport' => '0',
    )
);
$result = awvp_admin_invoke(array($controller, 'grant_action'));
awvp_admin_assert(
    str_contains($result->url, 'state_may_have_changed'),
    'Grant advanced result accepted a phase outside the R38 checkpoint.'
);
$actions->grant_result = awvp_admin_grant_result(
    PeerTube_Password_Grant_Service::STATUS_READY_FOR_VERIFICATION,
    Atomic_Option_Result::MUTATION_APPLIED
);

awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_GRANT,
    'argentwolf_video_processor_peertube_connection_grant:' . $operation_id,
    array(
        'operation_id' => $operation_id,
        'username' => 'admin',
        'password' => 'password',
        'otp' => '12345',
        'authorize_external_service' => '1',
        'authorize_insecure_transport' => '0',
    )
);
$result = awvp_admin_invoke(array($controller, 'grant_action'));
awvp_admin_assert(str_contains($result->url, 'invalid_request'), 'Invalid OTP was not refused.');
awvp_admin_assert(2 === awvp_admin_call_count($actions, 'grant'), 'Invalid OTP reached grant.');

awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_GRANT,
    'argentwolf_video_processor_peertube_connection_grant:' . $operation_id,
    array(
        'operation_id' => $operation_id,
        'username' => 'admin',
        'password' => 'password',
        'otp' => '',
        'authorize_external_service' => '0',
        'authorize_insecure_transport' => '0',
    )
);
$result = awvp_admin_invoke(array($controller, 'grant_action'));
awvp_admin_assert(str_contains($result->url, 'invalid_request'), 'Missing service authorization was accepted.');
awvp_admin_assert(2 === awvp_admin_call_count($actions, 'grant'), 'Unauthorized external-service grant ran.');

// Development-only HTTP requires a separate exact acknowledgement.
$actions->operations = array(
    awvp_admin_operation($operation_id, 'http://peertube.test:9000')
);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_GRANT,
    'argentwolf_video_processor_peertube_connection_grant:' . $operation_id,
    array(
        'operation_id' => $operation_id,
        'username' => 'admin',
        'password' => 'password',
        'otp' => '',
        'authorize_external_service' => '1',
        'authorize_insecure_transport' => '0',
    )
);
$result = awvp_admin_invoke(array($controller, 'grant_action'));
awvp_admin_assert(str_contains($result->url, 'invalid_request'), 'HTTP grant lacked second acknowledgement.');
awvp_admin_assert(2 === awvp_admin_call_count($actions, 'grant'), 'Unacknowledged HTTP grant ran.');

awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_GRANT,
    'argentwolf_video_processor_peertube_connection_grant:' . $operation_id,
    array(
        'operation_id' => $operation_id,
        'username' => 'admin',
        'password' => 'password',
        'otp' => '123456',
        'authorize_external_service' => '1',
        'authorize_insecure_transport' => '1',
    )
);
$result = awvp_admin_invoke(array($controller, 'grant_action'));
awvp_admin_assert(3 === awvp_admin_call_count($actions, 'grant'), 'Acknowledged HTTP grant did not run once.');

// The OTP phase requires the six-digit value at both browser and server
// boundaries; a blank value never reaches the grant service.
$otp_actions = new Awvp_Admin_Fake_Actions();
$otp_actions->operations = array(
    awvp_admin_operation(
        $operation_id,
        'https://video.example.org',
        Machine::PHASE_AWAITING_OTP,
        1
    )
);
$otp_controller = awvp_admin_controller($otp_actions);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_GRANT,
    'argentwolf_video_processor_peertube_connection_grant:' . $operation_id,
    array(
        'operation_id' => $operation_id,
        'username' => 'admin',
        'password' => 'password',
        'otp' => '',
        'authorize_external_service' => '1',
        'authorize_insecure_transport' => '0',
    )
);
$result = awvp_admin_invoke(array($otp_controller, 'grant_action'));
awvp_admin_assert(str_contains($result->url, 'invalid_request'), 'Blank required OTP was not rejected.');
awvp_admin_assert(0 === awvp_admin_call_count($otp_actions, 'grant'), 'Blank required OTP reached grant.');
$_GET = array(
    'page' => PeerTube_Connection_Admin::PAGE_SLUG,
    'argentwolf_peertube_operation' => $operation_id,
);
ob_start();
$otp_controller->page();
$otp_html = (string) ob_get_clean();
awvp_admin_assert(
    str_contains($otp_html, 'name="otp" type="text"')
        && str_contains($otp_html, 'maxlength="6" required'),
    'OTP-required phase did not render a required OTP field.'
);

// Reconcile is operation-bound and credential-free.
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_RECONCILE,
    'argentwolf_video_processor_peertube_connection_reconcile:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($controller, 'reconcile_action'));
awvp_admin_assert($result instanceof Awvp_Admin_Redirect && 303 === $result->status, 'Reconcile did not use 303 PRG.');
awvp_admin_assert(1 === awvp_admin_call_count($actions, 'reconcile'), 'Reconcile did not invoke exactly once.');

// Identity verification and destination selection are separate operation-bound
// POSTs. The browser cannot normalize or invent a destination identifier.
$identity_actions = new Awvp_Admin_Fake_Actions();
$identity_actions->operations = array(
    awvp_admin_operation($operation_id, 'https://video.example.org', Machine::PHASE_SECRET_STORED, 1)
);
$identity_controller = awvp_admin_controller($identity_actions);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_VERIFY_IDENTITY,
    'argentwolf_video_processor_peertube_connection_verify_identity:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($identity_controller, 'verify_identity_action'));
awvp_admin_assert(
    $result instanceof Awvp_Admin_Redirect && 303 === $result->status,
    'Identity verification did not use 303 PRG.'
);
awvp_admin_assert(
    array($operation_id, 1700000000)
        === array_values(array_filter(
            $identity_actions->calls,
            static fn (array $call): bool => 'verify_identity' === $call['method']
        ))[0]['args'],
    'Identity verification inputs changed before the service boundary.'
);
awvp_admin_assert(str_contains($result->url, 'verification_advanced'), 'Identity step got the wrong fixed notice.');

$identity_actions->select_result = awvp_admin_identity_result(
    PeerTube_Identity_Destination_Service::STATUS_ADVANCED,
    Atomic_Option_Result::MUTATION_APPLIED,
    $operation_id,
    Machine::PHASE_VERIFICATION_IN_FLIGHT
);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_SELECT_DESTINATION,
    'argentwolf_video_processor_peertube_connection_select_destination:' . $operation_id,
    array('operation_id' => $operation_id, 'destination_id' => '41')
);
$result = awvp_admin_invoke(array($identity_controller, 'select_destination_action'));
awvp_admin_assert($result instanceof Awvp_Admin_Redirect && 303 === $result->status, 'Selection did not use 303 PRG.');
$select_calls = array_values(array_filter(
    $identity_actions->calls,
    static fn (array $call): bool => 'select_destination' === $call['method']
));
awvp_admin_assert(
    1 === count($select_calls)
        && array($operation_id, '41', 17, 1700000000) === $select_calls[0]['args'],
    'Destination selection inputs changed before the service boundary.'
);

// A failed fresh authority read during selection is read-only: the durable
// operation remains awaiting_destination and the service reports no mutation.
$identity_actions->select_result = awvp_admin_identity_result(
    PeerTube_Identity_Destination_Service::STATUS_VERIFICATION_FAILED,
    Atomic_Option_Result::MUTATION_NONE,
    $operation_id,
    Machine::PHASE_AWAITING_DESTINATION
);
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_SELECT_DESTINATION,
    'argentwolf_video_processor_peertube_connection_select_destination:' . $operation_id,
    array('operation_id' => $operation_id, 'destination_id' => '42')
);
$result = awvp_admin_invoke(array($identity_controller, 'select_destination_action'));
awvp_admin_assert(
    $result instanceof Awvp_Admin_Redirect
        && 303 === $result->status
        && str_contains($result->url, 'verification_failed'),
    'Read-only selection verification failure was not classified safely.'
);

awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_SELECT_DESTINATION,
    'argentwolf_video_processor_peertube_connection_select_destination:' . $operation_id,
    array('operation_id' => $operation_id, 'destination_id' => '041')
);
$result = awvp_admin_invoke(array($identity_controller, 'select_destination_action'));
awvp_admin_assert(str_contains($result->url, 'invalid_request'), 'Noncanonical destination was not rejected.');
awvp_admin_assert(
    2 === awvp_admin_call_count($identity_actions, 'select_destination'),
    'Noncanonical destination reached the selection service.'
);

$identity_actions->verify_result['access_token'] = 'RESULT-TOKEN-CANARY';
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_VERIFY_IDENTITY,
    'argentwolf_video_processor_peertube_connection_verify_identity:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($identity_controller, 'verify_identity_action'));
awvp_admin_assert(str_contains($result->url, 'state_may_have_changed'), 'Malformed identity result was trusted.');
awvp_admin_assert(! str_contains($result->url, 'RESULT-TOKEN-CANARY'), 'Malformed identity secret reached redirect.');

// Exceptions and terminal indeterminate grants never expose exception text or
// offer an automatic retry.
$actions->throw = true;
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_RECONCILE,
    'argentwolf_video_processor_peertube_connection_reconcile:' . $operation_id,
    array('operation_id' => $operation_id)
);
$result = awvp_admin_invoke(array($controller, 'reconcile_action'));
awvp_admin_assert(str_contains($result->url, 'state_may_have_changed'), 'Action exception was not classified uncertain.');
awvp_admin_assert(! str_contains($result->url, 'SECRET-CANARY'), 'Exception text reached redirect.');
$actions->throw = false;

// Page rendering is read-only, escapes projections, leaves credentials blank,
// and renders fixed disclosure beside the grant form.
$actions->operations = array(awvp_admin_operation());
$calls_before_page = count($actions->calls);
awvp_admin_reset_request();
$_GET = array(
    'page' => PeerTube_Connection_Admin::PAGE_SLUG,
    'argentwolf_peertube_operation' => $operation_id,
);
ob_start();
$controller->page();
$html = (string) ob_get_clean();
awvp_admin_assert(count($actions->calls) === $calls_before_page + 2, 'Page performed an unexpected number of reads.');
awvp_admin_assert('open_operations' === $actions->calls[$calls_before_page]['method'], 'Page did not read connection operations first.');
awvp_admin_assert('managed_backends' === $actions->calls[array_key_last($actions->calls)]['method'], 'Page performed a mutation.');
awvp_admin_assert(str_contains($html, 'Primary &amp; private'), 'Operation label was not escaped.');
awvp_admin_assert(str_contains($html, 'https://video.example.org'), 'Exact origin was not disclosed.');
awvp_admin_assert(str_contains($html, 'name="password" type="password"'), 'Password field is missing.');
awvp_admin_assert(! str_contains($html, 'name="password" type="password" value='), 'Password field was repopulated.');
awvp_admin_assert(str_contains($html, 'No media, media metadata, or telemetry'), 'Required no-media disclosure is missing.');
awvp_admin_assert(str_contains($html, 'dedicated least-privilege PeerTube account'), 'Dedicated-account guidance is missing.');

// R45 backend upload segmentation is an explicit administrator-only settings
// POST. Canonical integer input is preserved exactly; malformed forms never
// reach the settings service.
$policy_actions = new Awvp_Admin_Fake_Actions();
$policy_actions->operations = array();
$policy_actions->backends = array(
    array(
        'backend_id' => 'r45-policy',
        'label' => 'R45 Upload Backend',
        'origin' => 'https://video.example.org',
        'state' => 'active',
        'lifecycle_action' => '',
        'lifecycle_phase' => '',
        'lifecycle_revision' => 0,
        'upload_chunk_mib' => 128,
    )
);
$policy_controller = awvp_admin_controller($policy_actions);
awvp_admin_reset_request();
$_GET = array('page' => PeerTube_Connection_Admin::PAGE_SLUG);
ob_start();
$policy_controller->page();
$policy_html = (string) ob_get_clean();
awvp_admin_assert(
    str_contains($policy_html, 'name="upload_chunk_mib"')
        && str_contains($policy_html, 'value="128"')
        && str_contains($policy_html, 'Use 0 to stream all remaining bytes')
        && str_contains($policy_html, '0 or 1024 MiB when WordPress and PeerTube are on the same host'),
    'R45 upload-policy tuning or operator guidance is missing from the active backend page.'
);
awvp_admin_assert(
    str_contains($policy_html, 'one-shot WP-CLI path')
        && str_contains($policy_html, 'does not start media transfers'),
    'PeerTube administrator checkpoint disclosure is stale about R45 upload reachability.'
);

awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_UPLOAD_POLICY,
    'argentwolf_video_processor_peertube_upload_policy:r45-policy',
    array('backend_id' => 'r45-policy', 'upload_chunk_mib' => '1024')
);
$policy_result = awvp_admin_invoke(array($policy_controller, 'upload_policy_action'));
awvp_admin_assert(
    $policy_result instanceof Awvp_Admin_Redirect
        && str_contains($policy_result->url, 'upload_policy_saved'),
    'Valid upload-policy settings POST was not classified as saved.'
);
$policy_call = $policy_actions->calls[array_key_last($policy_actions->calls)];
awvp_admin_assert(
    'save_upload_policy' === $policy_call['method']
        && array('r45-policy', 1024) === $policy_call['args'],
    'Upload-policy settings POST transformed or misrouted the canonical value.'
);

$policy_calls_before = awvp_admin_call_count($policy_actions, 'save_upload_policy');
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_UPLOAD_POLICY,
    'argentwolf_video_processor_peertube_upload_policy:r45-policy',
    array('backend_id' => 'r45-policy', 'upload_chunk_mib' => '01024')
);
$invalid_policy = awvp_admin_invoke(array($policy_controller, 'upload_policy_action'));
awvp_admin_assert(
    $invalid_policy instanceof Awvp_Admin_Redirect
        && str_contains($invalid_policy->url, 'invalid_request')
        && $policy_calls_before === awvp_admin_call_count($policy_actions, 'save_upload_policy'),
    'Noncanonical upload-policy input reached the settings service.'
);

// R41 disconnect remains explicitly continuable after the consequential local
// registry retirement. The page must not strand a restart-safe lifecycle
// between descriptor retirement and exact-generation secret deletion.
$lifecycle_actions = new Awvp_Admin_Fake_Actions();
$lifecycle_actions->operations = array();
$lifecycle_controller = awvp_admin_controller($lifecycle_actions);
$lifecycle_backend = array(
    'backend_id' => 'r38-admin',
    'label' => 'Managed PeerTube',
    'origin' => 'https://video.example.org',
    'state' => 'active',
    'lifecycle_action' => 'disconnect',
    'lifecycle_phase' => 'disconnect_retire_planned',
    'lifecycle_revision' => 4,
);
$lifecycle_actions->backends = array($lifecycle_backend);
awvp_admin_reset_request();
$_GET = array('page' => PeerTube_Connection_Admin::PAGE_SLUG);
ob_start();
$lifecycle_controller->page();
$active_disconnect_html = (string) ob_get_clean();
awvp_admin_assert(
    1 === substr_count(
        $active_disconnect_html,
        'name="action" value="' . PeerTube_Connection_Admin::ACTION_DISCONNECT . '"'
    ),
    'An active in-progress disconnect did not expose exactly one continuation action.'
);
awvp_admin_assert(
    ! str_contains(
        $active_disconnect_html,
        'name="action" value="' . PeerTube_Connection_Admin::ACTION_REFRESH . '"'
    ),
    'Refresh remained available during a nonterminal disconnect.'
);

$lifecycle_backend['state'] = 'retired';
$lifecycle_actions->backends = array($lifecycle_backend);
awvp_admin_reset_request();
$_GET = array('page' => PeerTube_Connection_Admin::PAGE_SLUG);
ob_start();
$lifecycle_controller->page();
$retired_disconnect_html = (string) ob_get_clean();
awvp_admin_assert(
    1 === substr_count(
        $retired_disconnect_html,
        'name="action" value="' . PeerTube_Connection_Admin::ACTION_DISCONNECT . '"'
    )
        && str_contains($retired_disconnect_html, 'Continue disconnect'),
    'A retired in-progress disconnect was stranded before local cleanup completed.'
);
awvp_admin_assert(
    ! str_contains(
        $retired_disconnect_html,
        'name="action" value="' . PeerTube_Connection_Admin::ACTION_REFRESH . '"'
    ),
    'Refresh was exposed after local backend retirement.'
);

$lifecycle_backend['lifecycle_phase'] = 'disconnect_complete';
$lifecycle_actions->backends = array($lifecycle_backend);
awvp_admin_reset_request();
$_GET = array('page' => PeerTube_Connection_Admin::PAGE_SLUG);
ob_start();
$lifecycle_controller->page();
$completed_disconnect_html = (string) ob_get_clean();
awvp_admin_assert(
    ! str_contains(
        $completed_disconnect_html,
        'name="action" value="' . PeerTube_Connection_Admin::ACTION_DISCONNECT . '"'
    )
        && str_contains($completed_disconnect_html, 'No active remote credential action.'),
    'A completed retired disconnect still exposed a remote credential action.'
);

// Awaiting-destination page loads remain local until the administrator submits
// the explicit nonce-bound GET. Returned channel text is strictly projected
// and escaped before an exact selection POST is offered.
$destination_actions = new Awvp_Admin_Fake_Actions();
$destination_actions->operations = array(
    awvp_admin_operation(
        $operation_id,
        'https://video.example.org',
        Machine::PHASE_AWAITING_DESTINATION,
        1
    )
);
$destination_controller = awvp_admin_controller($destination_actions);
awvp_admin_reset_request();
$_GET = array(
    'page' => PeerTube_Connection_Admin::PAGE_SLUG,
    'argentwolf_peertube_operation' => $operation_id,
);
ob_start();
$destination_controller->page();
$destination_local_html = (string) ob_get_clean();
awvp_admin_assert(
    0 === awvp_admin_call_count($destination_actions, 'discover_destinations'),
    'Ordinary page GET contacted PeerTube without the explicit discovery request.'
);
awvp_admin_assert(
    str_contains($destination_local_html, 'Read current owned destinations')
        && str_contains($destination_local_html, 'method="get"'),
    'Awaiting-destination page did not render the explicit read-only discovery form.'
);

awvp_admin_reset_request();
$GLOBALS['awvp_admin_expected_nonce_action'] =
    'argentwolf_video_processor_peertube_connection_discover_destinations:' . $operation_id;
$_GET = array(
    'page' => PeerTube_Connection_Admin::PAGE_SLUG,
    'argentwolf_peertube_operation' => $operation_id,
    'argentwolf_peertube_discover' => '1',
    PeerTube_Connection_Admin::NONCE_FIELD => 'valid-nonce',
);
ob_start();
$destination_controller->page();
$destination_html = (string) ob_get_clean();
awvp_admin_assert(
    1 === awvp_admin_call_count($destination_actions, 'discover_destinations'),
    'Explicit destination read did not invoke exactly one read-only service boundary.'
);
awvp_admin_assert(
    str_contains($destination_html, 'Primary &amp; Owned')
        && str_contains($destination_html, 'value="41"')
        && str_contains(
            $destination_html,
            'name="action" value="' . PeerTube_Connection_Admin::ACTION_SELECT_DESTINATION . '"'
        ),
    'Reviewed destination projection was not escaped and rendered for exact selection.'
);
awvp_admin_assert(
    ! str_contains($destination_html, 'access_token')
        && ! str_contains($destination_html, 'refresh_token'),
    'Destination page exposed a persistent-secret field.'
);

$destination_actions->discover_result['access_token'] = 'DISCOVERY-TOKEN-CANARY';
ob_start();
$destination_controller->page();
$malformed_discovery_html = (string) ob_get_clean();
awvp_admin_assert(
    ! str_contains($malformed_discovery_html, 'DISCOVERY-TOKEN-CANARY')
        && ! str_contains(
            $malformed_discovery_html,
            'name="action" value="' . PeerTube_Connection_Admin::ACTION_SELECT_DESTINATION . '"'
        ),
    'Malformed discovery projection reached the destination selector.'
);

awvp_admin_reset_request();
$GLOBALS['awvp_admin_expected_nonce_action'] =
    'argentwolf_video_processor_peertube_connection_discover_destinations:' . $operation_id;
$GLOBALS['awvp_admin_nonce_valid'] = false;
$_GET = array(
    'page' => PeerTube_Connection_Admin::PAGE_SLUG,
    'argentwolf_peertube_operation' => $operation_id,
    'argentwolf_peertube_discover' => '1',
    PeerTube_Connection_Admin::NONCE_FIELD => 'valid-nonce',
);
$result = awvp_admin_invoke(array($destination_controller, 'page'));
awvp_admin_assert($result instanceof Awvp_Admin_Die, 'Bad destination-read nonce did not terminate.');
awvp_admin_assert(
    2 === awvp_admin_call_count($destination_actions, 'discover_destinations'),
    'Bad destination-read nonce reached the service boundary.'
);

$malformed_projection = awvp_admin_operation();
$malformed_projection['password'] = 'PROJECTION-SECRET-CANARY';
$actions->operations = array($malformed_projection);
ob_start();
$controller->page();
$malformed_html = (string) ob_get_clean();
awvp_admin_assert(
    str_contains($malformed_html, 'Connection state is currently unavailable.'),
    'Malformed operation projection was not rejected as a whole.'
);
awvp_admin_assert(
    ! str_contains($malformed_html, 'PROJECTION-SECRET-CANARY'),
    'Malformed operation projection reached the page.'
);

$overflow_projection = awvp_admin_operation();
$overflow_projection['retry_after'] = 1;
$overflow_projection['updated_at'] = PHP_INT_MAX;
$actions->operations = array($overflow_projection);
ob_start();
$controller->page();
$overflow_html = (string) ob_get_clean();
awvp_admin_assert(
    str_contains($overflow_html, 'Connection state is currently unavailable.'),
    'Overflowing retry timestamp was accepted for rendering.'
);

$actions->operations = array(
    awvp_admin_operation($operation_id, 'https://video.example.org', Machine::PHASE_GRANT_INDETERMINATE)
);
ob_start();
$controller->page();
$indeterminate_html = (string) ob_get_clean();
awvp_admin_assert(str_contains($indeterminate_html, 'uncertain and terminal'), 'Terminal grant warning is missing.');
awvp_admin_assert(
    ! str_contains(
        $indeterminate_html,
        'name="action" value="' . PeerTube_Connection_Admin::ACTION_GRANT . '"'
    ),
    'Terminal indeterminate grant still rendered credential action.'
);
awvp_admin_assert(
    str_contains(
        $indeterminate_html,
        'name="action" value="' . PeerTube_Connection_Admin::ACTION_RECONCILE . '"'
    ),
    'Terminal indeterminate grant did not render credential-free reconciliation.'
);
$grant_count_before_terminal_post = awvp_admin_call_count($actions, 'grant');
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_GRANT,
    'argentwolf_video_processor_peertube_connection_grant:' . $operation_id,
    array(
        'operation_id' => $operation_id,
        'username' => 'admin',
        'password' => 'password',
        'otp' => '',
        'authorize_external_service' => '1',
        'authorize_insecure_transport' => '0',
    )
);
$result = awvp_admin_invoke(array($controller, 'grant_action'));
awvp_admin_assert(str_contains($result->url, 'request_refused'), 'Terminal credential POST was not refused.');
awvp_admin_assert(
    $grant_count_before_terminal_post === awvp_admin_call_count($actions, 'grant'),
    'Terminal credential POST reached the grant service.'
);

$actions->operations = array(
    awvp_admin_operation(
        $operation_id,
        'https://video.example.org',
        Machine::PHASE_AWAITING_CREDENTIALS,
        PeerTube_Connection_Input::MAX_GRANT_ATTEMPTS
    )
);
$_GET = array(
    'page' => PeerTube_Connection_Admin::PAGE_SLUG,
    'argentwolf_peertube_operation' => $operation_id,
);
ob_start();
$controller->page();
$exhausted_html = (string) ob_get_clean();
awvp_admin_assert(str_contains($exhausted_html, 'exhausted its bounded password-grant attempts'), 'Attempt exhaustion warning is missing.');
awvp_admin_assert(
    ! str_contains(
        $exhausted_html,
        'name="action" value="' . PeerTube_Connection_Admin::ACTION_GRANT . '"'
    ),
    'Attempt-exhausted operation rendered a credential action.'
);

$actions->operations = array(
    awvp_admin_operation(
        $operation_id,
        'https://video.example.org',
        Machine::PHASE_AWAITING_CREDENTIALS,
        1,
        200
    )
);
$_GET = array(
    'page' => PeerTube_Connection_Admin::PAGE_SLUG,
    'argentwolf_peertube_operation' => $operation_id,
);
ob_start();
$controller->page();
$delayed_html = (string) ob_get_clean();
awvp_admin_assert(str_contains($delayed_html, 'fresh explicit credential attempt is unavailable until'), 'Bounded retry delay warning is missing.');
awvp_admin_assert(
    ! str_contains(
        $delayed_html,
        'name="action" value="' . PeerTube_Connection_Admin::ACTION_GRANT . '"'
    ),
    'Rate-limited operation rendered a premature credential action.'
);
$grant_count_before_delayed_post = awvp_admin_call_count($actions, 'grant');
awvp_admin_reset_request();
awvp_admin_post(
    PeerTube_Connection_Admin::ACTION_GRANT,
    'argentwolf_video_processor_peertube_connection_grant:' . $operation_id,
    array(
        'operation_id' => $operation_id,
        'username' => 'admin',
        'password' => 'password',
        'otp' => '',
        'authorize_external_service' => '1',
        'authorize_insecure_transport' => '0',
    )
);
$result = awvp_admin_invoke(array($controller, 'grant_action'));
awvp_admin_assert(str_contains($result->url, 'request_refused'), 'Premature retry POST was not refused.');
awvp_admin_assert(
    $grant_count_before_delayed_post === awvp_admin_call_count($actions, 'grant'),
    'Premature retry POST reached the grant service.'
);

// R40 activation phases render only the explicit local activation action.
foreach (
    array(
        Machine::PHASE_ACTIVATION_READY => 'Begin backend activation',
        Machine::PHASE_ACTIVATION_PLANNED => 'Continue backend activation',
        Machine::PHASE_ACTIVE_PENDING_CLOSE => 'Finalize backend activation',
    ) as $activation_phase => $activation_label
) {
    $activation_actions = new Awvp_Admin_Fake_Actions();
    $activation_actions->operations = array(
        awvp_admin_operation($operation_id, 'https://video.example.org', $activation_phase, 1)
    );
    $activation_controller = awvp_admin_controller($activation_actions);
    awvp_admin_reset_request();
    $_GET = array(
        'page' => PeerTube_Connection_Admin::PAGE_SLUG,
        'argentwolf_peertube_operation' => $operation_id,
    );
    ob_start();
    $activation_controller->page();
    $activation_html = (string) ob_get_clean();
    awvp_admin_assert(
        str_contains(
            $activation_html,
            'name="action" value="' . PeerTube_Connection_Admin::ACTION_ACTIVATE . '"'
        ) && str_contains($activation_html, $activation_label),
        'Activation phase did not render its exact local continuation action.'
    );
    awvp_admin_assert(
        0 === awvp_admin_call_count($activation_actions, 'activate'),
        'Activation page GET crossed a mutation boundary.'
    );
    awvp_admin_assert(
        ! str_contains($activation_html, 'name="username"')
            && ! str_contains($activation_html, 'name="destination_id"'),
        'Activation phase rendered an unrelated credential or destination mutation field.'
    );
}

// Notice query tampering cannot introduce arbitrary text or HTML.
awvp_admin_reset_request();
$_GET = array(
    'page' => PeerTube_Connection_Admin::PAGE_SLUG,
    'argentwolf_peertube_notice' => '<script>alert(1)</script>',
    'argentwolf_video_message' => 'NOTICE-SECRET-CANARY',
);
ob_start();
$controller->notices();
$notice_html = (string) ob_get_clean();
awvp_admin_assert('' === $notice_html, 'Unrecognized notice query rendered output.');

$_GET['argentwolf_peertube_notice'] = 'credentials_stored';
ob_start();
$controller->notices();
$notice_html = (string) ob_get_clean();
awvp_admin_assert(str_contains($notice_html, 'Authenticated-encrypted token storage is confirmed.'), 'Fixed notice did not render.');
awvp_admin_assert(! str_contains($notice_html, 'NOTICE-SECRET-CANARY'), 'Arbitrary notice message rendered.');

fwrite(STDOUT, "PeerTube administrator boundary tests passed.\n");

// EOF: tests/peertube-connection-admin.php
