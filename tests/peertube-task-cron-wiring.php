<?php
/** Focused source-boundary tests for the R45.5 recurring PeerTube wake-up. */
declare(strict_types=1);

$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$root = dirname(__DIR__);
$plugin = (string) file_get_contents($root . '/includes/Plugin.php');
$activator = (string) file_get_contents($root . '/includes/Activator.php');
$launcher = (string) file_get_contents($root . '/includes/PeerTube_Task_Worker_Launcher.php');
$admin = (string) file_get_contents($root . '/includes/Admin.php');
$peertube_admin = (string) file_get_contents($root . '/includes/PeerTube_Connection_Admin.php');

$wp_cli_guard = strpos($plugin, "if (defined('WP_CLI') && WP_CLI)");
$task_repo_build = strpos($plugin, '$peertube_tasks = new Task_Repository();');
$launcher_build = strpos($plugin, '$peertube_task_launcher = new PeerTube_Task_Worker_Launcher($peertube_tasks);');
$cron_wiring = strpos($plugin, "add_action(Activator::CRON_HOOK, array(\$peertube_task_launcher, 'launch'));");

$assert(false !== $wp_cli_guard, 'Plugin lost the WP_CLI execution guard.');
$assert(
    false !== $task_repo_build && $task_repo_build < $wp_cli_guard,
    'R45.5 task repository is not available to the recurring launcher outside WP_CLI.'
);
$assert(
    false !== $launcher_build && $launcher_build < $wp_cli_guard,
    'R45.5 detached launcher is not composed before the WP_CLI-only media services.'
);
$assert(
    false !== $cron_wiring && $cron_wiring < $wp_cli_guard,
    'R45.5 does not register the reviewed launcher on the existing recurring dispatch hook.'
);
$assert(
    1 === substr_count($plugin, '$peertube_tasks = new Task_Repository();'),
    'R45.5 introduced duplicate PeerTube task-repository composition.'
);
$assert(
    2 === substr_count($plugin, 'add_action(Activator::CRON_HOOK,'),
    'The shared recurring dispatch hook should own exactly the legacy and PeerTube detached launch callbacks.'
);

// Reuse the already-established five-minute recurring event. R45.5 must not
// create a second PeerTube-specific scheduler or change activation/deactivation
// ownership of the existing event.
$assert(
    str_contains($activator, "public const CRON_HOOK = 'argent_video_processor_dispatch';")
        && str_contains($activator, "wp_schedule_event(time() + 60, 'argent_video_five_minutes', self::CRON_HOOK);")
        && str_contains($activator, 'wp_clear_scheduled_hook(self::CRON_HOOK);'),
    'R45.5 recurring wake-up no longer reuses the established dispatch event lifecycle.'
);
$assert(
    ! str_contains($plugin, 'wp_schedule_event(')
        && ! str_contains($plugin, 'wp_schedule_single_event(')
        && ! str_contains($plugin, 'peertube_task_dispatch'),
    'R45.5 introduced a second scheduler instead of reusing the existing recurring event.'
);

// The cron callback is launcher-only. Media/API services remain constructed
// strictly inside the detached WP-CLI process after the WP_CLI guard.
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
    'R45.5 cron callback no longer delegates through the due/stale probe and detached drain boundary.'
);
foreach (array($admin, $peertube_admin) as $surface) {
    $assert(
        ! str_contains($surface, 'PeerTube_Task_Worker_Launcher'),
        'R45.5 added an administrator/browser transfer-launch surface.'
    );
}

fwrite(STDOUT, "PeerTube recurring task wake-up tests passed.\n");
