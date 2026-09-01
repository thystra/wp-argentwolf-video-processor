<?php
/**
 * File: includes/PeerTube_Connection_Admin.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use Throwable;

/**
 * Explicit administrator authorization boundary for PeerTube bootstrap.
 *
 * Page rendering is read-only. Every state transition is a distinct
 * authenticated POST which invokes at most one state-changing coordinator or
 * grant method; grant authorization may first read a non-secret projection.
 */
final class PeerTube_Connection_Admin
{
    public const PAGE_SLUG = 'argentwolf-video-processor-peertube';

    public const ACTION_START = 'argentwolf_video_processor_peertube_connection_start';
    public const ACTION_RESUME = 'argentwolf_video_processor_peertube_connection_resume';
    public const ACTION_GRANT = 'argentwolf_video_processor_peertube_connection_grant';
    public const ACTION_RECONCILE = 'argentwolf_video_processor_peertube_connection_reconcile';

    public const NONCE_FIELD = 'argentwolf_video_processor_peertube_nonce';

    private const NONCE_START = 'argentwolf_video_processor_peertube_connection_start';
    private const NONCE_RESUME = 'argentwolf_video_processor_peertube_connection_resume:';
    private const NONCE_GRANT = 'argentwolf_video_processor_peertube_connection_grant:';
    private const NONCE_RECONCILE = 'argentwolf_video_processor_peertube_connection_reconcile:';

    private const NOTICE_QUERY = 'argentwolf_peertube_notice';
    private const OPERATION_QUERY = 'argentwolf_peertube_operation';
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
            'connection_advanced', 'ready_for_credentials', 'credentials_stored' =>
                'notice notice-success',
            'invalid_request', 'request_refused', 'connection_conflict' =>
                'notice notice-error',
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
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('PeerTube Connection — ArgentWolf Video Processor', 'argentwolf-video-processor'); ?></h1>
            <div class="notice notice-warning inline"><p><?php esc_html_e('This unreleased development checkpoint can prepare a disabled PeerTube backend and store login tokens. It does not yet verify identity or channels, select a destination, activate the backend, upload media, refresh tokens, or disconnect the remote session.', 'argentwolf-video-processor'); ?></p></div>

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

            <h2><?php esc_html_e('Open connection operations', 'argentwolf-video-processor'); ?></h2>
            <?php $this->render_operation_list($operations, $selected_id); ?>

            <?php if (null !== $selected) : ?>
                <?php $this->render_selected_operation($selected); ?>
            <?php endif; ?>
        </div>
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
    private function render_selected_operation(array $operation): void
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
                PeerTube_Connection_State_Machine::PHASE_SECRET_STORED,
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
            . esc_html__('This operation is beyond the password-grant checkpoint. Identity, destination, activation, refresh, revoke, and upload actions are not available in this tranche.', 'argentwolf-video-processor')
            . '</p>';
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
        </p><p><?php esc_html_e('Other installed server-side code attached to WordPress HTTP hooks can inspect requests transiently. AWVP does not retain the password, OTP, or instance-local OAuth-client response. Returned access and refresh tokens are stored authenticated-encrypted and non-autoloaded with no plaintext fallback.', 'argentwolf-video-processor'); ?></p><p><?php esc_html_e('Use a dedicated least-privilege PeerTube account. No media, media metadata, or telemetry is sent by this bootstrap. This checkpoint does not verify identity or channels, select a destination, activate the backend, upload media, refresh tokens, or revoke the remote session.', 'argentwolf-video-processor'); ?></p></div>
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

    /** @return array<string, string> */
    private static function notice_messages(): array
    {
        return array(
            'connection_advanced' => __('The PeerTube connection operation advanced by one reviewed step.', 'argentwolf-video-processor'),
            'ready_for_credentials' => __('Local preparation is complete. Review the disclosure before submitting one credential attempt.', 'argentwolf-video-processor'),
            'otp_required' => __('PeerTube requires a six-digit OTP. Enter the username, password, and OTP again in a fresh explicit request.', 'argentwolf-video-processor'),
            'credentials_required' => __('PeerTube did not accept the prior credential attempt or requested a bounded delay. Review the durable status before trying again.', 'argentwolf-video-processor'),
            'credentials_stored' => __('Authenticated-encrypted token storage is confirmed. Identity, destination, and backend activation remain incomplete.', 'argentwolf-video-processor'),
            'grant_indeterminate' => __('The remote password-grant outcome is uncertain and terminal. AWVP will not retry it automatically.', 'argentwolf-video-processor'),
            'connection_conflict' => __('The operation changed concurrently. Reload its durable status before choosing another explicit action.', 'argentwolf-video-processor'),
            'state_check_required' => __('The local outcome could not be confirmed. Use only the available credential-free reconciliation action.', 'argentwolf-video-processor'),
            'state_may_have_changed' => __('Local state may have changed before the request became uncertain. AWVP did not repeat the action; review durable status.', 'argentwolf-video-processor'),
            'request_refused' => __('The PeerTube connection request was refused without claiming success.', 'argentwolf-video-processor'),
            'invalid_request' => __('The PeerTube connection request contained invalid or unexpected input and was not performed.', 'argentwolf-video-processor'),
            'outside_checkpoint' => __('That operation state is outside this password-grant checkpoint. No action was performed.', 'argentwolf-video-processor'),
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
            default => __('Outside this checkpoint', 'argentwolf-video-processor'),
        };
    }
}

// EOF: includes/PeerTube_Connection_Admin.php
