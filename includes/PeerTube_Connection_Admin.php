<?php
/**
 * File: includes/PeerTube_Connection_Admin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use Throwable;

/**
 * Explicit administrator authorization boundary for PeerTube connection setup.
 *
 * Page rendering is read-only. Every state transition is a distinct
 * authenticated POST which invokes at most one state-changing connection
 * method; grant authorization may first read a non-secret projection.
 */
final class PeerTube_Connection_Admin
{
    public const PAGE_SLUG = 'argentwolf-video-processor-peertube';

    public const ACTION_START = 'argentwolf_video_processor_peertube_connection_start';
    public const ACTION_RESUME = 'argentwolf_video_processor_peertube_connection_resume';
    public const ACTION_GRANT = 'argentwolf_video_processor_peertube_connection_grant';
    public const ACTION_RECONCILE = 'argentwolf_video_processor_peertube_connection_reconcile';
    public const ACTION_VERIFY_IDENTITY = 'argentwolf_video_processor_peertube_connection_verify_identity';
    public const ACTION_SELECT_DESTINATION = 'argentwolf_video_processor_peertube_connection_select_destination';
    public const ACTION_ACTIVATE = 'argentwolf_video_processor_peertube_connection_activate';
    public const ACTION_REFRESH = 'argentwolf_video_processor_peertube_token_refresh';
    public const ACTION_DISCONNECT = 'argentwolf_video_processor_peertube_disconnect';
    public const ACTION_UPLOAD_POLICY = 'argentwolf_video_processor_peertube_upload_policy';

    public const NONCE_FIELD = 'argentwolf_video_processor_peertube_nonce';

    private const NONCE_START = 'argentwolf_video_processor_peertube_connection_start';
    private const NONCE_RESUME = 'argentwolf_video_processor_peertube_connection_resume:';
    private const NONCE_GRANT = 'argentwolf_video_processor_peertube_connection_grant:';
    private const NONCE_RECONCILE = 'argentwolf_video_processor_peertube_connection_reconcile:';
    private const NONCE_VERIFY_IDENTITY = 'argentwolf_video_processor_peertube_connection_verify_identity:';
    private const NONCE_DISCOVER_DESTINATIONS = 'argentwolf_video_processor_peertube_connection_discover_destinations:';
    private const NONCE_SELECT_DESTINATION = 'argentwolf_video_processor_peertube_connection_select_destination:';
    private const NONCE_ACTIVATE = 'argentwolf_video_processor_peertube_connection_activate:';
    private const NONCE_REFRESH = 'argentwolf_video_processor_peertube_token_refresh:';
    private const NONCE_DISCONNECT = 'argentwolf_video_processor_peertube_disconnect:';
    private const NONCE_UPLOAD_POLICY = 'argentwolf_video_processor_peertube_upload_policy:';

    private const NOTICE_QUERY = 'argentwolf_peertube_notice';
    private const OPERATION_QUERY = 'argentwolf_peertube_operation';
    private const DISCOVER_QUERY = 'argentwolf_peertube_discover';
    private const MAX_OPEN_OPERATIONS = 32;

    /** @var Closure():int */
    private Closure $clock;

    /** @var Closure(string,int):void */
    private Closure $redirector;

    public function __construct(
        private readonly PeerTube_Connection_Admin_Actions $actions,
        ?callable $clock = null,
        ?callable $redirector = null
    ) {
        $this->clock = null === $clock
            ? static fn (): int => time()
            : Closure::fromCallable($clock);
        $this->redirector = null === $redirector
            ? static function (string $url, int $status): void {
                if (! wp_safe_redirect($url, $status, 'ArgentWolf Video Processor')) {
                    wp_die(
                        esc_html__('The PeerTube action completed, but WordPress could not redirect safely.', 'argentwolf-video-processor')
                    );
                }
                exit;
            }
            : Closure::fromCallable($redirector);
    }

    public function menu(): void
    {
        add_options_page(
            __('PeerTube Connection — ArgentWolf Video Processor', 'argentwolf-video-processor'),
            __('PeerTube Connection', 'argentwolf-video-processor'),
            'manage_options',
            self::PAGE_SLUG,
            array($this, 'page')
        );
    }

    public function start_action(): void
    {
        $this->require_post_administrator();
        $this->verify_nonce(self::NONCE_START);

        $values = $this->post_fields(
            array('action', self::NONCE_FIELD, 'backend_id', 'origin', 'label')
        );
        if (null === $values || self::ACTION_START !== $values['action']) {
            $this->redirect_notice('invalid_request');
        }

        $backend_id = Backend_Identity::sanitize($values['backend_id']);
        $origin = PeerTube_Origin::sanitize($values['origin']);
        $label = PeerTube_Connection_Input::label($values['label']);
        if ('' === $backend_id) {
            $this->redirect_notice('invalid_backend_id');
        }
        if ('local' === $backend_id) {
            $this->redirect_notice('reserved_backend_id');
        }
        if ('' === $origin || $origin !== $values['origin']) {
            $this->redirect_notice('invalid_peertube_url');
        }
        if ('' === $label) {
            $this->redirect_notice('invalid_connection_label');
        }

        $actor_id = get_current_user_id();
        $now = $this->now();
        if ($actor_id < 1 || $now < 1) {
            $this->redirect_notice('request_refused');
        }

        try {
            $result = $this->actions->start(
                array(
                    'backend_id' => $backend_id,
                    'origin'     => $origin,
                    'label'      => $label,
                ),
                $actor_id,
                $now
            );
        } catch (Throwable) {
            $this->redirect_notice('state_may_have_changed');
        }

        $this->redirect_result($result, '', false);
    }

    public function resume_action(): void
    {
        $this->require_post_administrator();
        $operation_id = $this->raw_operation_id();
        if ('' === $operation_id) {
            $this->reject_invalid_request();
        }
        $this->verify_nonce(self::NONCE_RESUME . $operation_id);

        $values = $this->post_fields(
            array('action', self::NONCE_FIELD, 'operation_id')
        );
        if (
            null === $values
            || self::ACTION_RESUME !== $values['action']
            || $operation_id !== $values['operation_id']
        ) {
            $this->redirect_notice('invalid_request');
        }

        try {
            $result = $this->actions->resume($operation_id, $this->now());
        } catch (Throwable) {
            $this->redirect_notice('state_may_have_changed', $operation_id);
        }

        $this->redirect_result($result, $operation_id, false);
    }

    public function grant_action(): void
    {
        $this->require_post_administrator();
        $operation_id = $this->raw_operation_id();
        if ('' === $operation_id) {
            $this->reject_invalid_request();
        }
        $this->verify_nonce(self::NONCE_GRANT . $operation_id);

        $values = $this->post_fields(
            array(
                'action',
                self::NONCE_FIELD,
                'operation_id',
                'username',
                'password',
                'otp',
                'authorize_external_service',
                'authorize_insecure_transport',
            )
        );
        if (
            null === $values
            || self::ACTION_GRANT !== $values['action']
            || $operation_id !== $values['operation_id']
            || '1' !== $values['authorize_external_service']
            || ! PeerTube_Connection_Input::valid_credentials(
                $values['username'],
                $values['password'],
                $values['otp']
            )
        ) {
            $this->redirect_notice('invalid_request', $operation_id);
        }

        $operation = $this->find_operation($operation_id);
        if (null === $operation) {
            $this->redirect_notice('request_refused', $operation_id);
        }
        if (
            PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP === $operation['phase']
            && '' === $values['otp']
        ) {
            $this->redirect_notice('invalid_request', $operation_id);
        }
        $now = $this->now();
        if (! self::grant_available($operation, $now)) {
            $this->redirect_notice('request_refused', $operation_id);
        }

        $insecure = str_starts_with($operation['origin'], 'http://');
        if (
            ($insecure && '1' !== $values['authorize_insecure_transport'])
            || (! $insecure && '0' !== $values['authorize_insecure_transport'])
        ) {
            $this->redirect_notice('invalid_request', $operation_id);
        }

        try {
            $result = $this->actions->grant(
                $operation_id,
                $values['username'],
                $values['password'],
                $values['otp'],
                $now
            );
        } catch (Throwable) {
            $this->redirect_notice('state_may_have_changed', $operation_id);
        }

        $this->redirect_result($result, $operation_id, true);
    }

    public function reconcile_action(): void
    {
        $this->require_post_administrator();
        $operation_id = $this->raw_operation_id();
        if ('' === $operation_id) {
            $this->reject_invalid_request();
        }
        $this->verify_nonce(self::NONCE_RECONCILE . $operation_id);

        $values = $this->post_fields(
            array('action', self::NONCE_FIELD, 'operation_id')
        );
        if (
            null === $values
            || self::ACTION_RECONCILE !== $values['action']
            || $operation_id !== $values['operation_id']
        ) {
            $this->redirect_notice('invalid_request');
        }

        try {
            $result = $this->actions->reconcile($operation_id, $this->now());
        } catch (Throwable) {
            $this->redirect_notice('state_may_have_changed', $operation_id);
        }

        $this->redirect_result($result, $operation_id, true);
    }

    public function verify_identity_action(): void
    {
        $this->require_post_administrator();
        $operation_id = $this->raw_operation_id();
        if ('' === $operation_id) {
            $this->reject_invalid_request();
        }
        $this->verify_nonce(self::NONCE_VERIFY_IDENTITY . $operation_id);

        $values = $this->post_fields(
            array('action', self::NONCE_FIELD, 'operation_id')
        );
        if (
            null === $values
            || self::ACTION_VERIFY_IDENTITY !== $values['action']
            || $operation_id !== $values['operation_id']
        ) {
            $this->redirect_notice('invalid_request', $operation_id);
        }

        try {
            $result = $this->actions->verify_identity($operation_id, $this->now());
        } catch (Throwable) {
            $this->redirect_notice('state_may_have_changed', $operation_id);
        }

        $this->redirect_identity_result($result, $operation_id);
    }

    public function select_destination_action(): void
    {
        $this->require_post_administrator();
        $operation_id = $this->raw_operation_id();
        if ('' === $operation_id) {
            $this->reject_invalid_request();
        }
        $this->verify_nonce(self::NONCE_SELECT_DESTINATION . $operation_id);

        $values = $this->post_fields(
            array('action', self::NONCE_FIELD, 'operation_id', 'destination_id')
        );
        if (
            null === $values
            || self::ACTION_SELECT_DESTINATION !== $values['action']
            || $operation_id !== $values['operation_id']
            || '' === PeerTube_Connection_Input::destination_id($values['destination_id'])
        ) {
            $this->redirect_notice('invalid_request', $operation_id);
        }

        $actor_id = get_current_user_id();
        if ($actor_id < 1) {
            $this->redirect_notice('request_refused', $operation_id);
        }

        try {
            $result = $this->actions->select_destination(
                $operation_id,
                $values['destination_id'],
                $actor_id,
                $this->now()
            );
        } catch (Throwable) {
            $this->redirect_notice('state_may_have_changed', $operation_id);
        }

        $this->redirect_identity_result($result, $operation_id);
    }

    public function activate_action(): void
    {
        $this->require_post_administrator();
        $operation_id = $this->raw_operation_id();
        if ('' === $operation_id) {
            $this->reject_invalid_request();
        }
        $this->verify_nonce(self::NONCE_ACTIVATE . $operation_id);

        $values = $this->post_fields(
            array('action', self::NONCE_FIELD, 'operation_id')
        );
        if (
            null === $values
            || self::ACTION_ACTIVATE !== $values['action']
            || $operation_id !== $values['operation_id']
        ) {
            $this->redirect_notice('invalid_request', $operation_id);
        }

        try {
            $result = $this->actions->activate($operation_id, $this->now());
        } catch (Throwable) {
            $this->redirect_notice('state_may_have_changed', $operation_id);
        }

        $this->redirect_activation_result($result, $operation_id);
    }

    public function refresh_action(): void
    {
        $this->lifecycle_action(self::ACTION_REFRESH, self::NONCE_REFRESH, 'refresh_backend');
    }

    public function disconnect_action(): void
    {
        $this->lifecycle_action(self::ACTION_DISCONNECT, self::NONCE_DISCONNECT, 'disconnect_backend');
    }

    public function upload_policy_action(): void
    {
        $this->require_post_administrator();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- The sanitized backend ID is required to derive the action-specific nonce checked immediately below.
        $backend_id = isset($_POST['backend_id']) && is_string($_POST['backend_id'])
            ? Backend_Identity::sanitize(sanitize_text_field(wp_unslash($_POST['backend_id'])))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id) {
            $this->reject_invalid_request();
        }
        $this->verify_nonce(self::NONCE_UPLOAD_POLICY . $backend_id);
        $values = $this->post_fields(
            array('action', self::NONCE_FIELD, 'backend_id', 'upload_chunk_mib')
        );
        $chunk_mib = null === $values
            ? null
            : PeerTube_Upload_Policy::chunk_mib($values['upload_chunk_mib']);
        if (
            null === $values
            || self::ACTION_UPLOAD_POLICY !== $values['action']
            || $backend_id !== $values['backend_id']
            || null === $chunk_mib
        ) {
            $this->redirect_notice('invalid_request');
        }

        try {
            $result = $this->actions->save_upload_policy($backend_id, $chunk_mib);
        } catch (Throwable) {
            $this->redirect_notice('state_may_have_changed');
        }
        $notice = match ($result['status'] ?? '') {
            PeerTube_Upload_Policy_Store::APPLIED,
            PeerTube_Upload_Policy_Store::PRESENT => 'upload_policy_saved',
            PeerTube_Upload_Policy_Store::INDETERMINATE => 'state_may_have_changed',
            default => 'request_refused',
        };
        $this->redirect_notice($notice);
    }

    private function lifecycle_action(string $expected_action, string $nonce_prefix, string $method): void
    {
        $this->require_post_administrator();
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- The sanitized backend ID is required to derive the action-specific nonce checked immediately below.
        $backend_id = isset($_POST['backend_id']) && is_string($_POST['backend_id'])
            ? Backend_Identity::sanitize(sanitize_text_field(wp_unslash($_POST['backend_id'])))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id) {
            $this->reject_invalid_request();
        }
        $this->verify_nonce($nonce_prefix . $backend_id);
        $values = $this->post_fields(array('action', self::NONCE_FIELD, 'backend_id'));
        if (null === $values || $expected_action !== $values['action'] || $backend_id !== $values['backend_id']) {
            $this->redirect_notice('invalid_request');
        }
        try {
            $result = $this->actions->{$method}($backend_id, $this->now());
        } catch (Throwable) {
            $this->redirect_notice('state_may_have_changed');
        }
        $notice = match ($result['status'] ?? '') {
            PeerTube_Token_Lifecycle_Service::STATUS_COMPLETE =>
                self::ACTION_DISCONNECT === $expected_action ? 'backend_disconnected' : 'token_refreshed',
            PeerTube_Token_Lifecycle_Service::STATUS_ADVANCED => 'lifecycle_advanced',
            PeerTube_Token_Lifecycle_Service::STATUS_WAIT => 'refresh_rate_limited',
            PeerTube_Token_Lifecycle_Service::STATUS_REAUTHENTICATION_REQUIRED => 'reauthentication_required',
            PeerTube_Token_Lifecycle_Service::STATUS_INDETERMINATE => 'lifecycle_indeterminate',
            PeerTube_Token_Lifecycle_Service::STATUS_CONFLICT => 'connection_conflict',
            default => 'request_refused',
        };
        $this->redirect_notice($notice);
    }

    public function notices(): void
    {
        if (! current_user_can('manage_options') || ! Settings_Hub::is_tab(Settings_Hub::TAB_PEERTUBE)) {
            return;
        }

        $notice = $this->query_notice();
        $messages = self::notice_messages();
        if ('' === $notice || ! isset($messages[$notice])) {
            return;
        }

        $class = match ($notice) {
            'connection_advanced', 'ready_for_credentials', 'credentials_stored',
            'identity_verified', 'destination_verified', 'backend_activated',
            'token_refreshed', 'backend_disconnected', 'upload_policy_saved' =>
                'notice notice-success',
            'invalid_request', 'invalid_backend_id', 'reserved_backend_id', 'invalid_peertube_url',
            'invalid_connection_label', 'request_refused', 'connection_conflict' =>
                'notice notice-error',
            'verification_advanced', 'activation_advanced', 'lifecycle_advanced' => 'notice notice-info',
            default => 'notice notice-warning',
        };

        echo '<div class="' . esc_attr($class) . ' is-dismissible"><p>'
            . esc_html($messages[$notice])
            . '</p></div>';
    }

    public function page(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(
                esc_html__('You are not allowed to administer PeerTube connections.', 'argentwolf-video-processor')
            );
        }
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('ArgentWolf Video Processor', 'argentwolf-video-processor'); ?></h1>
            <?php $this->render_tab(); ?>
        </div>
        <?php
    }

    public function render_tab(): void
    {
        if (! current_user_can('manage_options')) {
            wp_die(
                esc_html__('You are not allowed to administer PeerTube connections.', 'argentwolf-video-processor')
            );
        }

        try {
            $raw_operations = $this->actions->open_operations();
        } catch (Throwable) {
            $raw_operations = null;
        }
        $operations = null === $raw_operations
            ? null
            : $this->validated_operations($raw_operations);
        $selected_id = $this->query_operation_id();
        $selected = is_array($operations)
            ? $this->operation_from_list($operations, $selected_id)
            : null;
        $discovery = null;
        if (
            null !== $selected
            && PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION === $selected['phase']
            && $this->discovery_requested($selected['operation_id'])
        ) {
            try {
                $raw_discovery = $this->actions->discover_destinations(
                    $selected['operation_id'],
                    $this->now()
                );
            } catch (Throwable) {
                $raw_discovery = null;
            }
            $discovery = self::validated_discovery_result(
                $raw_discovery,
                $selected['operation_id']
            );
        }
        try {
            $managed_backends = $this->actions->managed_backends();
        } catch (Throwable) {
            $managed_backends = array();
        }
        ?>
        <h2><?php esc_html_e('PeerTube Servers', 'argentwolf-video-processor'); ?></h2>
            <p><?php esc_html_e('ArgentWolf Video Processor can publish selected videos to configured PeerTube servers. Videos are uploaded in the background while WordPress continues serving the local copy. Once the PeerTube video is ready and its selected visibility has been verified, the post or page will serve the PeerTube version instead.', 'argentwolf-video-processor'); ?></p>
            <p><?php esc_html_e('Publishing defaults are configured on the Publishing tab. Local-file cleanup is configured separately on the Local Retention tab.', 'argentwolf-video-processor'); ?></p>

            <h3><?php esc_html_e('Connect a PeerTube Server', 'argentwolf-video-processor'); ?></h3>
            <p><?php esc_html_e('Use the exact HTTPS URL of the PeerTube server you wish to connect. Do not include any credentials or other information after the base URL.', 'argentwolf-video-processor'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off" style="max-width:900px">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_START); ?>">
                <?php wp_nonce_field(self::NONCE_START, self::NONCE_FIELD, false); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="awvp-peertube-origin"><?php esc_html_e('PeerTube URL', 'argentwolf-video-processor'); ?></label></th><td><input class="regular-text code" id="awvp-peertube-origin" name="origin" type="url" placeholder="https://video.example.org" aria-describedby="awvp-peertube-origin-help" required><p class="description" id="awvp-peertube-origin-help"><?php esc_html_e('Enter only the HTTPS base URL, for example https://video.example.org. Do not include a username, password, API path, query string, or fragment.', 'argentwolf-video-processor'); ?></p></td></tr>
                    <tr><th scope="row"><label for="awvp-peertube-backend-id"><?php esc_html_e('Backend ID (internal identifier)', 'argentwolf-video-processor'); ?></label></th><td><input class="regular-text code" id="awvp-peertube-backend-id" name="backend_id" type="text" maxlength="64" pattern="[a-z0-9][a-z0-9_-]{0,63}" title="Use 1-64 lowercase letters, numbers, hyphens, or underscores; begin with a letter or number." aria-describedby="awvp-peertube-backend-id-help" required><p class="description" id="awvp-peertube-backend-id-help"><?php esc_html_e('The Backend ID is used in logs, diagnostics, and error messages to identify the particular PeerTube server involved. Use 1–64 lowercase letters, numbers, hyphens, or underscores. It must begin with a letter or number. Spaces and uppercase letters are not allowed. The ID “local” is reserved.', 'argentwolf-video-processor'); ?></p></td></tr>
                    <tr><th scope="row"><label for="awvp-peertube-label"><?php esc_html_e('Connection Label', 'argentwolf-video-processor'); ?></label></th><td><input class="regular-text" id="awvp-peertube-label" name="label" type="text" maxlength="120" aria-describedby="awvp-peertube-label-help" required><p class="description" id="awvp-peertube-label-help"><?php esc_html_e('A friendly name shown in ArgentWolf Video Processor menus and selectors, for example “ArgentWolf Video”.', 'argentwolf-video-processor'); ?></p></td></tr>
                </table>
                <p><button class="button button-primary" type="submit"><?php esc_html_e('Add PeerTube Server', 'argentwolf-video-processor'); ?></button></p>
            </form>

            <details style="max-width:900px;margin:1em 0 2em"><summary><strong><?php esc_html_e('How connection setup works', 'argentwolf-video-processor'); ?></strong></summary>
                <ol>
                    <li><?php esc_html_e('Add the PeerTube URL, Backend ID (internal identifier), and Connection Label.', 'argentwolf-video-processor'); ?></li>
                    <li><?php esc_html_e('ArgentWolf Video Processor prepares secure local storage for the connection.', 'argentwolf-video-processor'); ?></li>
                    <li><?php esc_html_e('Sign in with a PeerTube username and password, plus a six-digit one-time code if your PeerTube account requires one. You do not need to create or paste an API key.', 'argentwolf-video-processor'); ?></li>
                    <li><?php esc_html_e('ArgentWolf Video Processor stores the returned access and refresh tokens securely; it does not retain the password or one-time code.', 'argentwolf-video-processor'); ?></li>
                    <li><?php esc_html_e('Verify the PeerTube account and choose one of its owned channels.', 'argentwolf-video-processor'); ?></li>
                    <li><?php esc_html_e('Activate the connection. The server is then available for publishing.', 'argentwolf-video-processor'); ?></li>
                </ol>
            </details>

            <h2><?php esc_html_e('Managed PeerTube servers', 'argentwolf-video-processor'); ?></h2>
            <?php $this->render_managed_backends($managed_backends); ?>

            <h2><?php esc_html_e('Connections in progress', 'argentwolf-video-processor'); ?></h2>
            <?php $this->render_operation_list($operations, $selected_id); ?>

            <?php if (null !== $selected) : ?>
                <?php $this->render_selected_operation($selected, $discovery); ?>
            <?php endif; ?>
        <?php
    }

    /** @param list<array<string,mixed>> $backends */
    private function render_managed_backends(array $backends): void
    {
        if ([] === $backends) {
            echo '<p>' . esc_html__('No PeerTube servers are connected yet.', 'argentwolf-video-processor') . '</p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:1100px">
            <thead><tr><th><?php esc_html_e('Label', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('PeerTube URL', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Connection status', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Upload chunk', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Credential status', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Actions', 'argentwolf-video-processor'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($backends as $backend) : ?>
                <?php
                $state = (string) $backend['state'];
                $lifecycle_action = (string) $backend['lifecycle_action'];
                $lifecycle_phase = (string) $backend['lifecycle_phase'];
                $disconnect_pending = 'disconnect' === $lifecycle_action
                    && 'disconnect_complete' !== $lifecycle_phase;
                $refresh_blocks_disconnect = 'refresh' === $lifecycle_action
                    && ! in_array(
                        $lifecycle_phase,
                        array('refresh_complete', 'refresh_reauthentication_required', 'refresh_indeterminate'),
                        true
                    );
                ?>
                <tr>
                    <td><?php echo esc_html((string) $backend['label']); ?><br><code><?php echo esc_html((string) $backend['backend_id']); ?></code></td>
                    <td><code><?php echo esc_html((string) $backend['origin']); ?></code></td>
                    <td><?php echo esc_html(self::backend_state_label($state)); ?></td>
                    <td>
                        <?php if ('active' === $state && ! $disconnect_pending) : ?>
                            <?php $this->render_upload_policy_form((string) $backend['backend_id'], $backend['upload_chunk_mib'] ?? PeerTube_Upload_Policy::DEFAULT_CHUNK_MIB); ?>
                        <?php else : ?>
                            <?php echo esc_html((string) ($backend['upload_chunk_mib'] ?? PeerTube_Upload_Policy::DEFAULT_CHUNK_MIB)); ?> <?php esc_html_e('MiB', 'argentwolf-video-processor'); ?>
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html(self::lifecycle_label($lifecycle_action, $lifecycle_phase)); ?></td>
                    <td>
                    <?php if ('active' === $state && ! $disconnect_pending) : ?>
                        <?php $this->render_backend_action(self::ACTION_REFRESH, self::NONCE_REFRESH, (string) $backend['backend_id'], __('Refresh connection credentials', 'argentwolf-video-processor')); ?>
                        <?php if (! $refresh_blocks_disconnect) : ?>
                            <?php $this->render_backend_action(self::ACTION_DISCONNECT, self::NONCE_DISCONNECT, (string) $backend['backend_id'], __('Disconnect PeerTube', 'argentwolf-video-processor'), true); ?>
                        <?php endif; ?>
                    <?php elseif ($disconnect_pending && in_array($state, array('active', 'retired'), true)) : ?>
                        <?php $this->render_backend_action(self::ACTION_DISCONNECT, self::NONCE_DISCONNECT, (string) $backend['backend_id'], __('Continue disconnect', 'argentwolf-video-processor'), true); ?>
                    <?php else : ?>
                        <?php esc_html_e('No credential action is currently available.', 'argentwolf-video-processor'); ?>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><?php esc_html_e('Upload chunk size is an advanced transfer setting for each server. The default is 128 MiB. Smaller chunks can recover from interrupted Internet transfers with less retransmission; larger chunks reduce request overhead on fast, reliable links. Set to 0 to send each file in one chunk. Recommended for reliable infrastructure or when WordPress and PeerTube are co-located on the same server.', 'argentwolf-video-processor'); ?></p>
        <p><?php esc_html_e('AWVP automatically refreshes PeerTube credentials and publishing options during normal maintenance so uploads can continue without routine administrator intervention. Disconnect remains a manual administrator action. If the saved authorization can no longer be refreshed, reconnect the PeerTube server.', 'argentwolf-video-processor'); ?></p>
        <?php
    }

    private function render_upload_policy_form(string $backend_id, mixed $chunk_mib): void
    {
        $chunk_mib = PeerTube_Upload_Policy::chunk_mib($chunk_mib)
            ?? PeerTube_Upload_Policy::DEFAULT_CHUNK_MIB;
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="white-space:nowrap">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_UPLOAD_POLICY); ?>">
            <input type="hidden" name="backend_id" value="<?php echo esc_attr($backend_id); ?>">
            <?php wp_nonce_field(self::NONCE_UPLOAD_POLICY . $backend_id, self::NONCE_FIELD, false); ?>
            <label class="screen-reader-text" for="awvp-peertube-upload-chunk-<?php echo esc_attr($backend_id); ?>"><?php esc_html_e('Upload chunk size in MiB', 'argentwolf-video-processor'); ?></label>
            <input id="awvp-peertube-upload-chunk-<?php echo esc_attr($backend_id); ?>" name="upload_chunk_mib" type="number" min="0" max="<?php echo esc_attr((string) PeerTube_Upload_Policy::MAX_CHUNK_MIB); ?>" step="1" value="<?php echo esc_attr((string) $chunk_mib); ?>" style="width:7em" required>
            <span><?php esc_html_e('MiB', 'argentwolf-video-processor'); ?></span>
            <button class="button button-secondary" type="submit"><?php esc_html_e('Save', 'argentwolf-video-processor'); ?></button>
        </form>
        <?php
    }

    private function render_backend_action(string $action, string $nonce_prefix, string $backend_id, string $label, bool $destructive = false): void
    {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline-block;margin-right:6px">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="backend_id" value="<?php echo esc_attr($backend_id); ?>">
            <?php wp_nonce_field($nonce_prefix . $backend_id, self::NONCE_FIELD, false); ?>
            <button class="button<?php echo $destructive ? ' button-secondary' : ''; ?>" type="submit"><?php echo esc_html($label); ?></button>
        </form>
        <?php
    }

    private function render_operation_list(?array $operations, string $selected_id): void
    {
        if (null === $operations) {
            echo '<p>' . esc_html__('Connection state is currently unavailable. No action was taken.', 'argentwolf-video-processor') . '</p>';
            return;
        }
        if ([] === $operations) {
            echo '<p>' . esc_html__('No PeerTube connections are currently being set up.', 'argentwolf-video-processor') . '</p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:1100px">
            <thead><tr><th><?php esc_html_e('Label', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('PeerTube URL', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Backend ID (internal identifier)', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Status', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Updated', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Action', 'argentwolf-video-processor'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($operations as $operation) : ?>
                <?php
                $url = add_query_arg(
                    array(
                        'page' => Settings_Hub::PAGE_SLUG,
                        'tab' => Settings_Hub::TAB_PEERTUBE,
                        self::OPERATION_QUERY => $operation['operation_id'],
                    ),
                    admin_url('options-general.php')
                );
                ?>
                <tr<?php if ($selected_id === $operation['operation_id']) : ?> class="active"<?php endif; ?>>
                    <td><?php echo esc_html($operation['label']); ?></td>
                    <td><code><?php echo esc_html($operation['origin']); ?></code></td>
                    <td><code><?php echo esc_html($operation['backend_id']); ?></code></td>
                    <td><?php echo esc_html(self::phase_label($operation['phase'])); ?></td>
                    <td><?php echo esc_html(Settings_Hub::format_datetime((int) $operation['updated_at'])); ?></td>
                    <td><a class="button button-secondary" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Continue setup', 'argentwolf-video-processor'); ?></a></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php
    }

    /** @param array<string, mixed> $operation */
    private function render_selected_operation(array $operation, ?array $discovery = null): void
    {
        $phase = $operation['phase'];
        ?>
        <h2><?php esc_html_e('Connection setup', 'argentwolf-video-processor'); ?></h2>
        <table class="widefat striped" style="max-width:900px"><tbody>
            <tr><th><?php esc_html_e('Setup ID', 'argentwolf-video-processor'); ?></th><td><code><?php echo esc_html($operation['operation_id']); ?></code></td></tr>
            <tr><th><?php esc_html_e('Backend ID (internal identifier)', 'argentwolf-video-processor'); ?></th><td><code><?php echo esc_html($operation['backend_id']); ?></code></td></tr>
            <tr><th><?php esc_html_e('PeerTube URL', 'argentwolf-video-processor'); ?></th><td><code><?php echo esc_html($operation['origin']); ?></code></td></tr>
            <tr><th><?php esc_html_e('Status', 'argentwolf-video-processor'); ?></th><td><?php echo esc_html(self::phase_label($phase)); ?></td></tr>
            <tr><th><?php esc_html_e('Progress', 'argentwolf-video-processor'); ?></th><td><?php echo esc_html(sprintf(
                /* translators: 1: current setup step number, 2: total setup steps. */
                __('Step %1$d of %2$d', 'argentwolf-video-processor'),
                self::phase_step($phase),
                6
            )); ?></td></tr>
            <tr><th><?php esc_html_e('Next step', 'argentwolf-video-processor'); ?></th><td><?php echo esc_html(self::phase_help($phase)); ?></td></tr>
            <tr><th><?php esc_html_e('Sign-in attempts', 'argentwolf-video-processor'); ?></th><td><?php echo esc_html((string) $operation['grant_attempt_no']); ?> / <?php echo esc_html((string) PeerTube_Connection_Input::MAX_GRANT_ATTEMPTS); ?></td></tr>
        </tbody></table>
        <?php

        if (in_array(
            $phase,
            array(
                PeerTube_Connection_State_Machine::PHASE_PREPARED,
                PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED,
                PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVED,
                PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED,
            ),
            true
        )) {
            echo '<p>' . esc_html__('ArgentWolf Video Processor is preparing secure local storage for this connection. This step does not contact PeerTube. Continue setup until the PeerTube sign-in form appears.', 'argentwolf-video-processor') . '</p>';
            $this->render_operation_form(
                self::ACTION_RESUME,
                self::NONCE_RESUME . $operation['operation_id'],
                $operation['operation_id'],
                __('Continue setup', 'argentwolf-video-processor')
            );
            return;
        }

        if (PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE === $phase) {
            echo '<div class="notice notice-error inline"><p>'
                . esc_html__('The remote password-grant outcome is uncertain and terminal. AWVP will not retry it automatically or offer another credential submission for this operation.', 'argentwolf-video-processor')
                . '</p></div>';
            echo '<p>' . esc_html__('Credential-free reconciliation performs no PeerTube HTTP request and may confirm only an exact encrypted-token write that completed before the local outcome became uncertain.', 'argentwolf-video-processor') . '</p>';
            $this->render_operation_form(
                self::ACTION_RECONCILE,
                self::NONCE_RECONCILE . $operation['operation_id'],
                $operation['operation_id'],
                __('Check terminal local state', 'argentwolf-video-processor')
            );
            return;
        }

        if (in_array(
            $phase,
            array(
                PeerTube_Connection_State_Machine::PHASE_GRANT_IN_FLIGHT,
                PeerTube_Connection_State_Machine::PHASE_OTP_RESULT_PENDING,
                PeerTube_Connection_State_Machine::PHASE_CREDENTIAL_RESULT_PENDING,
                PeerTube_Connection_State_Machine::PHASE_SECRET_WRITE_PLANNED,
            ),
            true
        )) {
            echo '<p>' . esc_html__('The previous sign-in step needs a local status check before setup can continue. This check does not send your credentials to PeerTube again.', 'argentwolf-video-processor') . '</p>';
            $this->render_operation_form(
                self::ACTION_RECONCILE,
                self::NONCE_RECONCILE . $operation['operation_id'],
                $operation['operation_id'],
                __('Check setup status', 'argentwolf-video-processor')
            );
            return;
        }

        if (in_array(
            $phase,
            array(
                PeerTube_Connection_State_Machine::PHASE_SECRET_STORED,
                PeerTube_Connection_State_Machine::PHASE_VERIFICATION_FAILED,
                PeerTube_Connection_State_Machine::PHASE_VERIFICATION_IN_FLIGHT,
            ),
            true
        )) {
            if (
                PeerTube_Connection_State_Machine::PHASE_VERIFICATION_FAILED === $phase
                && $operation['retry_after'] > 0
                && $this->now() < $operation['updated_at'] + $operation['retry_after']
            ) {
                echo '<div class="notice notice-warning inline"><p>'
                    . esc_html(
                        sprintf(
                            /* translators: %s: local WordPress date/time after which verification may be retried. */
                            __('PeerTube requested a temporary delay. A fresh explicit verification is unavailable until %s.', 'argentwolf-video-processor'),
                            Settings_Hub::format_datetime((int) ($operation['updated_at'] + $operation['retry_after']))
                        )
                    )
                    . '</p></div>';
                return;
            }

            if (PeerTube_Connection_State_Machine::PHASE_VERIFICATION_IN_FLIGHT === $phase) {
                echo '<div class="notice notice-info inline"><p>'
                    . esc_html__('This step uses your saved PeerTube sign-in to confirm your account. Your channel list is then read publicly, without authentication. No videos are uploaded and no changes are made to your PeerTube account or server.', 'argentwolf-video-processor')
                    . '</p></div>';
            } else {
                echo '<p>'
                    . esc_html__('The next step prepares the account check. Continue once more to verify the signed-in PeerTube account and its channels.', 'argentwolf-video-processor')
                    . '</p>';
            }

            $this->render_operation_form(
                self::ACTION_VERIFY_IDENTITY,
                self::NONCE_VERIFY_IDENTITY . $operation['operation_id'],
                $operation['operation_id'],
                PeerTube_Connection_State_Machine::PHASE_VERIFICATION_IN_FLIGHT === $phase
                    ? __('Verify account and channels', 'argentwolf-video-processor')
                    : __('Start account verification', 'argentwolf-video-processor')
            );
            return;
        }

        if (PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION === $phase) {
            $this->render_destination_discovery($operation, $discovery);
            return;
        }

        if (in_array(
            $phase,
            array(
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY,
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_PLANNED,
                PeerTube_Connection_State_Machine::PHASE_ACTIVE_PENDING_CLOSE,
            ),
            true
        )) {
            $message = match ($phase) {
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY =>
                    __('Your PeerTube account and selected channel are verified. Activate this connection to make the server available for publishing. No video is uploaded by this step.', 'argentwolf-video-processor'),
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_PLANNED =>
                    __('Activation has started. Continue setup to finish enabling this PeerTube server for publishing. No video is uploaded by this step.', 'argentwolf-video-processor'),
                default =>
                    __('The PeerTube server has been enabled. Finish setup to close the connection process; then go to Publishing and load this server’s publishing options.', 'argentwolf-video-processor'),
            };
            echo '<div class="notice notice-info inline"><p>' . esc_html($message) . '</p></div>';

            $button = match ($phase) {
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY =>
                    __('Activate PeerTube Server', 'argentwolf-video-processor'),
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_PLANNED =>
                    __('Continue activation', 'argentwolf-video-processor'),
                default => __('Finish setup', 'argentwolf-video-processor'),
            };
            $this->render_operation_form(
                self::ACTION_ACTIVATE,
                self::NONCE_ACTIVATE . $operation['operation_id'],
                $operation['operation_id'],
                $button
            );
            return;
        }

        if (in_array(
            $phase,
            array(
                PeerTube_Connection_State_Machine::PHASE_DISABLED,
                PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP,
                PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS,
            ),
            true
        )) {
            if ($operation['grant_attempt_no'] >= PeerTube_Connection_Input::MAX_GRANT_ATTEMPTS) {
                echo '<div class="notice notice-error inline"><p>'
                    . esc_html__('This operation has reached its password-grant attempt limit. No further credential submission is available.', 'argentwolf-video-processor')
                    . '</p></div>';
                return;
            }

            $retry_at = $operation['updated_at'] + $operation['retry_after'];
            if (
                PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS === $phase
                && $operation['retry_after'] > 0
                && $this->now() < $retry_at
            ) {
                echo '<div class="notice notice-warning inline"><p>'
                    . esc_html(
                        sprintf(
                            /* translators: %s: local WordPress date/time after which another explicit attempt may be made. */
                            __('PeerTube requested a temporary delay. A fresh explicit credential attempt is unavailable until %s.', 'argentwolf-video-processor'),
                            Settings_Hub::format_datetime((int) $retry_at)
                        )
                    )
                    . '</p></div>';
                return;
            }

            $this->render_grant_form($operation);
            return;
        }

        echo '<p>'
            . esc_html__('This connection is in a state that cannot be continued from this screen. Review the current status or reconnect the PeerTube server if necessary.', 'argentwolf-video-processor')
            . '</p>';
    }

    /** @param array<string, mixed> $operation */
    private function render_destination_discovery(array $operation, ?array $discovery): void
    {
        echo '<div class="notice notice-info inline"><p>'
            . esc_html(
                sprintf(
                    /* translators: %s: exact configured PeerTube origin. */
                    __('Refreshing channels verifies the signed-in account with %s and retrieves the channels owned by that account.', 'argentwolf-video-processor'),
                    $operation['origin']
                )
            )
            . '</p></div>';

        if (null === $discovery) {
            $this->render_discovery_form($operation['operation_id'], __('Load owned channels', 'argentwolf-video-processor'));
            return;
        }

        if (PeerTube_Identity_Destination_Service::STATUS_NO_DESTINATIONS === $discovery['status']) {
            echo '<div class="notice notice-warning inline"><p>'
                . esc_html__('The authenticated account currently has no eligible local owned channel. No destination was selected.', 'argentwolf-video-processor')
                . '</p></div>';
            $this->render_discovery_form($operation['operation_id'], __('Refresh owned channels', 'argentwolf-video-processor'));
            return;
        }

        if (PeerTube_Identity_Destination_Service::STATUS_DESTINATIONS_READY !== $discovery['status']) {
            echo '<div class="notice notice-error inline"><p>'
                . esc_html__('The available PeerTube channels could not be confirmed. No channel was selected and the connection remains inactive.', 'argentwolf-video-processor')
                . '</p></div>';
            $this->render_discovery_form($operation['operation_id'], __('Retry channel refresh', 'argentwolf-video-processor'));
            return;
        }

        echo '<h3>' . esc_html__('Signed-in PeerTube account', 'argentwolf-video-processor') . '</h3>';
        echo '<p><code>' . esc_html($discovery['identity']['username']) . '</code> / <code>'
            . esc_html($discovery['identity']['account_name']) . '</code></p>';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SELECT_DESTINATION); ?>">
            <input type="hidden" name="operation_id" value="<?php echo esc_attr($operation['operation_id']); ?>">
            <?php wp_nonce_field(self::NONCE_SELECT_DESTINATION . $operation['operation_id'], self::NONCE_FIELD, false); ?>
            <fieldset>
                <legend class="screen-reader-text"><?php esc_html_e('Owned PeerTube channel', 'argentwolf-video-processor'); ?></legend>
                <table class="widefat striped"><thead><tr><th><?php esc_html_e('Select', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Channel', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Machine name', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('ID', 'argentwolf-video-processor'); ?></th></tr></thead><tbody>
                <?php foreach ($discovery['destinations'] as $destination) : ?>
                    <tr>
                        <td><input type="radio" name="destination_id" value="<?php echo esc_attr($destination['id']); ?>" required></td>
                        <td><?php echo esc_html($destination['display_name']); ?></td>
                        <td><code><?php echo esc_html($destination['name']); ?></code></td>
                        <td><code><?php echo esc_html($destination['id']); ?></code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody></table>
            </fieldset>
            <p><?php esc_html_e('Choose the PeerTube channel that ArgentWolf Video Processor should use by default for this connection. The selected channel will be verified again before activation.', 'argentwolf-video-processor'); ?></p>
            <p><button class="button button-primary" type="submit"><?php esc_html_e('Select channel', 'argentwolf-video-processor'); ?></button></p>
        </form>
        <?php
        $this->render_discovery_form($operation['operation_id'], __('Refresh owned channels', 'argentwolf-video-processor'));
    }

    private function render_discovery_form(string $operation_id, string $button_label): void
    {
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('options-general.php')); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(Settings_Hub::PAGE_SLUG); ?>">
            <input type="hidden" name="tab" value="<?php echo esc_attr(Settings_Hub::TAB_PEERTUBE); ?>">
            <input type="hidden" name="<?php echo esc_attr(self::OPERATION_QUERY); ?>" value="<?php echo esc_attr($operation_id); ?>">
            <input type="hidden" name="<?php echo esc_attr(self::DISCOVER_QUERY); ?>" value="1">
            <?php wp_nonce_field(self::NONCE_DISCOVER_DESTINATIONS . $operation_id, self::NONCE_FIELD, false); ?>
            <p><button class="button button-secondary" type="submit"><?php echo esc_html($button_label); ?></button></p>
        </form>
        <?php
    }

    private function render_operation_form(
        string $action,
        string $nonce_action,
        string $operation_id,
        string $button_label
    ): void {
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="<?php echo esc_attr($action); ?>">
            <input type="hidden" name="operation_id" value="<?php echo esc_attr($operation_id); ?>">
            <?php wp_nonce_field($nonce_action, self::NONCE_FIELD, false); ?>
            <p><button class="button button-primary" type="submit"><?php echo esc_html($button_label); ?></button></p>
        </form>
        <?php
    }

    /** @param array<string, mixed> $operation */
    private function render_grant_form(array $operation): void
    {
        $insecure = str_starts_with($operation['origin'], 'http://');
        $otp_required = PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP
            === $operation['phase'];
        ?>
        <h3><?php esc_html_e('Sign in to PeerTube', 'argentwolf-video-processor'); ?></h3>
        <div class="notice notice-info inline"><p>
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s: exact configured PeerTube origin. */
                    __('To authorize this connection, ArgentWolf Video Processor will send the username, password, and optional six-digit one-time code entered below only to %s. The PeerTube server operator’s terms and privacy policy apply.', 'argentwolf-video-processor'),
                    $operation['origin']
                )
            );
            ?>
        </p><p><?php esc_html_e('ArgentWolf Video Processor does not retain your PeerTube password or one-time code. PeerTube returns access and refresh tokens after a successful sign-in; those tokens are stored encrypted in WordPress.', 'argentwolf-video-processor'); ?></p><p><?php esc_html_e('Use a dedicated PeerTube account with access to the channels you want WordPress to publish to. Signing in only authorizes the connection; it does not upload videos or video metadata.', 'argentwolf-video-processor'); ?></p></div>
        <?php if ($insecure) : ?>
            <div class="notice notice-error inline"><p><?php esc_html_e('Development-only warning: this allowlisted origin uses plaintext HTTP. The entered credentials and returned tokens are not protected by TLS in transit.', 'argentwolf-video-processor'); ?></p></div>
        <?php endif; ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off" style="max-width:900px">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_GRANT); ?>">
            <input type="hidden" name="operation_id" value="<?php echo esc_attr($operation['operation_id']); ?>">
            <?php if (! $insecure) : ?><input type="hidden" name="authorize_insecure_transport" value="0"><?php endif; ?>
            <?php wp_nonce_field(self::NONCE_GRANT . $operation['operation_id'], self::NONCE_FIELD, false); ?>
            <table class="form-table" role="presentation">
                <tr><th scope="row"><label for="awvp-peertube-username"><?php esc_html_e('PeerTube username', 'argentwolf-video-processor'); ?></label></th><td><input class="regular-text" id="awvp-peertube-username" name="username" type="text" autocomplete="off" required></td></tr>
                <tr><th scope="row"><label for="awvp-peertube-password"><?php esc_html_e('PeerTube password', 'argentwolf-video-processor'); ?></label></th><td><input class="regular-text" id="awvp-peertube-password" name="password" type="password" autocomplete="off" required></td></tr>
                <tr><th scope="row"><label for="awvp-peertube-otp"><?php esc_html_e('Six-digit OTP (when required)', 'argentwolf-video-processor'); ?></label></th><td><input class="small-text code" id="awvp-peertube-otp" name="otp" type="text" inputmode="numeric" autocomplete="off" pattern="[0-9]{6}" maxlength="6"<?php if ($otp_required) : ?> required<?php endif; ?>></td></tr>
            </table>
            <p><label><input type="checkbox" name="authorize_external_service" value="1" required> <?php esc_html_e('I authorize ArgentWolf Video Processor to send these credentials to the PeerTube server shown above.', 'argentwolf-video-processor'); ?></label></p>
            <?php if ($insecure) : ?><p><label><input type="checkbox" name="authorize_insecure_transport" value="1" required> <?php esc_html_e('I understand this development-only origin uses plaintext HTTP without TLS protection.', 'argentwolf-video-processor'); ?></label></p><?php endif; ?>
            <p><button class="button button-primary" type="submit"><?php esc_html_e('Sign in to PeerTube', 'argentwolf-video-processor'); ?></button></p>
        </form>
        <?php
    }

    private function require_post_administrator(): void
    {
        $request_method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD'])
            ? strtoupper(sanitize_text_field(wp_unslash($_SERVER['REQUEST_METHOD'])))
            : '';
        if ('POST' !== $request_method) {
            wp_die(
                esc_html__('PeerTube connection actions require an explicit POST request.', 'argentwolf-video-processor')
            );
            exit;
        }
        if (! current_user_can('manage_options')) {
            wp_die(
                esc_html__('You are not allowed to administer PeerTube connections.', 'argentwolf-video-processor')
            );
            exit;
        }
    }

    private function verify_nonce(string $action): void
    {
        $nonce = isset($_POST[self::NONCE_FIELD]) && is_string($_POST[self::NONCE_FIELD])
            ? sanitize_text_field(wp_unslash($_POST[self::NONCE_FIELD]))
            : '';
        if ('' === $nonce || false === wp_verify_nonce($nonce, $action)) {
            wp_die(
                esc_html__('The PeerTube connection request could not be verified.', 'argentwolf-video-processor')
            );
            exit;
        }
    }

    /**
     * @param list<string> $expected
     * @return array<string, string>|null
     */
    // phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Every caller verifies its action-specific nonce before post_fields(); this helper intentionally preserves raw field bytes for strict domain validation.
    private function post_fields(array $expected): ?array
    {
        $actual = array_keys($_POST);
        foreach ($actual as $key) {
            if (! is_string($key)) {
                return null;
            }
        }
        sort($actual, SORT_STRING);
        $sorted_expected = $expected;
        sort($sorted_expected, SORT_STRING);
        if ($actual !== $sorted_expected) {
            return null;
        }

        $values = array();
        foreach ($expected as $key) {
            $raw = $_POST[$key] ?? null;
            if (! is_string($raw)) {
                return null;
            }
            $value = wp_unslash($raw);
            if (! is_string($value)) {
                return null;
            }
            $values[$key] = $value;
        }

        return $values;
    }
    // phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized

    private function raw_operation_id(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Missing -- The sanitized operation ID is required to derive the action-specific nonce checked by every caller immediately afterward.
        $value = isset($_POST['operation_id']) && is_string($_POST['operation_id'])
            ? sanitize_text_field(wp_unslash($_POST['operation_id']))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Missing
        return PeerTube_Connection_Input::operation_id($value);
    }

    private function now(): int
    {
        try {
            $now = ($this->clock)();
            return is_int($now) && $now > 0 ? $now : 0;
        } catch (Throwable) {
            return 0;
        }
    }

    /** @param array<string, mixed> $operation */
    private static function grant_available(array $operation, int $now): bool
    {
        if (
            $now < 1
            || $operation['grant_attempt_no'] >= PeerTube_Connection_Input::MAX_GRANT_ATTEMPTS
            || ! in_array(
                $operation['phase'],
                array(
                    PeerTube_Connection_State_Machine::PHASE_DISABLED,
                    PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP,
                    PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS,
                ),
                true
            )
        ) {
            return false;
        }

        return 0 === $operation['retry_after']
            || $now >= $operation['updated_at'] + $operation['retry_after'];
    }

    /** @param array<string, mixed> $result */
    private function redirect_result(
        array $result,
        string $expected_operation_id,
        bool $grant_boundary
    ): never
    {
        $validated = self::validated_result(
            $result,
            $expected_operation_id,
            $grant_boundary
        );
        if (null === $validated) {
            $this->redirect_notice('state_may_have_changed', $expected_operation_id);
        }

        $operation_id = '' !== $validated['operation_id']
            ? $validated['operation_id']
            : $expected_operation_id;
        $status = $validated['status'];
        $mutation = $validated['mutation'];

        if (
            Atomic_Option_Result::MUTATION_UNKNOWN === $mutation
            || (
                Atomic_Option_Result::MUTATION_APPLIED === $mutation
                && in_array(
                    $status,
                    array(
                        PeerTube_Connection_Coordinator::STATUS_CONFLICT,
                        PeerTube_Connection_Coordinator::STATUS_INDETERMINATE,
                        PeerTube_Connection_Coordinator::STATUS_REFUSED,
                        PeerTube_Connection_Coordinator::STATUS_OUTSIDE_SCOPE,
                    ),
                    true
                )
            )
        ) {
            $this->redirect_notice('state_may_have_changed', $operation_id);
        }

        $notice = match ($status) {
            PeerTube_Connection_Coordinator::STATUS_ADVANCED => 'connection_advanced',
            PeerTube_Connection_Coordinator::STATUS_READY_FOR_GRANT => 'ready_for_credentials',
            PeerTube_Password_Grant_Service::STATUS_AWAITING_OTP => 'otp_required',
            PeerTube_Password_Grant_Service::STATUS_AWAITING_CREDENTIALS => 'credentials_required',
            PeerTube_Password_Grant_Service::STATUS_READY_FOR_VERIFICATION => 'credentials_stored',
            PeerTube_Password_Grant_Service::STATUS_GRANT_INDETERMINATE => 'grant_indeterminate',
            PeerTube_Connection_Coordinator::STATUS_CONFLICT => 'connection_conflict',
            PeerTube_Connection_Coordinator::STATUS_INDETERMINATE => 'state_check_required',
            PeerTube_Connection_Coordinator::STATUS_REFUSED => 'request_refused',
            default => 'outside_checkpoint',
        };

        $this->redirect_notice($notice, $operation_id);
    }

    /** @param array<string, mixed> $result */
    private function redirect_identity_result(array $result, string $expected_operation_id): never
    {
        $validated = self::validated_identity_result($result, $expected_operation_id);
        if (null === $validated) {
            $this->redirect_notice('state_may_have_changed', $expected_operation_id);
        }

        $operation_id = '' !== $validated['operation_id']
            ? $validated['operation_id']
            : $expected_operation_id;
        $status = $validated['status'];
        $mutation = $validated['mutation'];
        $positive_mutation_statuses = array(
            PeerTube_Identity_Destination_Service::STATUS_ADVANCED,
            PeerTube_Identity_Destination_Service::STATUS_VERIFICATION_FAILED,
            PeerTube_Identity_Destination_Service::STATUS_AWAITING_DESTINATION,
            PeerTube_Identity_Destination_Service::STATUS_ACTIVATION_READY,
        );

        if (
            Atomic_Option_Result::MUTATION_UNKNOWN === $mutation
            || (
                Atomic_Option_Result::MUTATION_APPLIED === $mutation
                && ! in_array($status, $positive_mutation_statuses, true)
            )
        ) {
            $this->redirect_notice('state_may_have_changed', $operation_id);
        }

        $notice = match ($status) {
            PeerTube_Identity_Destination_Service::STATUS_ADVANCED => 'verification_advanced',
            PeerTube_Identity_Destination_Service::STATUS_VERIFICATION_FAILED => 'verification_failed',
            PeerTube_Identity_Destination_Service::STATUS_AWAITING_DESTINATION => 'identity_verified',
            PeerTube_Identity_Destination_Service::STATUS_ACTIVATION_READY => 'destination_verified',
            PeerTube_Identity_Destination_Service::STATUS_DESTINATION_UNAVAILABLE => 'destination_unavailable',
            PeerTube_Identity_Destination_Service::STATUS_CONFLICT => 'connection_conflict',
            PeerTube_Identity_Destination_Service::STATUS_INDETERMINATE => 'state_check_required',
            PeerTube_Identity_Destination_Service::STATUS_REFUSED => 'request_refused',
            default => 'outside_checkpoint',
        };
        $this->redirect_notice($notice, $operation_id);
    }

    /** @param array<string, mixed> $result */
    private function redirect_activation_result(array $result, string $expected_operation_id): never
    {
        $validated = self::validated_activation_result($result, $expected_operation_id);
        if (null === $validated) {
            $this->redirect_notice('state_may_have_changed', $expected_operation_id);
        }

        $operation_id = '' !== $validated['operation_id']
            ? $validated['operation_id']
            : $expected_operation_id;
        $status = $validated['status'];
        $mutation = $validated['mutation'];
        $positive = in_array(
            $status,
            array(
                PeerTube_Backend_Activation_Service::STATUS_ADVANCED,
                PeerTube_Backend_Activation_Service::STATUS_ACTIVE,
            ),
            true
        );

        if (
            Atomic_Option_Result::MUTATION_UNKNOWN === $mutation
            || (Atomic_Option_Result::MUTATION_APPLIED === $mutation && ! $positive)
        ) {
            $this->redirect_notice('state_may_have_changed', $operation_id);
        }

        $notice = match ($status) {
            PeerTube_Backend_Activation_Service::STATUS_ADVANCED => 'activation_advanced',
            PeerTube_Backend_Activation_Service::STATUS_ACTIVE => 'backend_activated',
            PeerTube_Backend_Activation_Service::STATUS_CONFLICT => 'connection_conflict',
            PeerTube_Backend_Activation_Service::STATUS_INDETERMINATE => 'state_check_required',
            PeerTube_Backend_Activation_Service::STATUS_REFUSED => 'request_refused',
            default => 'outside_checkpoint',
        };
        $this->redirect_notice($notice, $operation_id);
    }

    private function redirect_notice(string $notice, string $operation_id = ''): never
    {
        if (! array_key_exists($notice, self::notice_messages())) {
            $notice = 'state_may_have_changed';
        }
        $operation_id = PeerTube_Connection_Input::operation_id($operation_id);

        $arguments = array(
            'page' => Settings_Hub::PAGE_SLUG,
            'tab' => Settings_Hub::TAB_PEERTUBE,
            self::NOTICE_QUERY => $notice,
        );
        if ('' !== $operation_id) {
            $arguments[self::OPERATION_QUERY] = $operation_id;
        }

        $url = add_query_arg($arguments, admin_url('options-general.php'));
        ($this->redirector)($url, 303);

        wp_die(
            esc_html__('The PeerTube action completed, but the response could not be finalized.', 'argentwolf-video-processor')
        );
        exit;
    }

    private function reject_invalid_request(): never
    {
        wp_die(
            esc_html__('The PeerTube connection request was invalid.', 'argentwolf-video-processor')
        );
        exit;
    }

    /** @return array<string, mixed>|null */
    private function find_operation(string $operation_id): ?array
    {
        try {
            $raw = $this->actions->open_operations();
        } catch (Throwable) {
            return null;
        }
        if (! is_array($raw)) {
            return null;
        }

        $operations = $this->validated_operations($raw);
        return is_array($operations)
            ? $this->operation_from_list($operations, $operation_id)
            : null;
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @return list<array<string, mixed>>|null
     */
    private function validated_operations(array $operations): ?array
    {
        if (array_values($operations) !== $operations || count($operations) > self::MAX_OPEN_OPERATIONS) {
            return null;
        }

        $validated = array();
        foreach ($operations as $operation) {
            if (! self::valid_operation_projection($operation)) {
                return null;
            }
            $validated[] = $operation;
        }
        return $validated;
    }

    /**
     * @param list<array<string, mixed>> $operations
     * @return array<string, mixed>|null
     */
    private function operation_from_list(array $operations, string $operation_id): ?array
    {
        if ('' === $operation_id) {
            return null;
        }
        foreach ($operations as $operation) {
            if ($operation_id === $operation['operation_id']) {
                return $operation;
            }
        }
        return null;
    }

    /** @param mixed $operation */
    private static function valid_operation_projection(mixed $operation): bool
    {
        if (
            ! is_array($operation)
            || array(
                'operation_id',
                'backend_id',
                'origin',
                'label',
                'phase',
                'record_revision',
                'grant_attempt_no',
                'retry_after',
                'created_at',
                'updated_at',
            ) !== array_keys($operation)
        ) {
            return false;
        }

        $backend_id = Backend_Identity::sanitize($operation['backend_id']);
        $origin = PeerTube_Origin::sanitize($operation['origin']);
        return '' !== PeerTube_Connection_Input::operation_id($operation['operation_id'])
            && '' !== $backend_id
            && 'local' !== $backend_id
            && '' !== $origin
            && $origin === $operation['origin']
            && '' !== PeerTube_Connection_Input::label($operation['label'])
            && '' !== PeerTube_Connection_Input::phase($operation['phase'])
            && is_int($operation['record_revision'])
            && $operation['record_revision'] >= 1
            && is_int($operation['grant_attempt_no'])
            && $operation['grant_attempt_no'] >= 0
            && $operation['grant_attempt_no'] <= PeerTube_Connection_Input::MAX_GRANT_ATTEMPTS
            && is_int($operation['retry_after'])
            && $operation['retry_after'] >= 0
            && $operation['retry_after'] <= 86400
            && is_int($operation['created_at'])
            && $operation['created_at'] >= 1
            && is_int($operation['updated_at'])
            && $operation['updated_at'] >= $operation['created_at']
            && $operation['updated_at'] <= PHP_INT_MAX - $operation['retry_after'];
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>|null
     */
    private static function validated_result(
        array $result,
        string $expected_operation_id,
        bool $grant_boundary
    ): ?array
    {
        $base_keys = array(
            'status',
            'mutation',
            'operation_id',
            'backend_id',
            'phase',
            'record_revision',
        );
        $keys = array_keys($result);
        $expected_keys = $grant_boundary
            ? array_merge($base_keys, array('retry_after'))
            : $base_keys;
        if ($expected_keys !== $keys) {
            return null;
        }

        $coordinator_statuses = array(
            PeerTube_Connection_Coordinator::STATUS_ADVANCED,
            PeerTube_Connection_Coordinator::STATUS_READY_FOR_GRANT,
            PeerTube_Connection_Coordinator::STATUS_CONFLICT,
            PeerTube_Connection_Coordinator::STATUS_INDETERMINATE,
            PeerTube_Connection_Coordinator::STATUS_REFUSED,
            PeerTube_Connection_Coordinator::STATUS_OUTSIDE_SCOPE,
        );
        $grant_statuses = array(
            PeerTube_Password_Grant_Service::STATUS_ADVANCED,
            PeerTube_Password_Grant_Service::STATUS_READY_FOR_GRANT,
            PeerTube_Password_Grant_Service::STATUS_AWAITING_OTP,
            PeerTube_Password_Grant_Service::STATUS_AWAITING_CREDENTIALS,
            PeerTube_Password_Grant_Service::STATUS_READY_FOR_VERIFICATION,
            PeerTube_Password_Grant_Service::STATUS_GRANT_INDETERMINATE,
            PeerTube_Password_Grant_Service::STATUS_CONFLICT,
            PeerTube_Password_Grant_Service::STATUS_INDETERMINATE,
            PeerTube_Password_Grant_Service::STATUS_REFUSED,
            PeerTube_Password_Grant_Service::STATUS_OUTSIDE_SCOPE,
        );
        $statuses = $grant_boundary ? $grant_statuses : $coordinator_statuses;
        $mutations = array(
            Atomic_Option_Result::MUTATION_NONE,
            Atomic_Option_Result::MUTATION_APPLIED,
            Atomic_Option_Result::MUTATION_UNKNOWN,
        );

        if (
            ! is_string($result['status'])
            || ! in_array($result['status'], $statuses, true)
            || ! is_string($result['mutation'])
            || ! in_array($result['mutation'], $mutations, true)
            || ! is_string($result['operation_id'])
            || ('' !== $result['operation_id']
                && '' === PeerTube_Connection_Input::operation_id($result['operation_id']))
            || ('' !== $expected_operation_id
                && '' !== $result['operation_id']
                && $expected_operation_id !== $result['operation_id'])
            || ! is_string($result['backend_id'])
            || ('' !== $result['backend_id']
                && ('local' === $result['backend_id']
                    || $result['backend_id'] !== Backend_Identity::sanitize($result['backend_id'])))
            || ! is_string($result['phase'])
            || ('' !== $result['phase'] && '' === PeerTube_Connection_Input::phase($result['phase']))
            || ! is_int($result['record_revision'])
            || $result['record_revision'] < 0
            || ($grant_boundary
                && (! is_int($result['retry_after'])
                    || $result['retry_after'] < 0
                    || $result['retry_after'] > 86400))
        ) {
            return null;
        }

        $positive_phases = array(
            PeerTube_Connection_Coordinator::STATUS_READY_FOR_GRANT =>
                PeerTube_Connection_State_Machine::PHASE_DISABLED,
            PeerTube_Password_Grant_Service::STATUS_AWAITING_OTP =>
                PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP,
            PeerTube_Password_Grant_Service::STATUS_AWAITING_CREDENTIALS =>
                PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS,
            PeerTube_Password_Grant_Service::STATUS_READY_FOR_VERIFICATION =>
                PeerTube_Connection_State_Machine::PHASE_SECRET_STORED,
            PeerTube_Password_Grant_Service::STATUS_GRANT_INDETERMINATE =>
                PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE,
        );
        $positive = PeerTube_Connection_Coordinator::STATUS_ADVANCED === $result['status']
            || array_key_exists($result['status'], $positive_phases);
        $advanced_phases = $grant_boundary
            ? array(
                PeerTube_Connection_State_Machine::PHASE_DISABLED,
                PeerTube_Connection_State_Machine::PHASE_GRANT_IN_FLIGHT,
                PeerTube_Connection_State_Machine::PHASE_OTP_RESULT_PENDING,
                PeerTube_Connection_State_Machine::PHASE_CREDENTIAL_RESULT_PENDING,
                PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP,
                PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS,
                PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE,
                PeerTube_Connection_State_Machine::PHASE_SECRET_WRITE_PLANNED,
                PeerTube_Connection_State_Machine::PHASE_SECRET_STORED,
            )
            : array(
                PeerTube_Connection_State_Machine::PHASE_PREPARED,
                PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED,
                PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVED,
                PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED,
                PeerTube_Connection_State_Machine::PHASE_DISABLED,
            );
        if (
            $positive
            && (
                '' === $result['operation_id']
                || '' === $result['backend_id']
                || '' === $result['phase']
                || $result['record_revision'] < 1
                || (isset($positive_phases[$result['status']])
                    && $positive_phases[$result['status']] !== $result['phase'])
                || (PeerTube_Connection_Coordinator::STATUS_ADVANCED === $result['status']
                    && ! in_array($result['phase'], $advanced_phases, true))
            )
        ) {
            return null;
        }

        return $result;
    }

    /** @param array<string, mixed> $result @return array<string, mixed>|null */
    private static function validated_identity_result(
        array $result,
        string $expected_operation_id
    ): ?array {
        if (
            array(
                'status',
                'mutation',
                'operation_id',
                'backend_id',
                'phase',
                'record_revision',
                'retry_after',
            ) !== array_keys($result)
            || ! in_array(
                $result['status'] ?? null,
                array(
                    PeerTube_Identity_Destination_Service::STATUS_ADVANCED,
                    PeerTube_Identity_Destination_Service::STATUS_DESTINATION_UNAVAILABLE,
                    PeerTube_Identity_Destination_Service::STATUS_VERIFICATION_FAILED,
                    PeerTube_Identity_Destination_Service::STATUS_AWAITING_DESTINATION,
                    PeerTube_Identity_Destination_Service::STATUS_ACTIVATION_READY,
                    PeerTube_Identity_Destination_Service::STATUS_CONFLICT,
                    PeerTube_Identity_Destination_Service::STATUS_INDETERMINATE,
                    PeerTube_Identity_Destination_Service::STATUS_REFUSED,
                    PeerTube_Identity_Destination_Service::STATUS_OUTSIDE_SCOPE,
                ),
                true
            )
            || ! in_array(
                $result['mutation'] ?? null,
                array(
                    Atomic_Option_Result::MUTATION_NONE,
                    Atomic_Option_Result::MUTATION_APPLIED,
                    Atomic_Option_Result::MUTATION_UNKNOWN,
                ),
                true
            )
            || ! is_string($result['operation_id'] ?? null)
            || ('' !== $expected_operation_id && $result['operation_id'] !== $expected_operation_id)
            || ('' !== $result['operation_id']
                && '' === PeerTube_Connection_Input::operation_id($result['operation_id']))
            || ! is_string($result['backend_id'] ?? null)
            || ('' !== $result['backend_id']
                && ('local' === $result['backend_id']
                    || $result['backend_id'] !== Backend_Identity::sanitize($result['backend_id'])))
            || ! is_string($result['phase'] ?? null)
            || ('' !== $result['phase'] && '' === PeerTube_Connection_Input::phase($result['phase']))
            || ! is_int($result['record_revision'] ?? null)
            || $result['record_revision'] < 0
            || ! is_int($result['retry_after'] ?? null)
            || $result['retry_after'] < 0
            || $result['retry_after'] > 86400
        ) {
            return null;
        }

        $positive_phases = array(
            PeerTube_Identity_Destination_Service::STATUS_ADVANCED =>
                PeerTube_Connection_State_Machine::PHASE_VERIFICATION_IN_FLIGHT,
            PeerTube_Identity_Destination_Service::STATUS_DESTINATION_UNAVAILABLE =>
                PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION,
            PeerTube_Identity_Destination_Service::STATUS_AWAITING_DESTINATION =>
                PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION,
            PeerTube_Identity_Destination_Service::STATUS_ACTIVATION_READY =>
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY,
        );
        if (isset($positive_phases[$result['status']]) && (
            '' === $result['operation_id']
            || '' === $result['backend_id']
            || $positive_phases[$result['status']] !== $result['phase']
            || $result['record_revision'] < 1
        )) {
            return null;
        }
        if (
            PeerTube_Identity_Destination_Service::STATUS_VERIFICATION_FAILED === $result['status']
            && (
                '' === $result['operation_id']
                || '' === $result['backend_id']
                || $result['record_revision'] < 1
                || ! in_array(
                    $result['phase'],
                    array(
                        PeerTube_Connection_State_Machine::PHASE_VERIFICATION_FAILED,
                        PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION,
                    ),
                    true
                )
                || (
                    PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION === $result['phase']
                    && Atomic_Option_Result::MUTATION_NONE !== $result['mutation']
                )
            )
        ) {
            return null;
        }

        return $result;
    }

    /** @param array<string, mixed> $result @return array<string, mixed>|null */
    private static function validated_activation_result(
        array $result,
        string $expected_operation_id
    ): ?array {
        if (
            array(
                'status',
                'mutation',
                'operation_id',
                'backend_id',
                'phase',
                'record_revision',
                'retry_after',
            ) !== array_keys($result)
            || ! in_array(
                $result['status'] ?? null,
                array(
                    PeerTube_Backend_Activation_Service::STATUS_ADVANCED,
                    PeerTube_Backend_Activation_Service::STATUS_ACTIVE,
                    PeerTube_Backend_Activation_Service::STATUS_CONFLICT,
                    PeerTube_Backend_Activation_Service::STATUS_INDETERMINATE,
                    PeerTube_Backend_Activation_Service::STATUS_REFUSED,
                    PeerTube_Backend_Activation_Service::STATUS_OUTSIDE_SCOPE,
                ),
                true
            )
            || ! in_array(
                $result['mutation'] ?? null,
                array(
                    Atomic_Option_Result::MUTATION_NONE,
                    Atomic_Option_Result::MUTATION_APPLIED,
                    Atomic_Option_Result::MUTATION_UNKNOWN,
                ),
                true
            )
            || ! is_string($result['operation_id'] ?? null)
            || $result['operation_id'] !== $expected_operation_id
            || '' === PeerTube_Connection_Input::operation_id($result['operation_id'])
            || ! is_string($result['backend_id'] ?? null)
            || ('' !== $result['backend_id']
                && ('local' === $result['backend_id']
                    || $result['backend_id'] !== Backend_Identity::sanitize($result['backend_id'])))
            || ! is_string($result['phase'] ?? null)
            || ('' !== $result['phase'] && '' === PeerTube_Connection_Input::phase($result['phase']))
            || ! is_int($result['record_revision'] ?? null)
            || $result['record_revision'] < 0
            || 0 !== ($result['retry_after'] ?? null)
        ) {
            return null;
        }

        if (PeerTube_Backend_Activation_Service::STATUS_ADVANCED === $result['status']) {
            if (
                '' === $result['backend_id']
                || $result['record_revision'] < 1
                || ! in_array(
                    $result['phase'],
                    array(
                        PeerTube_Connection_State_Machine::PHASE_ACTIVATION_PLANNED,
                        PeerTube_Connection_State_Machine::PHASE_ACTIVE_PENDING_CLOSE,
                    ),
                    true
                )
            ) {
                return null;
            }
        }

        if (PeerTube_Backend_Activation_Service::STATUS_ACTIVE === $result['status']) {
            if (
                '' === $result['backend_id']
                || PeerTube_Connection_State_Machine::PHASE_COMPLETE !== $result['phase']
                || $result['record_revision'] < 1
            ) {
                return null;
            }
        }

        return $result;
    }

    /** @param mixed $result @return array<string, mixed>|null */
    private static function validated_discovery_result(
        mixed $result,
        string $expected_operation_id
    ): ?array {
        if (
            ! is_array($result)
            || array(
                'status',
                'mutation',
                'operation_id',
                'backend_id',
                'phase',
                'record_revision',
                'retry_after',
                'identity',
                'destinations',
            ) !== array_keys($result)
            || ! in_array(
                $result['status'] ?? null,
                array(
                    PeerTube_Identity_Destination_Service::STATUS_DESTINATIONS_READY,
                    PeerTube_Identity_Destination_Service::STATUS_NO_DESTINATIONS,
                    PeerTube_Identity_Destination_Service::STATUS_VERIFICATION_FAILED,
                    PeerTube_Identity_Destination_Service::STATUS_CONFLICT,
                    PeerTube_Identity_Destination_Service::STATUS_INDETERMINATE,
                    PeerTube_Identity_Destination_Service::STATUS_REFUSED,
                    PeerTube_Identity_Destination_Service::STATUS_OUTSIDE_SCOPE,
                ),
                true
            )
            || Atomic_Option_Result::MUTATION_NONE !== ($result['mutation'] ?? null)
            || $expected_operation_id !== ($result['operation_id'] ?? null)
            || '' === PeerTube_Connection_Input::operation_id($result['operation_id'])
            || ! is_string($result['backend_id'] ?? null)
            || ('' !== $result['backend_id']
                && ('local' === $result['backend_id']
                    || $result['backend_id'] !== Backend_Identity::sanitize($result['backend_id'])))
            || ! is_string($result['phase'] ?? null)
            || ('' !== $result['phase'] && '' === PeerTube_Connection_Input::phase($result['phase']))
            || ! is_int($result['record_revision'] ?? null)
            || $result['record_revision'] < 0
            || ! is_int($result['retry_after'] ?? null)
            || $result['retry_after'] < 0
            || $result['retry_after'] > 86400
            || ! is_array($result['identity'] ?? null)
            || ! is_array($result['destinations'] ?? null)
        ) {
            return null;
        }

        $success = in_array(
            $result['status'],
            array(
                PeerTube_Identity_Destination_Service::STATUS_DESTINATIONS_READY,
                PeerTube_Identity_Destination_Service::STATUS_NO_DESTINATIONS,
            ),
            true
        );
        if (! $success) {
            return array() === $result['identity'] && array() === $result['destinations']
                ? $result
                : null;
        }
        if (
            '' === $result['backend_id']
            || PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION !== $result['phase']
            || $result['record_revision'] < 1
            || ! self::valid_identity_projection($result['identity'])
            || array_values($result['destinations']) !== $result['destinations']
            || count($result['destinations']) > 500
            || (PeerTube_Identity_Destination_Service::STATUS_NO_DESTINATIONS === $result['status']
                && array() !== $result['destinations'])
            || (PeerTube_Identity_Destination_Service::STATUS_DESTINATIONS_READY === $result['status']
                && array() === $result['destinations'])
        ) {
            return null;
        }

        $last_id = 0;
        foreach ($result['destinations'] as $destination) {
            if (! self::valid_destination_projection($destination)) {
                return null;
            }
            $numeric_id = (int) $destination['id'];
            if ($numeric_id <= $last_id) {
                return null;
            }
            $last_id = $numeric_id;
        }
        return $result;
    }

    private static function valid_identity_projection(mixed $identity): bool
    {
        return is_array($identity)
            && array('user_id', 'username', 'account_id', 'account_name') === array_keys($identity)
            && '' !== PeerTube_Connection_Input::destination_id($identity['user_id'])
            && self::valid_machine_name($identity['username'])
            && '' !== PeerTube_Connection_Input::destination_id($identity['account_id'])
            && self::valid_machine_name($identity['account_name']);
    }

    private static function valid_destination_projection(mixed $destination): bool
    {
        return is_array($destination)
            && array('id', 'name', 'display_name', 'authority') === array_keys($destination)
            && '' !== PeerTube_Connection_Input::destination_id($destination['id'])
            && self::valid_machine_name($destination['name'])
            && self::valid_display_name($destination['display_name'])
            && 'owned' === $destination['authority'];
    }

    private static function valid_machine_name(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= 50
            && 1 === preg_match('/^[a-z0-9_]+(?:[a-z0-9_.-]+[a-z0-9_]+)?$/D', $value);
    }

    private static function valid_display_name(mixed $value): bool
    {
        if (
            ! is_string($value)
            || '' === $value
            || trim($value) !== $value
            || strlen($value) > 1024
            || 1 !== preg_match('//u', $value)
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            return false;
        }
        $characters = preg_match_all('/./us', $value, $matches);
        return is_int($characters) && $characters <= 240;
    }

    private function query_page(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only settings-page selector; no state mutation occurs.
        $value = isset($_GET['page']) && is_string($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        return Settings_Hub::PAGE_SLUG === $value ? $value : '';
    }

    private function query_notice(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only redirect notice; no state mutation occurs.
        $value = isset($_GET[self::NOTICE_QUERY]) && is_string($_GET[self::NOTICE_QUERY])
            ? sanitize_key(wp_unslash($_GET[self::NOTICE_QUERY]))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return $value;
    }

    private function query_operation_id(): string
    {
        // phpcs:disable WordPress.Security.NonceVerification.Recommended -- Read-only operation selector; mutations use separate nonce-protected POST actions.
        $value = isset($_GET[self::OPERATION_QUERY]) && is_string($_GET[self::OPERATION_QUERY])
            ? sanitize_text_field(wp_unslash($_GET[self::OPERATION_QUERY]))
            : '';
        // phpcs:enable WordPress.Security.NonceVerification.Recommended
        return PeerTube_Connection_Input::operation_id($value);
    }

    private function discovery_requested(string $operation_id): bool
    {
        // phpcs:disable WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Exact query bytes are unslashed, shape-checked, and nonce-verified below before use.
        $requested = $_GET[self::DISCOVER_QUERY] ?? null;
        if (null === $requested) {
            return false;
        }
        $nonce = $_GET[self::NONCE_FIELD] ?? null;
        if (! is_string($requested) || ! is_string($nonce)) {
            $this->reject_invalid_request();
        }
        $raw_requested = $requested;
        $raw_nonce = $nonce;
        $requested = wp_unslash($requested);
        $nonce = wp_unslash($nonce);
        // phpcs:enable WordPress.Security.ValidatedSanitizedInput.MissingUnslash,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
        if (
            ! is_string($requested)
            || ! is_string($nonce)
            || $raw_requested !== $requested
            || $raw_nonce !== $nonce
            || '1' !== $requested
            || false === wp_verify_nonce(
                $nonce,
                self::NONCE_DISCOVER_DESTINATIONS . $operation_id
            )
        ) {
            $this->reject_invalid_request();
        }
        return true;
    }

    /** @return array<string, string> */
    private static function notice_messages(): array
    {
        return array(
            'connection_advanced' => __('Setup advanced to the next step. Continue setup to proceed.', 'argentwolf-video-processor'),
            'ready_for_credentials' => __('Local setup is complete. Sign in to PeerTube to authorize this connection.', 'argentwolf-video-processor'),
            'otp_required' => __('PeerTube requires a six-digit OTP. Enter the username, password, and OTP again in a fresh explicit request.', 'argentwolf-video-processor'),
            'credentials_required' => __('PeerTube did not accept the previous sign-in or requested a delay. Check the status shown below before trying again.', 'argentwolf-video-processor'),
            'credentials_stored' => __('PeerTube sign-in succeeded and the connection credentials were stored securely. Verify the account and channels to continue.', 'argentwolf-video-processor'),
            'verification_advanced' => __('Account verification started. Continue setup to complete the PeerTube account and channel check.', 'argentwolf-video-processor'),
            'verification_failed' => __('The PeerTube account or its owned channels could not be verified. The connection remains inactive.', 'argentwolf-video-processor'),
            'identity_verified' => __('The PeerTube account and its owned channels were verified. Choose the channel this connection should use.', 'argentwolf-video-processor'),
            'destination_verified' => __('The selected PeerTube channel was verified. Activate the connection to finish setup.', 'argentwolf-video-processor'),
            'activation_advanced' => __('Activation advanced to the next step. Continue activation to finish connecting this PeerTube server.', 'argentwolf-video-processor'),
            'backend_activated' => __('The PeerTube server is connected. Next, open the Publishing tab and load this server’s publishing options before configuring videos. No video was uploaded during connection setup.', 'argentwolf-video-processor'),
            'lifecycle_advanced' => __('The PeerTube credential update advanced to the next step. Continue if another action is offered.', 'argentwolf-video-processor'),
            'token_refreshed' => __('The PeerTube connection credentials were refreshed successfully.', 'argentwolf-video-processor'),
            'backend_disconnected' => __('The PeerTube server was disconnected and its stored connection credentials were removed.', 'argentwolf-video-processor'),
            'upload_policy_saved' => __('The PeerTube upload chunk size was saved for this server.', 'argentwolf-video-processor'),
            'refresh_rate_limited' => __('PeerTube asked ArgentWolf Video Processor to wait before refreshing the connection credentials.', 'argentwolf-video-processor'),
            'reauthentication_required' => __('The saved PeerTube authorization can no longer be refreshed. Reconnect the PeerTube server to authorize it again.', 'argentwolf-video-processor'),
            'lifecycle_indeterminate' => __('PeerTube may or may not have completed the credential change. For safety, ArgentWolf Video Processor will not repeat it automatically.', 'argentwolf-video-processor'),
            'destination_unavailable' => __('That destination is not in the account’s current eligible owned-channel set. No selection was changed.', 'argentwolf-video-processor'),
            'grant_indeterminate' => __('PeerTube may or may not have accepted the sign-in. For safety, ArgentWolf Video Processor will not submit the credentials again automatically. Start a new connection if the status cannot be confirmed.', 'argentwolf-video-processor'),
            'connection_conflict' => __('The connection changed while this request was being processed. Reload the page before continuing.', 'argentwolf-video-processor'),
            'state_check_required' => __('ArgentWolf Video Processor could not confirm the previous local change. Use the action shown for the current setup status.', 'argentwolf-video-processor'),
            'state_may_have_changed' => __('ArgentWolf Video Processor could not confirm the previous change and did not repeat it automatically. Review the current setup status before continuing.', 'argentwolf-video-processor'),
            'request_refused' => __('The PeerTube connection request could not be completed. Review the current settings and status, then try again.', 'argentwolf-video-processor'),
            'invalid_backend_id' => __('Invalid Backend ID. Use 1–64 lowercase letters, numbers, hyphens, or underscores, beginning with a letter or number. Spaces and uppercase letters are not allowed.', 'argentwolf-video-processor'),
            'reserved_backend_id' => __('Backend ID “local” is reserved by ArgentWolf Video Processor. Choose another Backend ID.', 'argentwolf-video-processor'),
            'invalid_peertube_url' => __('Invalid PeerTube URL. Enter the exact HTTPS base URL with a DNS hostname and no credentials, API path, query string, or fragment.', 'argentwolf-video-processor'),
            'invalid_connection_label' => __('Invalid Connection Label. Enter a friendly name up to 120 characters without leading or trailing spaces or control characters.', 'argentwolf-video-processor'),
            'invalid_request' => __('The PeerTube connection request contained invalid or unexpected input and was not performed.', 'argentwolf-video-processor'),
            'outside_checkpoint' => __('This connection cannot be continued from its current state. No action was performed.', 'argentwolf-video-processor'),
        );
    }

    private static function backend_state_label(string $state): string
    {
        return match ($state) {
            'active' => __('Connected', 'argentwolf-video-processor'),
            'retired' => __('Disconnected', 'argentwolf-video-processor'),
            'disabled' => __('Not connected', 'argentwolf-video-processor'),
            default => __('Needs attention', 'argentwolf-video-processor'),
        };
    }

    private static function lifecycle_label(string $action, string $phase): string
    {
        if ('' === $action && '' === $phase) {
            return __('Credentials ready', 'argentwolf-video-processor');
        }
        return match ($phase) {
            'refresh_ready' => __('Credential refresh ready', 'argentwolf-video-processor'),
            'refresh_wait' => __('Waiting to refresh credentials', 'argentwolf-video-processor'),
            'refresh_in_flight' => __('Refreshing credentials', 'argentwolf-video-processor'),
            'refresh_complete' => __('Credentials current', 'argentwolf-video-processor'),
            'refresh_reauthentication_required' => __('New sign-in required', 'argentwolf-video-processor'),
            'refresh_indeterminate' => __('Credential refresh needs attention', 'argentwolf-video-processor'),
            'disconnect_ready' => __('Disconnect ready', 'argentwolf-video-processor'),
            'disconnect_revoke_in_flight' => __('Disconnecting from PeerTube', 'argentwolf-video-processor'),
            'disconnect_revoked' => __('PeerTube access revoked', 'argentwolf-video-processor'),
            'disconnect_indeterminate' => __('Disconnect status needs attention', 'argentwolf-video-processor'),
            'disconnect_retire_planned' => __('Finalizing disconnect', 'argentwolf-video-processor'),
            'disconnect_retired' => __('Removing saved credentials', 'argentwolf-video-processor'),
            'disconnect_complete' => __('Disconnected', 'argentwolf-video-processor'),
            default => 'refresh' === $action
                ? __('Credential refresh needs attention', 'argentwolf-video-processor')
                : ('disconnect' === $action
                    ? __('Disconnect needs attention', 'argentwolf-video-processor')
                    : __('Credentials ready', 'argentwolf-video-processor')),
        };
    }

    private static function phase_help(string $phase): string
    {
        return match ($phase) {
            PeerTube_Connection_State_Machine::PHASE_PREPARED,
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED,
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVED,
            PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED => __('Continue setup while ArgentWolf Video Processor prepares secure local storage. PeerTube is not contacted during these preparation steps.', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_DISABLED,
            PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS => __('Sign in with the PeerTube account that owns the channels you want to publish to.', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP => __('Enter the PeerTube username and password again together with the requested six-digit one-time code.', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_GRANT_IN_FLIGHT,
            PeerTube_Connection_State_Machine::PHASE_OTP_RESULT_PENDING,
            PeerTube_Connection_State_Machine::PHASE_CREDENTIAL_RESULT_PENDING,
            PeerTube_Connection_State_Machine::PHASE_SECRET_WRITE_PLANNED => __('Check the saved setup status. ArgentWolf Video Processor will not repeat a sign-in request automatically.', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE => __('Check the local setup status. If the sign-in result cannot be confirmed, start a new connection rather than repeating the uncertain request.', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_SECRET_STORED => __('Start account verification to confirm the signed-in PeerTube account and its owned channels.', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_VERIFICATION_IN_FLIGHT => __('Continue account verification.', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_VERIFICATION_FAILED => __('Retry account verification when the page offers that action.', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION => __('Load the owned channels and choose the default PeerTube channel for this connection.', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY => __('Activate the PeerTube server to make it available for publishing.', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_ACTIVATION_PLANNED,
            PeerTube_Connection_State_Machine::PHASE_ACTIVE_PENDING_CLOSE => __('Continue activation until setup is complete.', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_COMPLETE => __('Connection complete. Go to the Publishing tab and load this server’s publishing options before configuring videos for PeerTube.', 'argentwolf-video-processor'),
            default => __('Review the current status before taking another action.', 'argentwolf-video-processor'),
        };
    }

    private static function phase_step(string $phase): int
    {
        return match ($phase) {
            PeerTube_Connection_State_Machine::PHASE_PREPARED,
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED,
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVED,
            PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED => 1,
            PeerTube_Connection_State_Machine::PHASE_DISABLED,
            PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS,
            PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP,
            PeerTube_Connection_State_Machine::PHASE_GRANT_IN_FLIGHT,
            PeerTube_Connection_State_Machine::PHASE_OTP_RESULT_PENDING,
            PeerTube_Connection_State_Machine::PHASE_CREDENTIAL_RESULT_PENDING,
            PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE,
            PeerTube_Connection_State_Machine::PHASE_SECRET_WRITE_PLANNED => 2,
            PeerTube_Connection_State_Machine::PHASE_SECRET_STORED,
            PeerTube_Connection_State_Machine::PHASE_VERIFICATION_IN_FLIGHT,
            PeerTube_Connection_State_Machine::PHASE_VERIFICATION_FAILED => 3,
            PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION => 4,
            PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY,
            PeerTube_Connection_State_Machine::PHASE_ACTIVATION_PLANNED,
            PeerTube_Connection_State_Machine::PHASE_ACTIVE_PENDING_CLOSE => 5,
            PeerTube_Connection_State_Machine::PHASE_COMPLETE => 6,
            default => 1,
        };
    }

    private static function phase_label(string $phase): string
    {
        return match ($phase) {
            PeerTube_Connection_State_Machine::PHASE_PREPARED,
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED,
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVED,
            PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED => __('Preparing connection', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_DISABLED,
            PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS => __('PeerTube sign-in required', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP => __('One-time code required', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_GRANT_IN_FLIGHT,
            PeerTube_Connection_State_Machine::PHASE_OTP_RESULT_PENDING,
            PeerTube_Connection_State_Machine::PHASE_CREDENTIAL_RESULT_PENDING => __('Completing PeerTube sign-in', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE => __('Sign-in status needs attention', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_SECRET_WRITE_PLANNED => __('Saving connection credentials', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_SECRET_STORED => __('Credentials saved; account verification required', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_VERIFICATION_IN_FLIGHT => __('Verifying PeerTube account', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_VERIFICATION_FAILED => __('Account verification needs attention', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION => __('Choose a PeerTube channel', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY => __('Ready to activate', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_ACTIVATION_PLANNED,
            PeerTube_Connection_State_Machine::PHASE_ACTIVE_PENDING_CLOSE => __('Activating connection', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_COMPLETE => __('Connected', 'argentwolf-video-processor'),
            default => __('Setup needs attention', 'argentwolf-video-processor'),
        };
    }
}

// EOF: includes/PeerTube_Connection_Admin.php
