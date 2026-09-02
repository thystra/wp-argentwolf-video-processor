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
        if (
            '' === $backend_id
            || 'local' === $backend_id
            || '' === $origin
            || $origin !== $values['origin']
            || '' === $label
        ) {
            $this->redirect_notice('invalid_request');
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

    private function lifecycle_action(string $expected_action, string $nonce_prefix, string $method): void
    {
        $this->require_post_administrator();
        $backend_id = isset($_POST['backend_id']) && is_string($_POST['backend_id'])
            ? Backend_Identity::sanitize(wp_unslash($_POST['backend_id']))
            : '';
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
        if (! current_user_can('manage_options') || self::PAGE_SLUG !== $this->query_page()) {
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
            'token_refreshed', 'backend_disconnected' =>
                'notice notice-success',
            'invalid_request', 'request_refused', 'connection_conflict' =>
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
        <div class="wrap">
            <h1><?php esc_html_e('PeerTube Connection — ArgentWolf Video Processor', 'argentwolf-video-processor'); ?></h1>
            <div class="notice notice-warning inline"><p><?php esc_html_e('This unreleased development checkpoint can explicitly refresh a managed PeerTube token pair and disconnect a backend by revoking its current token, retiring its local descriptor, and deleting the managed credential. No media upload, processing, publication, library, retention, or remote-media mutation is implemented.', 'argentwolf-video-processor'); ?></p></div>

            <h2><?php esc_html_e('Start a connection operation', 'argentwolf-video-processor'); ?></h2>
            <p><?php esc_html_e('Use an exact canonical HTTPS origin with no path, query, fragment, credentials, or trailing slash. A backend ID is a permanent lowercase identifier.', 'argentwolf-video-processor'); ?></p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" autocomplete="off" style="max-width:900px">
                <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_START); ?>">
                <?php wp_nonce_field(self::NONCE_START, self::NONCE_FIELD, false); ?>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="awvp-peertube-backend-id"><?php esc_html_e('Backend ID', 'argentwolf-video-processor'); ?></label></th><td><input class="regular-text code" id="awvp-peertube-backend-id" name="backend_id" type="text" maxlength="64" pattern="[a-z0-9][a-z0-9_-]{0,63}" required></td></tr>
                    <tr><th scope="row"><label for="awvp-peertube-origin"><?php esc_html_e('PeerTube origin', 'argentwolf-video-processor'); ?></label></th><td><input class="regular-text code" id="awvp-peertube-origin" name="origin" type="url" placeholder="https://video.example.org" required></td></tr>
                    <tr><th scope="row"><label for="awvp-peertube-label"><?php esc_html_e('Connection label', 'argentwolf-video-processor'); ?></label></th><td><input class="regular-text" id="awvp-peertube-label" name="label" type="text" maxlength="120" required></td></tr>
                </table>
                <p><button class="button button-primary" type="submit"><?php esc_html_e('Start disabled connection', 'argentwolf-video-processor'); ?></button></p>
            </form>

            <h2><?php esc_html_e('Managed PeerTube backends', 'argentwolf-video-processor'); ?></h2>
            <?php $this->render_managed_backends($managed_backends); ?>

            <h2><?php esc_html_e('Open connection operations', 'argentwolf-video-processor'); ?></h2>
            <?php $this->render_operation_list($operations, $selected_id); ?>

            <?php if (null !== $selected) : ?>
                <?php $this->render_selected_operation($selected, $discovery); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    /** @param list<array<string,mixed>> $backends */
    private function render_managed_backends(array $backends): void
    {
        if ([] === $backends) {
            echo '<p>' . esc_html__('No managed PeerTube backends are registered.', 'argentwolf-video-processor') . '</p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:1100px">
            <thead><tr><th><?php esc_html_e('Label', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Origin', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('State', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Lifecycle', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Explicit actions', 'argentwolf-video-processor'); ?></th></tr></thead>
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
                    <td><?php echo esc_html($state); ?></td>
                    <td><code><?php echo esc_html($lifecycle_phase); ?></code></td>
                    <td>
                    <?php if ('active' === $state && ! $disconnect_pending) : ?>
                        <?php $this->render_backend_action(self::ACTION_REFRESH, self::NONCE_REFRESH, (string) $backend['backend_id'], __('Refresh token lifecycle', 'argentwolf-video-processor')); ?>
                        <?php if (! $refresh_blocks_disconnect) : ?>
                            <?php $this->render_backend_action(self::ACTION_DISCONNECT, self::NONCE_DISCONNECT, (string) $backend['backend_id'], __('Disconnect PeerTube', 'argentwolf-video-processor'), true); ?>
                        <?php endif; ?>
                    <?php elseif ($disconnect_pending && in_array($state, array('active', 'retired'), true)) : ?>
                        <?php $this->render_backend_action(self::ACTION_DISCONNECT, self::NONCE_DISCONNECT, (string) $backend['backend_id'], __('Continue disconnect', 'argentwolf-video-processor'), true); ?>
                    <?php else : ?>
                        <?php esc_html_e('No active remote credential action.', 'argentwolf-video-processor'); ?>
                    <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><?php esc_html_e('Refresh and disconnect are explicit administrator POST actions. Disconnect may retire local authority after an uncertain revoke, but AWVP never retries an uncertain revoke automatically.', 'argentwolf-video-processor'); ?></p>
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
            echo '<p>' . esc_html__('No open PeerTube connection operations.', 'argentwolf-video-processor') . '</p>';
            return;
        }
        ?>
        <table class="widefat striped" style="max-width:1100px">
            <thead><tr><th><?php esc_html_e('Label', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Origin', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Backend ID', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Phase', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Updated', 'argentwolf-video-processor'); ?></th><th><?php esc_html_e('Action', 'argentwolf-video-processor'); ?></th></tr></thead>
            <tbody>
            <?php foreach ($operations as $operation) : ?>
                <?php
                $url = add_query_arg(
                    array(
                        'page' => self::PAGE_SLUG,
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
                    <td><?php echo esc_html(gmdate('Y-m-d H:i:s \U\T\C', $operation['updated_at'])); ?></td>
                    <td><a class="button button-secondary" href="<?php echo esc_url($url); ?>"><?php esc_html_e('Review', 'argentwolf-video-processor'); ?></a></td>
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
        <h2><?php esc_html_e('Selected operation', 'argentwolf-video-processor'); ?></h2>
        <table class="widefat striped" style="max-width:900px"><tbody>
            <tr><th><?php esc_html_e('Operation', 'argentwolf-video-processor'); ?></th><td><code><?php echo esc_html($operation['operation_id']); ?></code></td></tr>
            <tr><th><?php esc_html_e('Backend', 'argentwolf-video-processor'); ?></th><td><code><?php echo esc_html($operation['backend_id']); ?></code></td></tr>
            <tr><th><?php esc_html_e('PeerTube origin', 'argentwolf-video-processor'); ?></th><td><code><?php echo esc_html($operation['origin']); ?></code></td></tr>
            <tr><th><?php esc_html_e('Status', 'argentwolf-video-processor'); ?></th><td><?php echo esc_html(self::phase_label($phase)); ?></td></tr>
            <tr><th><?php esc_html_e('Grant attempts', 'argentwolf-video-processor'); ?></th><td><?php echo esc_html((string) $operation['grant_attempt_no']); ?> / <?php echo esc_html((string) PeerTube_Connection_Input::MAX_GRANT_ATTEMPTS); ?></td></tr>
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
            echo '<p>' . esc_html__('Each explicit request advances at most one local persistence boundary.', 'argentwolf-video-processor') . '</p>';
            $this->render_operation_form(
                self::ACTION_RESUME,
                self::NONCE_RESUME . $operation['operation_id'],
                $operation['operation_id'],
                __('Continue local preparation', 'argentwolf-video-processor')
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
            echo '<p>' . esc_html__('Reconciliation is credential-free and performs no PeerTube HTTP request.', 'argentwolf-video-processor') . '</p>';
            $this->render_operation_form(
                self::ACTION_RECONCILE,
                self::NONCE_RECONCILE . $operation['operation_id'],
                $operation['operation_id'],
                __('Check or reconcile status', 'argentwolf-video-processor')
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
                            /* translators: %s: UTC date/time after which verification may be retried. */
                            __('PeerTube requested a bounded delay. A fresh explicit verification is unavailable until %s.', 'argentwolf-video-processor'),
                            gmdate(
                                'Y-m-d H:i:s \U\T\C',
                                $operation['updated_at'] + $operation['retry_after']
                            )
                        )
                    )
                    . '</p></div>';
                return;
            }

            if (PeerTube_Connection_State_Machine::PHASE_VERIFICATION_IN_FLIGHT === $phase) {
                echo '<div class="notice notice-info inline"><p>'
                    . esc_html(
                        sprintf(
                            /* translators: %s: exact configured PeerTube origin. */
                            __('This explicit read sends the stored bearer token only to %s for /users/me. Channel pages are then read publicly without the bearer. No upload or remote mutation occurs.', 'argentwolf-video-processor'),
                            $operation['origin']
                        )
                    )
                    . '</p></div>';
            } else {
                echo '<p>'
                    . esc_html__('The next request records verification intent only. It performs no PeerTube HTTP request; a later explicit request performs the authenticated read.', 'argentwolf-video-processor')
                    . '</p>';
            }

            $this->render_operation_form(
                self::ACTION_VERIFY_IDENTITY,
                self::NONCE_VERIFY_IDENTITY . $operation['operation_id'],
                $operation['operation_id'],
                PeerTube_Connection_State_Machine::PHASE_VERIFICATION_IN_FLIGHT === $phase
                    ? __('Verify identity and owned channels', 'argentwolf-video-processor')
                    : __('Begin identity verification', 'argentwolf-video-processor')
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
                    __('The authenticated identity and selected owned channel are re-verified. Activation changes only local AWVP backend-registry state and performs no PeerTube HTTP request or media upload.', 'argentwolf-video-processor'),
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_PLANNED =>
                    __('An exact disabled-to-active registry mutation is journaled. Each explicit continuation applies or reconciles at most one local persistence boundary; no PeerTube HTTP or media action occurs.', 'argentwolf-video-processor'),
                default =>
                    __('The active descriptor is confirmed. Finalization re-proves the managed credential generation, selected destination, registered adapter, and non-blocking adapter health before closing the operation.', 'argentwolf-video-processor'),
            };
            echo '<div class="notice notice-info inline"><p>' . esc_html($message) . '</p></div>';

            $button = match ($phase) {
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY =>
                    __('Begin backend activation', 'argentwolf-video-processor'),
                PeerTube_Connection_State_Machine::PHASE_ACTIVATION_PLANNED =>
                    __('Continue backend activation', 'argentwolf-video-processor'),
                default => __('Finalize backend activation', 'argentwolf-video-processor'),
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
                    . esc_html__('This operation has exhausted its bounded password-grant attempts. No further credential submission is available.', 'argentwolf-video-processor')
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
                            /* translators: %s: UTC date/time after which another explicit attempt may be made. */
                            __('PeerTube requested a bounded delay. A fresh explicit credential attempt is unavailable until %s.', 'argentwolf-video-processor'),
                            gmdate('Y-m-d H:i:s \U\T\C', $retry_at)
                        )
                    )
                    . '</p></div>';
                return;
            }

            $this->render_grant_form($operation);
            return;
        }

        echo '<p>'
            . esc_html__('This operation is outside the reviewed R40 connection/activation checkpoint. Refresh, revoke, and upload actions are not available in this tranche.', 'argentwolf-video-processor')
            . '</p>';
    }

    /** @param array<string, mixed> $operation */
    private function render_destination_discovery(array $operation, ?array $discovery): void
    {
        echo '<div class="notice notice-info inline"><p>'
            . esc_html(
                sprintf(
                    /* translators: %s: exact configured PeerTube origin. */
                    __('Refreshing destinations sends the stored bearer token only to %s for /users/me, then reads that account’s public channel pages without the bearer. Results are not stored as a channel cache.', 'argentwolf-video-processor'),
                    $operation['origin']
                )
            )
            . '</p></div>';

        if (null === $discovery) {
            $this->render_discovery_form($operation['operation_id'], __('Read current owned destinations', 'argentwolf-video-processor'));
            return;
        }

        if (PeerTube_Identity_Destination_Service::STATUS_NO_DESTINATIONS === $discovery['status']) {
            echo '<div class="notice notice-warning inline"><p>'
                . esc_html__('The authenticated account currently has no eligible local owned channel. No destination was selected.', 'argentwolf-video-processor')
                . '</p></div>';
            $this->render_discovery_form($operation['operation_id'], __('Refresh owned destinations', 'argentwolf-video-processor'));
            return;
        }

        if (PeerTube_Identity_Destination_Service::STATUS_DESTINATIONS_READY !== $discovery['status']) {
            echo '<div class="notice notice-error inline"><p>'
                . esc_html__('Current destination authority could not be confirmed. No destination was selected and the backend remains disabled.', 'argentwolf-video-processor')
                . '</p></div>';
            $this->render_discovery_form($operation['operation_id'], __('Retry destination read', 'argentwolf-video-processor'));
            return;
        }

        echo '<h3>' . esc_html__('Current authenticated PeerTube account', 'argentwolf-video-processor') . '</h3>';
        echo '<p><code>' . esc_html($discovery['identity']['username']) . '</code> / <code>'
            . esc_html($discovery['identity']['account_name']) . '</code></p>';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="max-width:900px">
            <input type="hidden" name="action" value="<?php echo esc_attr(self::ACTION_SELECT_DESTINATION); ?>">
            <input type="hidden" name="operation_id" value="<?php echo esc_attr($operation['operation_id']); ?>">
            <?php wp_nonce_field(self::NONCE_SELECT_DESTINATION . $operation['operation_id'], self::NONCE_FIELD, false); ?>
            <fieldset>
                <legend class="screen-reader-text"><?php esc_html_e('Owned PeerTube destination', 'argentwolf-video-processor'); ?></legend>
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
            <p><?php esc_html_e('Selection performs a fresh authority read before journaling the exact ID. A later explicit verification is still required; this does not activate the backend.', 'argentwolf-video-processor'); ?></p>
            <p><button class="button button-primary" type="submit"><?php esc_html_e('Select and require re-verification', 'argentwolf-video-processor'); ?></button></p>
        </form>
        <?php
        $this->render_discovery_form($operation['operation_id'], __('Refresh owned destinations', 'argentwolf-video-processor'));
    }

    private function render_discovery_form(string $operation_id, string $button_label): void
    {
        ?>
        <form method="get" action="<?php echo esc_url(admin_url('options-general.php')); ?>">
            <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
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
        <h3><?php esc_html_e('Authorize PeerTube credential bootstrap', 'argentwolf-video-processor'); ?></h3>
        <div class="notice notice-info inline"><p>
            <?php
            echo esc_html(
                sprintf(
                    /* translators: %s: exact configured PeerTube origin. */
                    __('PeerTube is an optional external service. AWVP will send the username, password, and optional six-digit OTP entered below only to %s. That service observes ordinary transport metadata, including this server address and the AWVP product/version User-Agent. Its operator terms and privacy policy apply.', 'argentwolf-video-processor'),
                    $operation['origin']
                )
            );
            ?>
        </p><p><?php esc_html_e('Other installed server-side code attached to WordPress HTTP hooks can inspect requests transiently. AWVP does not retain the password, OTP, or instance-local OAuth-client response. Returned access and refresh tokens are stored authenticated-encrypted and non-autoloaded with no plaintext fallback.', 'argentwolf-video-processor'); ?></p><p><?php esc_html_e('Use a dedicated least-privilege PeerTube account. No media, media metadata, or telemetry is sent by this bootstrap. Later explicit steps verify identity and owned channels and select a destination; this checkpoint still does not activate the backend, upload media, refresh tokens, or revoke the remote session.', 'argentwolf-video-processor'); ?></p></div>
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
            <p><label><input type="checkbox" name="authorize_external_service" value="1" required> <?php esc_html_e('I authorize AWVP to send these credentials to the exact PeerTube origin displayed above under that operator’s terms and privacy policy.', 'argentwolf-video-processor'); ?></label></p>
            <?php if ($insecure) : ?><p><label><input type="checkbox" name="authorize_insecure_transport" value="1" required> <?php esc_html_e('I understand this development-only origin uses plaintext HTTP without TLS protection.', 'argentwolf-video-processor'); ?></label></p><?php endif; ?>
            <p><button class="button button-primary" type="submit"><?php esc_html_e('Submit one credential attempt', 'argentwolf-video-processor'); ?></button></p>
        </form>
        <?php
    }

    private function require_post_administrator(): void
    {
        if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? '')) {
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
        $raw = $_POST[self::NONCE_FIELD] ?? null;
        if (! is_string($raw)) {
            $this->reject_invalid_request();
        }

        $unslashed = wp_unslash($raw);
        $nonce = is_string($unslashed) ? sanitize_text_field($unslashed) : '';
        if ('' === $nonce || $nonce !== $unslashed || false === wp_verify_nonce($nonce, $action)) {
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

    private function raw_operation_id(): string
    {
        $value = $_POST['operation_id'] ?? null;
        if (! is_string($value)) {
            return '';
        }
        $value = wp_unslash($value);
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
            'page' => self::PAGE_SLUG,
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
        if (! array_is_list($operations) || count($operations) > self::MAX_OPEN_OPERATIONS) {
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
            || ! array_is_list($result['destinations'])
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
        $value = $_GET['page'] ?? null;
        if (! is_string($value)) {
            return '';
        }
        $value = wp_unslash($value);
        return is_string($value) && self::PAGE_SLUG === $value ? $value : '';
    }

    private function query_notice(): string
    {
        $value = $_GET[self::NOTICE_QUERY] ?? null;
        if (! is_string($value)) {
            return '';
        }
        $value = wp_unslash($value);
        if (! is_string($value)) {
            return '';
        }
        $sanitized = sanitize_key($value);
        return $sanitized === $value ? $value : '';
    }

    private function query_operation_id(): string
    {
        $value = $_GET[self::OPERATION_QUERY] ?? null;
        if (! is_string($value)) {
            return '';
        }
        $value = wp_unslash($value);
        return PeerTube_Connection_Input::operation_id($value);
    }

    private function discovery_requested(string $operation_id): bool
    {
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
            'connection_advanced' => __('The PeerTube connection operation advanced by one reviewed step.', 'argentwolf-video-processor'),
            'ready_for_credentials' => __('Local preparation is complete. Review the disclosure before submitting one credential attempt.', 'argentwolf-video-processor'),
            'otp_required' => __('PeerTube requires a six-digit OTP. Enter the username, password, and OTP again in a fresh explicit request.', 'argentwolf-video-processor'),
            'credentials_required' => __('PeerTube did not accept the prior credential attempt or requested a bounded delay. Review the durable status before trying again.', 'argentwolf-video-processor'),
            'credentials_stored' => __('Authenticated-encrypted token storage is confirmed. Identity, destination, and backend activation remain incomplete.', 'argentwolf-video-processor'),
            'verification_advanced' => __('Identity verification advanced by one explicit step. Review the next read-only PeerTube request before continuing.', 'argentwolf-video-processor'),
            'verification_failed' => __('PeerTube identity or owned-channel authority could not be verified. The backend remains disabled.', 'argentwolf-video-processor'),
            'identity_verified' => __('The authenticated identity and at least one owned local channel were verified. Select a current destination explicitly.', 'argentwolf-video-processor'),
            'destination_verified' => __('The selected owned channel and authenticated identity were re-verified. The backend remains disabled until an explicit local activation request is completed.', 'argentwolf-video-processor'),
            'activation_advanced' => __('Backend activation advanced by one local persistence step. No PeerTube HTTP request or media mutation was performed.', 'argentwolf-video-processor'),
            'backend_activated' => __('The verified PeerTube backend descriptor is active and eligible for the non-mutating R40 adapter surface. Media upload remains unavailable.', 'argentwolf-video-processor'),
            'lifecycle_advanced' => __('The PeerTube credential lifecycle advanced one reviewed step. Continue explicitly if another step remains.', 'argentwolf-video-processor'),
            'token_refreshed' => __('The managed PeerTube token pair was refreshed and stored as a new encrypted generation.', 'argentwolf-video-processor'),
            'backend_disconnected' => __('The PeerTube backend is locally retired and its managed credential has been removed.', 'argentwolf-video-processor'),
            'refresh_rate_limited' => __('PeerTube requested a bounded delay before refresh may continue.', 'argentwolf-video-processor'),
            'reauthentication_required' => __('The PeerTube refresh credential is no longer usable. A new connection authorization is required.', 'argentwolf-video-processor'),
            'lifecycle_indeterminate' => __('A remote token operation has an uncertain outcome. AWVP will not replay that remote mutation automatically.', 'argentwolf-video-processor'),
            'destination_unavailable' => __('That destination is not in the account’s current eligible owned-channel set. No selection was changed.', 'argentwolf-video-processor'),
            'grant_indeterminate' => __('The remote password-grant outcome is uncertain and terminal. AWVP will not retry it automatically.', 'argentwolf-video-processor'),
            'connection_conflict' => __('The operation changed concurrently. Reload its durable status before choosing another explicit action.', 'argentwolf-video-processor'),
            'state_check_required' => __('The local outcome could not be confirmed. Review durable status and use only the explicit action offered for that state.', 'argentwolf-video-processor'),
            'state_may_have_changed' => __('Local state may have changed before the request became uncertain. AWVP did not repeat the action; review durable status.', 'argentwolf-video-processor'),
            'request_refused' => __('The PeerTube connection request was refused without claiming success.', 'argentwolf-video-processor'),
            'invalid_request' => __('The PeerTube connection request contained invalid or unexpected input and was not performed.', 'argentwolf-video-processor'),
            'outside_checkpoint' => __('That operation state is outside this connection checkpoint. No action was performed.', 'argentwolf-video-processor'),
        );
    }

    private static function phase_label(string $phase): string
    {
        return match ($phase) {
            PeerTube_Connection_State_Machine::PHASE_PREPARED => __('Prepared', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVE_PLANNED => __('Secret reservation planned', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_SECRET_RESERVED => __('Secret slot reserved', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_LINK_PLANNED => __('Disabled backend link planned', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_DISABLED => __('Ready for credentials (backend disabled)', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_GRANT_IN_FLIGHT => __('Credential request in flight', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_OTP_RESULT_PENDING => __('OTP result pending confirmation', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_CREDENTIAL_RESULT_PENDING => __('Credential result pending confirmation', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_AWAITING_OTP => __('Awaiting fresh credentials and OTP', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_AWAITING_CREDENTIALS => __('Awaiting fresh credentials', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_GRANT_INDETERMINATE => __('Grant outcome indeterminate (terminal)', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_SECRET_WRITE_PLANNED => __('Encrypted token write pending reconciliation', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_SECRET_STORED => __('Encrypted tokens stored; verification pending', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_VERIFICATION_IN_FLIGHT => __('Identity verification in progress', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_VERIFICATION_FAILED => __('Identity or destination verification failed', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_AWAITING_DESTINATION => __('Awaiting owned destination selection', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_ACTIVATION_READY => __('Identity and destination verified; activation pending', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_ACTIVATION_PLANNED => __('Backend activation planned', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_ACTIVE_PENDING_CLOSE => __('Active backend pending final eligibility check', 'argentwolf-video-processor'),
            PeerTube_Connection_State_Machine::PHASE_COMPLETE => __('PeerTube backend active', 'argentwolf-video-processor'),
            default => __('Outside this checkpoint', 'argentwolf-video-processor'),
        };
    }
}

// EOF: includes/PeerTube_Connection_Admin.php
