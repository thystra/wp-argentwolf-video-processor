<?php
/** Focused dependency-free tests for the R45 detached PeerTube task launcher. */
declare(strict_types=1);

namespace ArgentVideo {
    define('ABSPATH', '/srv/wordpress/');
    $GLOBALS['awvp_r45_launcher_now'] = 5000;
    $GLOBALS['awvp_r45_launcher_transients'] = array();
    $GLOBALS['awvp_r45_launcher_exec_calls'] = array();
    $GLOBALS['awvp_r45_launcher_exec_exit'] = 0;
    $GLOBALS['awvp_r45_launcher_exec_pid'] = 4242;
    $GLOBALS['awvp_r45_launcher_deleted'] = array();

    function time(): int { return (int) $GLOBALS['awvp_r45_launcher_now']; }
    function current_time(string $type, bool $gmt = false): string { unset($type, $gmt); return '1970-01-01 01:23:20'; }
    function untrailingslashit(string $value): string { return rtrim($value, '/\\'); }
    function get_transient(string $key): mixed { return $GLOBALS['awvp_r45_launcher_transients'][$key] ?? false; }
    function set_transient(string $key, mixed $value, int $expiration): bool {
        $GLOBALS['awvp_r45_launcher_transients'][$key] = array('value'=>$value,'expiration'=>$expiration);
        return true;
    }
    function delete_transient(string $key): bool {
        $GLOBALS['awvp_r45_launcher_deleted'][] = $key;
        unset($GLOBALS['awvp_r45_launcher_transients'][$key]);
        return true;
    }
    function exec(string $command, array &$output = null, int &$result_code = null): string|false {
        $GLOBALS['awvp_r45_launcher_exec_calls'][] = $command;
        $output = array((string) $GLOBALS['awvp_r45_launcher_exec_pid']);
        $result_code = (int) $GLOBALS['awvp_r45_launcher_exec_exit'];
        return '';
    }

    final class Settings
    {
        public static array $values = array(
            'wp_cli_path'=>'/usr/local/bin/wp',
            'nice_level'=>10,
            'ionice_class'=>2,
            'ionice_level'=>7,
        );
        public static function get(string $key, mixed $default = null): mixed { return self::$values[$key] ?? $default; }
    }

    final class Shell_Probe
    {
        public static bool $exec_available = true;
        /** @var array<string,bool> */
        public static array $executables = array(
            '/usr/local/bin/wp'=>true,
            '/usr/bin/nohup'=>true,
            '/usr/bin/nice'=>true,
            '/usr/bin/ionice'=>true,
        );
        public static function path_executable(string $path): bool { return self::$executables[$path] ?? false; }
        public static function exec_available(): bool { return self::$exec_available; }
    }

    final class PeerTube_Upload_Task_Coordinator
    {
        public const TASK_UPLOAD_ADVANCE = 'peertube_upload_advance';
        public const TASK_REMOTE_RECONCILE = 'peertube_remote_reconcile';
    }

    final class PeerTube_Task_Worker
    {
        public const STALE_LOCK_SECONDS = 900;
    }

    final class Task_Repository
    {
        public bool $has_work = false;
        /** @var list<array{types:array<int,string>,now:int,stale_before:int}> */
        public array $calls = array();
        public function has_work_of_types(array $task_types, int $now, int $stale_before): bool
        {
            $this->calls[] = array('types'=>$task_types,'now'=>$now,'stale_before'=>$stale_before);
            return $this->has_work;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/includes/PeerTube_Task_Worker_Launcher.php';

    use ArgentVideo\PeerTube_Task_Worker_Launcher;
    use ArgentVideo\Settings;
    use ArgentVideo\Shell_Probe;
    use ArgentVideo\Task_Repository;

    $assert = static function (bool $ok, string $message): void {
        if (! $ok) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };
    $reset = static function (): void {
        $GLOBALS['awvp_r45_launcher_transients'] = array();
        $GLOBALS['awvp_r45_launcher_exec_calls'] = array();
        $GLOBALS['awvp_r45_launcher_exec_exit'] = 0;
        $GLOBALS['awvp_r45_launcher_exec_pid'] = 4242;
        $GLOBALS['awvp_r45_launcher_deleted'] = array();
        Settings::$values['wp_cli_path'] = '/usr/local/bin/wp';
        Shell_Probe::$exec_available = true;
        Shell_Probe::$executables = array(
            '/usr/local/bin/wp'=>true,
            '/usr/bin/nohup'=>true,
            '/usr/bin/nice'=>true,
            '/usr/bin/ionice'=>true,
        );
    };

    // No owned due/stale work means no process and no launch lock.
    $reset();
    $tasks = new Task_Repository();
    $launcher = new PeerTube_Task_Worker_Launcher($tasks);
    $idle = $launcher->launch();
    $assert($idle['ok'] && PeerTube_Task_Worker_Launcher::STATUS_IDLE === $idle['status'], 'Idle launcher result drifted.');
    $assert(0 === count($GLOBALS['awvp_r45_launcher_exec_calls']), 'Idle launcher spawned a process.');
    $assert(1 === count($tasks->calls) && 4100 === $tasks->calls[0]['stale_before'], 'Launcher work probe did not use the reviewed stale-lock boundary.');
    $assert(
        array('peertube_upload_advance','peertube_remote_reconcile') === $tasks->calls[0]['types'],
        'Launcher work probe was not restricted to the two reviewed PeerTube task types.'
    );

    // Existing launch lock suppresses duplicate detached launches.
    $reset();
    $tasks = new Task_Repository();
    $tasks->has_work = true;
    $GLOBALS['awvp_r45_launcher_transients']['argent_video_processor_peertube_task_launch_lock'] = array('value'=>'1','expiration'=>120);
    $launcher = new PeerTube_Task_Worker_Launcher($tasks);
    $locked = $launcher->launch();
    $assert($locked['ok'] && PeerTube_Task_Worker_Launcher::STATUS_LOCKED === $locked['status'], 'Launch lock did not suppress a duplicate launcher.');
    $assert(0 === count($GLOBALS['awvp_r45_launcher_exec_calls']), 'Launch-locked path still spawned a process.');

    // Successful launch uses only the reviewed bounded-drain WP-CLI boundary.
    $reset();
    $tasks = new Task_Repository();
    $tasks->has_work = true;
    $launcher = new PeerTube_Task_Worker_Launcher($tasks);
    $launched = $launcher->launch();
    $assert($launched['ok'] && PeerTube_Task_Worker_Launcher::STATUS_LAUNCHED === $launched['status'] && 4242 === $launched['pid'], 'Detached launch result was not successful.');
    $assert(1 === count($GLOBALS['awvp_r45_launcher_exec_calls']), 'Detached launcher did not execute exactly one shell command.');
    $command = $GLOBALS['awvp_r45_launcher_exec_calls'][0];
    foreach (array(' argent-video peertube-task-worker --drain --quiet', "--path='/srv/wordpress'") as $needle) {
        $assert(str_contains($command, $needle), 'Detached command missed reviewed argument: '.$needle);
    }
    foreach (array("'worker'", '--worker-log-id=', 'ffmpeg', 'curl', 'sleep ') as $needle) {
        $assert(! str_contains($command, $needle), 'Detached command leaked unrelated/loop authority: '.$needle);
    }
    $assert(str_contains($command, '> /dev/null 2>&1 < /dev/null & echo $!'), 'Detached command did not fully detach standard streams and return a PID.');

    // Environmental failures do not spawn and a failed exec releases the short lock.
    $reset();
    $tasks = new Task_Repository();
    $tasks->has_work = true;
    Shell_Probe::$executables['/usr/local/bin/wp'] = false;
    $missing = (new PeerTube_Task_Worker_Launcher($tasks))->launch();
    $assert(! $missing['ok'] && PeerTube_Task_Worker_Launcher::STATUS_FAILED === $missing['status'], 'Missing WP-CLI did not fail closed.');
    $assert(0 === count($GLOBALS['awvp_r45_launcher_exec_calls']), 'Missing WP-CLI path still executed a shell command.');

    $reset();
    $tasks = new Task_Repository();
    $tasks->has_work = true;
    $GLOBALS['awvp_r45_launcher_exec_exit'] = 1;
    $GLOBALS['awvp_r45_launcher_exec_pid'] = 0;
    $failed = (new PeerTube_Task_Worker_Launcher($tasks))->launch();
    $assert(! $failed['ok'] && PeerTube_Task_Worker_Launcher::STATUS_FAILED === $failed['status'], 'Failed detached exec did not fail closed.');
    $assert(1 === count($GLOBALS['awvp_r45_launcher_deleted']), 'Failed detached exec did not release the launch lock.');

    // R45.4a remains unwired. It owns no scheduler/browser/network/persistence
    // mutation beyond its advisory task probe and detached CLI process boundary.
    $root = dirname(__DIR__);
    $source = (string) file_get_contents($root.'/includes/PeerTube_Task_Worker_Launcher.php');
    foreach (array(
        'wp_schedule','wp_cron','add_action(','register_rest_route','wp_ajax','admin_post',
        'PeerTube_Staged_Upload_Service','PeerTube_Remote_Asset_Reconciliation_Service',
        'PeerTube_Api_Client','PeerTube_Http_Client','reconcile_offset(','unlink(','wp_delete'
    ) as $needle) {
        $assert(! str_contains($source, $needle), 'Detached launcher acquired forbidden authority: '.$needle);
    }
    foreach (array('includes/Plugin.php','includes/Admin.php','includes/CLI_Command.php','includes/Worker.php','includes/Worker_Launcher.php') as $relative) {
        $surface = (string) file_get_contents($root.'/'.$relative);
        $assert(! str_contains($surface, 'PeerTube_Task_Worker_Launcher'), 'R45.4a launcher was prematurely wired into '.$relative);
    }

    fwrite(STDOUT, "PeerTube task worker launcher tests passed.\n");
}
