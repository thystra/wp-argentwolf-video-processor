<?php
/** Focused source-boundary tests for the RC9 event-driven PeerTube wake-up. */
declare(strict_types=1);

$assert = static function (bool $ok, string $message): void {
    if (! $ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

$root = dirname(__DIR__);
$plugin = (string) file_get_contents($root . '/includes/Plugin.php');
$activator = (string) file_get_contents($root . '/includes/Activator.php');
$launcher = (string) file_get_contents($root . '/includes/PeerTube_Task_Worker_Launcher.php');
$tasks = (string) file_get_contents($root . '/includes/Task_Repository.php');
$admin = (string) file_get_contents($root . '/includes/Admin.php');
$peertube_admin = (string) file_get_contents($root . '/includes/PeerTube_Connection_Admin.php');

$wp_cli_guard = strpos($plugin, "if (defined('WP_CLI') && WP_CLI)");
$task_repo_build = strpos($plugin, '$peertube_tasks = new Task_Repository();');
$launcher_build = strpos($plugin, '$peertube_task_launcher = new PeerTube_Task_Worker_Launcher($peertube_tasks);');
$event_wiring = strpos($plugin, "add_action('argentwolf_video_processor_task_enqueued', array(\$peertube_task_launcher, 'wake'), 10, 2);");
$recovery_reconcile = strpos($plugin, "add_action(Activator::PEERTUBE_RECOVERY_HOOK, array(\$peertube_incomplete_work, 'recover'), 5);");
$recovery_launch = strpos($plugin, "add_action(Activator::PEERTUBE_RECOVERY_HOOK, array(\$peertube_task_launcher, 'recover'), 10);");

$assert(false !== $wp_cli_guard, 'Plugin lost the WP_CLI execution guard.');
$assert(false !== $task_repo_build && $task_repo_build < $wp_cli_guard, 'RC9 task repository is not available to the event/recovery launcher outside WP_CLI.');
$assert(false !== $launcher_build && $launcher_build < $wp_cli_guard, 'RC9 detached launcher is not composed before WP_CLI-only media services.');
$assert(false !== $event_wiring && $event_wiring < $wp_cli_guard, 'RC9 does not wake the detached worker from durable task enqueue events.');
$assert(false !== $recovery_reconcile && false !== $recovery_launch && $recovery_reconcile < $recovery_launch, 'RC9 one-minute recovery does not reconcile incomplete work before launching the watcher.');
$assert(1 === substr_count($plugin, '$peertube_tasks = new Task_Repository();'), 'RC9 introduced duplicate PeerTube task-repository composition.');
$assert(1 === substr_count($plugin, 'add_action(Activator::CRON_HOOK,'), 'Five-minute cron must remain local-processing-only in RC9.');
$assert(! str_contains($plugin, "add_action(Activator::CRON_HOOK, array(\$peertube_task_launcher"), 'RC9 regressed PeerTube execution to five-minute cron pacing.');

$assert(
    str_contains($activator, "public const PEERTUBE_RECOVERY_HOOK = 'argent_video_processor_peertube_recovery';")
        && str_contains($activator, "wp_schedule_event(time() + 60, 'argent_video_one_minute', self::PEERTUBE_RECOVERY_HOOK);")
        && str_contains($activator, 'wp_clear_scheduled_hook(self::PEERTUBE_RECOVERY_HOOK);')
        && str_contains($plugin, "'argent_video_one_minute'")
        && str_contains($plugin, "'interval' => MINUTE_IN_SECONDS"),
    'RC9 one-minute PeerTube recovery event lifecycle drifted.'
);
$assert(
    str_contains($tasks, "do_action('argentwolf_video_processor_task_enqueued'")
        && substr_count($tasks, 'self::signal_enqueue(') >= 3,
    'Durable enqueue/PRESENT race paths do not emit the RC9 wake signal.'
);

// Media/API services remain constructed strictly inside the detached WP-CLI process.
foreach (array(
    '$peertube_upload = new PeerTube_Staged_Upload_Service(',
    '$peertube_reconciliation = new PeerTube_Remote_Asset_Reconciliation_Service(',
    '$peertube_task_coordinator = new PeerTube_Upload_Task_Coordinator(',
    '$peertube_task_worker = new PeerTube_Task_Worker('
) as $needle) {
    $pos = strpos($plugin, $needle);
    $assert(false !== $pos && $pos > $wp_cli_guard, 'PeerTube media execution escaped the WP_CLI guard: ' . $needle);
}
$assert(
    str_contains($launcher, 'has_work_of_types(self::TASK_TYPES, $now, $stale_before)')
        && str_contains($launcher, "'--drain'")
        && ! str_contains($launcher, 'PeerTube_Api_Client')
        && ! str_contains($launcher, 'PeerTube_Http_Client'),
    'RC9 wake/recovery callback no longer delegates through the due/stale probe and detached drain boundary.'
);
foreach (array($admin, $peertube_admin) as $surface) {
    $assert(! str_contains($surface, 'PeerTube_Task_Worker_Launcher'), 'RC9 added an administrator/browser transfer-launch surface.');
}

fwrite(STDOUT, "PeerTube recurring task wake-up tests passed.\n");
