<?php
/** Install a disposable MU-plugin that captures wp_mail() without network I/O. */
declare(strict_types=1);

$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        throw new RuntimeException($message);
    }
};

$assert(defined('WPMU_PLUGIN_DIR') && is_string(WPMU_PLUGIN_DIR), 'R45 notification smoke could not resolve the MU-plugin directory.');
$assert(is_dir(WPMU_PLUGIN_DIR) || wp_mkdir_p(WPMU_PLUGIN_DIR), 'R45 notification smoke could not create the MU-plugin directory.');

$plugin = <<<'PLUGIN'
<?php
/** Disposable R45 smoke mail capture. */
add_filter('pre_wp_mail', static function ($return, array $atts) {
    update_option(
        'awvp_r45_failure_mail_capture',
        array(
            'to' => $atts['to'] ?? null,
            'subject' => $atts['subject'] ?? null,
            'message' => $atts['message'] ?? null,
            'headers' => $atts['headers'] ?? null,
            'attachments' => $atts['attachments'] ?? null,
        ),
        false
    );
    return true;
}, 10, 2);
PLUGIN;

$path = WPMU_PLUGIN_DIR . '/awvp-r45-failure-mail-capture.php';
$written = file_put_contents($path, $plugin, LOCK_EX);
$assert(is_int($written) && $written === strlen($plugin), 'R45 notification smoke could not install its disposable mail capture.');
delete_option('awvp_r45_failure_mail_capture');

echo "PEERTUBE_TASK_CLI_FAILURE_MAIL_CAPTURE_READY=PASS\n";
