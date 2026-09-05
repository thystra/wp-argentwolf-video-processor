<?php
/** Real-WordPress runtime assertion for R45.5 recurring detached wake-up wiring. */
declare(strict_types=1);

use ArgentVideo\Activator;
use ArgentVideo\PeerTube_Task_Worker_Launcher;
use ArgentVideo\Worker_Launcher;

$fail = static function (string $message): void {
    fwrite(STDERR, "R45.5 cron wiring assertion failed: {$message}\n");
    exit(1);
};

$hook = Activator::CRON_HOOK;
if (false === wp_next_scheduled($hook)) {
    $fail('The shared AWVP recurring dispatch event is not scheduled.');
}
if ('argent_video_five_minutes' !== wp_get_schedule($hook)) {
    $fail('The shared AWVP recurring dispatch event is not using the five-minute schedule.');
}

global $wp_filter;
$registered = $wp_filter[$hook] ?? null;
if (! $registered instanceof WP_Hook) {
    $fail('The shared AWVP recurring dispatch hook has no runtime callback registry.');
}

$legacy = 0;
$peertube = 0;
$unexpected_media_callback = 0;
foreach ($registered->callbacks as $priority_callbacks) {
    foreach ($priority_callbacks as $callback) {
        $function = $callback['function'] ?? null;
        if (! is_array($function) || 2 !== count($function) || ! is_object($function[0])) {
            continue;
        }
        $class = get_class($function[0]);
        $method = (string) $function[1];
        if (Worker_Launcher::class === $class && 'dispatch' === $method) {
            ++$legacy;
        }
        if (PeerTube_Task_Worker_Launcher::class === $class && 'launch' === $method) {
            ++$peertube;
        }
        if (in_array($class, array(
            ArgentVideo\PeerTube_Staged_Upload_Service::class,
            ArgentVideo\PeerTube_Remote_Asset_Reconciliation_Service::class,
            ArgentVideo\PeerTube_Upload_Task_Coordinator::class,
            ArgentVideo\PeerTube_Task_Worker::class,
        ), true)) {
            ++$unexpected_media_callback;
        }
    }
}

if (1 !== $legacy || 1 !== $peertube) {
    $fail('Expected exactly one legacy dispatcher and one PeerTube detached launcher callback.');
}
if (0 !== $unexpected_media_callback) {
    $fail('A PeerTube media execution object was registered directly on WP-Cron.');
}

echo "PEERTUBE_TASK_CRON_RUNTIME_WIRING=PASS\n";
