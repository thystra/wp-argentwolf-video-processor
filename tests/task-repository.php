<?php
/** Focused tests for the AWVP 2.0 generic asynchronous task repository. */
declare(strict_types=1);

if (! defined('ARRAY_A')) define('ARRAY_A', 'ARRAY_A');

$GLOBALS['awvp_task_uuid_counter'] = 0;
function wp_generate_uuid4(): string
{
    $GLOBALS['awvp_task_uuid_counter']++;
    return sprintf('00000000-0000-4000-8000-%012d', $GLOBALS['awvp_task_uuid_counter']);
}
function wp_json_encode(mixed $value, int $flags = 0, int $depth = 512): string|false
{
    return json_encode($value, $flags, $depth);
}

final class Awvp_R45_Task_Wpdb
{
    public string $prefix = 'wp_';
    public int $insert_id = 0;
    /** @var array<int,array<string,mixed>> */
    public array $rows = array();
    private int $next_id = 1;

    public function prepare(string $query, mixed ...$args): array
    {
        return array('query' => $query, 'args' => $args);
    }

    public function get_row(mixed $prepared, mixed $output = null): ?array
    {
        unset($output);
        if (! is_array($prepared)) return null;
        $q = (string) ($prepared['query'] ?? '');
        $a = $prepared['args'] ?? array();
        if (str_contains($q, 'WHERE id = %d')) {
            $id = (int) ($a[1] ?? 0);
            return $this->rows[$id] ?? null;
        }
        if (str_contains($q, 'WHERE idempotency_key = %s')) {
            $key = (string) ($a[1] ?? '');
            foreach ($this->rows as $row) {
                if (($row['idempotency_key'] ?? '') === $key) return $row;
            }
        }
        return null;
    }

    public function get_var(mixed $prepared): mixed
    {
        if (! is_array($prepared)) return null;
        $q = (string) ($prepared['query'] ?? '');
        $a = $prepared['args'] ?? array();
        if (! str_contains($q, "WHERE status = 'queued' AND run_after <= %s")) return null;
        $cutoff = (string) ($a[1] ?? '');
        $eligible = array_filter(
            $this->rows,
            static fn(array $row): bool => 'queued' === ($row['status'] ?? '')
                && (string) ($row['run_after'] ?? '') <= $cutoff
                && (int) ($row['attempts'] ?? 0) < (int) ($row['max_attempts'] ?? 0)
        );
        usort($eligible, static function (array $a, array $b): int {
            return array($a['run_after'], (int) $a['priority'], (int) $a['id'])
                <=> array($b['run_after'], (int) $b['priority'], (int) $b['id']);
        });
        return $eligible[0]['id'] ?? null;
    }

    /** @return list<array<string,mixed>> */
    public function get_results(mixed $prepared, mixed $output = null): array
    {
        unset($output);
        if (! is_array($prepared)) return array();
        $q = (string) ($prepared['query'] ?? '');
        $a = $prepared['args'] ?? array();
        if (! str_contains($q, "WHERE status = 'processing' AND locked_at < %s")) return array();
        $cutoff = (string) ($a[1] ?? '');
        $limit = (int) ($a[2] ?? 100);
        $rows = array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => 'processing' === ($row['status'] ?? '')
                && is_string($row['locked_at'] ?? null)
                && (string) $row['locked_at'] < $cutoff
        ));
        usort($rows, static fn(array $a, array $b): int => array($a['locked_at'], $a['id']) <=> array($b['locked_at'], $b['id']));
        return array_slice($rows, 0, $limit);
    }

    public function insert(string $table, array $data, array $formats): int|false
    {
        unset($table);
        if (count($data) !== count($formats)) return false;
        foreach ($this->rows as $row) {
            if (($row['idempotency_key'] ?? '') === ($data['idempotency_key'] ?? null)) return false;
        }
        $id = $this->next_id++;
        $this->insert_id = $id;
        $data['id'] = $id;
        $this->rows[$id] = $data;
        return 1;
    }

    public function update(string $table, array $data, array $where, array $formats, array $where_formats): int|false
    {
        unset($table);
        if (count($data) !== count($formats) || count($where) !== count($where_formats)) return false;
        $id = (int) ($where['id'] ?? 0);
        if (! isset($this->rows[$id])) return 0;
        foreach ($where as $key => $value) {
            if (($this->rows[$id][$key] ?? null) !== $value) return 0;
        }
        $changed = false;
        foreach ($data as $key => $value) {
            if (($this->rows[$id][$key] ?? null) !== $value) $changed = true;
            $this->rows[$id][$key] = $value;
        }
        return $changed ? 1 : 0;
    }
}

$GLOBALS['wpdb'] = new Awvp_R45_Task_Wpdb();
require_once dirname(__DIR__) . '/includes/Task_Repository.php';

use ArgentVideo\Task_Repository;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$repo = new Task_Repository();
$operation_a = 'upload_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
$operation_b = 'upload_bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
$key_a = hash('sha256', 'awvp-task:v1:peertube_upload_advance:' . $operation_a);
$key_b = hash('sha256', 'awvp-task:v1:peertube_upload_advance:' . $operation_b);

$first = $repo->enqueue('peertube_upload_advance', 77, null, 'peertube-primary', $key_a, array('version'=>1,'operation_id'=>$operation_a), 1000, 990, 100, 3);
$assert(Task_Repository::APPLIED === $first['status'] && 1 === $first['task_id'], 'Initial task enqueue failed.');
$second = $repo->enqueue('peertube_upload_advance', 77, null, 'peertube-primary', $key_a, array('version'=>1,'operation_id'=>$operation_a), 1000, 991, 100, 3);
$assert(Task_Repository::PRESENT === $second['status'] && 1 === $second['task_id'] && 1 === count($GLOBALS['wpdb']->rows), 'Exact enqueue replay duplicated a task.');
$poison = $repo->enqueue('peertube_upload_advance', 77, null, 'peertube-primary', $key_a, array('version'=>1,'operation_id'=>$operation_b), 1000, 992, 100, 3);
$assert(Task_Repository::CONFLICT === $poison['status'] && 1 === count($GLOBALS['wpdb']->rows), 'Idempotency-key collision changed task intent.');
$secret = $repo->enqueue('peertube_upload_advance', 77, null, 'peertube-primary', hash('sha256','secret'), array('version'=>1,'access_token'=>'forbidden'), 1000, 992);
$assert(Task_Repository::CONFLICT === $secret['status'] && 1 === count($GLOBALS['wpdb']->rows), 'Task repository accepted an obvious credential payload.');

$third = $repo->enqueue('peertube_upload_advance', 78, null, 'peertube-primary', $key_b, array('version'=>1,'operation_id'=>$operation_b), 1000, 993, 50, 2);
$assert(Task_Repository::APPLIED === $third['status'], 'Second task enqueue failed.');
$assert(null === $repo->claim_next(999), 'Task ran before run_after.');
$claimed = $repo->claim_next(1000);
$assert(is_array($claimed) && 2 === (int) $claimed['id'] && 'processing' === $claimed['status'] && 1 === (int) $claimed['attempts'], 'Claim did not honor schedule/priority or increment attempts.');
$token1 = (string) $claimed['lock_token'];
$assert(Task_Repository::APPLIED === $repo->reschedule(2, $token1, 1010, 'bounded wait', 1001), 'Token-bound reschedule failed.');
$other = $repo->claim_next(1009);
$assert(is_array($other) && 1 === (int) $other['id'], 'Rescheduled task ran before its run_after boundary.');
$assert(Task_Repository::APPLIED === $repo->complete(1, (string) $other['lock_token'], 1009), 'Independent queued task could not complete.');
$claimed2 = $repo->claim_next(1010);
$assert(is_array($claimed2) && 2 === (int) $claimed2['id'] && 2 === (int) $claimed2['attempts'], 'Rescheduled task was not reclaimed.');
$token2 = (string) $claimed2['lock_token'];
$assert($token1 !== $token2, 'Reclaim reused a lock token.');
$assert(Task_Repository::CONFLICT === $repo->complete(2, $token1, 1011), 'Stale worker token completed a newly claimed task.');
$assert(Task_Repository::APPLIED === $repo->complete(2, $token2, 1011), 'Current worker could not complete task.');
$assert('complete' === ($repo->find(2)['status'] ?? ''), 'Completion state did not persist.');
$again = $repo->enqueue('peertube_upload_advance', 78, null, 'peertube-primary', $key_b, array('version'=>1,'operation_id'=>$operation_b), 1000, 1012, 50, 2);
$assert(Task_Repository::PRESENT === $again['status'] && 2 === $again['task_id'], 'Completed exact task was not idempotently recognized.');

$key_c = hash('sha256', 'awvp-task:v1:test_stale:1');
$repo->enqueue('test_stale', null, null, null, $key_c, array('version'=>1), 1020, 1015, 100, 2);
$stale = $repo->claim_next(1020);
$assert(is_array($stale) && 3 === (int) $stale['id'], 'Stale-recovery fixture was not claimed.');
$assert(1 === $repo->recover_stale(1021, 1030), 'Stale task was not recovered.');
$recovered = $repo->find(3);
$assert('queued' === ($recovered['status'] ?? '') && null === ($recovered['lock_token'] ?? null) && '1970-01-01 00:17:10' === ($recovered['run_after'] ?? ''), 'Stale recovery did not requeue at the requested UTC time.');
$final_claim = $repo->claim_next(1030);
$assert(is_array($final_claim) && 3 === (int) $final_claim['id'] && 2 === (int) $final_claim['attempts'], 'Recovered task did not consume its second attempt.');
$exhausted = $repo->reschedule(3, (string) $final_claim['lock_token'], 1040, 'retry again', 1031);
$assert(Task_Repository::EXHAUSTED === $exhausted && 'failed' === ($repo->find(3)['status'] ?? ''), 'Attempt exhaustion did not fail closed.');


$root = dirname(__DIR__);
foreach (array('includes/Plugin.php','includes/Admin.php','includes/CLI_Command.php','includes/Worker.php','includes/Worker_Launcher.php') as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    $assert(! str_contains($source, 'Task_Repository'), "R45 checkpoint 1 prematurely wired Task_Repository into {$relative}.");
}

fwrite(STDOUT, "Task repository tests passed.\n");
