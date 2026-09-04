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
        if (str_contains($q, "(status = 'queued' AND run_after <= %s AND attempts < max_attempts)")) {
            $now = (string) ($a[1] ?? '');
            $stale_before = (string) ($a[2] ?? '');
            $types = array_values(array_map('strval', array_slice($a, 3)));
            $eligible = array_filter(
                $this->rows,
                static fn(array $row): bool => in_array((string) ($row['task_type'] ?? ''), $types, true)
                    && (
                        ('queued' === ($row['status'] ?? '')
                            && (string) ($row['run_after'] ?? '') <= $now
                            && (int) ($row['attempts'] ?? 0) < (int) ($row['max_attempts'] ?? 0))
                        || ('processing' === ($row['status'] ?? '')
                            && is_string($row['locked_at'] ?? null)
                            && (string) $row['locked_at'] < $stale_before)
                    )
            );
            usort($eligible, static fn(array $left, array $right): int => (int) $left['id'] <=> (int) $right['id']);
            return $eligible[0]['id'] ?? null;
        }
        if (! str_contains($q, "WHERE status = 'queued' AND run_after <= %s")) return null;
        $cutoff = (string) ($a[1] ?? '');
        $types = str_contains($q, 'task_type IN (')
            ? array_values(array_map('strval', array_slice($a, 2)))
            : null;
        $eligible = array_filter(
            $this->rows,
            static fn(array $row): bool => 'queued' === ($row['status'] ?? '')
                && (string) ($row['run_after'] ?? '') <= $cutoff
                && (int) ($row['attempts'] ?? 0) < (int) ($row['max_attempts'] ?? 0)
                && (null === $types || in_array((string) ($row['task_type'] ?? ''), $types, true))
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
        $typed = str_contains($q, 'task_type IN (');
        $limit = (int) ($typed ? ($a[count($a) - 1] ?? 100) : ($a[2] ?? 100));
        $types = $typed
            ? array_values(array_map('strval', array_slice($a, 2, -1)))
            : null;
        $rows = array_values(array_filter(
            $this->rows,
            static fn(array $row): bool => 'processing' === ($row['status'] ?? '')
                && is_string($row['locked_at'] ?? null)
                && (string) $row['locked_at'] < $cutoff
                && (null === $types || in_array((string) ($row['task_type'] ?? ''), $types, true))
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


// Generic tasks must support the same bounded attempt scale as the staged
// upload state machine; a 1 MiB-per-step upload must not be capped at 100 MiB.
$key_limit = hash('sha256', 'awvp-task:v1:test_attempt_limit:1');
$at_limit = $repo->enqueue(
    'test_attempt_limit',
    null,
    null,
    null,
    $key_limit,
    array('version'=>1),
    1050,
    1040,
    100,
    Task_Repository::MAX_ATTEMPTS
);
$assert(
    Task_Repository::APPLIED === $at_limit['status'],
    'Repository rejected its documented maximum attempt count.'
);

$over_limit = $repo->enqueue(
    'test_attempt_limit',
    null,
    null,
    null,
    hash('sha256', 'awvp-task:v1:test_attempt_limit:2'),
    array('version'=>1),
    1050,
    1040,
    100,
    Task_Repository::MAX_ATTEMPTS + 1
);
$assert(
    Task_Repository::CONFLICT === $over_limit['status'],
    'Repository accepted an attempt count above its bounded maximum.'
);

// Worker ownership must be enforced by the repository itself. A PeerTube
// consumer may not claim or stale-recover an unrelated future task type.
$future = $repo->enqueue(
    'future_cleanup',
    null,
    null,
    null,
    hash('sha256', 'awvp-task:v1:future_cleanup:1'),
    array('version'=>1),
    1200,
    1190,
    100,
    5
);
$peer = $repo->enqueue(
    'peertube_upload_advance',
    79,
    null,
    'peertube-primary',
    hash('sha256', 'awvp-task:v1:peertube_upload_advance:typed-claim'),
    array('version'=>1,'operation_id'=>'upload_cccccccccccccccccccccccccccccccc'),
    1200,
    1190,
    100,
    5
);
$assert(
    Task_Repository::APPLIED === $future['status'] && Task_Repository::APPLIED === $peer['status'],
    'Typed-claim fixtures did not enqueue.'
);

$peer_claim = $repo->claim_next_of_types(
    array('peertube_remote_reconcile','peertube_upload_advance'),
    1200
);
$assert(
    is_array($peer_claim)
        && (int) $peer['task_id'] === (int) $peer_claim['id']
        && 'peertube_upload_advance' === ($peer_claim['task_type'] ?? ''),
    'Type-restricted claim selected an unrelated task.'
);
$assert(
    'queued' === ($repo->find((int) $future['task_id'])['status'] ?? ''),
    'Type-restricted claim mutated an unrelated queued task.'
);
$assert(
    Task_Repository::APPLIED === $repo->complete(
        (int) $peer_claim['id'],
        (string) $peer_claim['lock_token'],
        1201
    ),
    'Typed PeerTube claim could not complete.'
);

$future_claim = $repo->claim_next_of_types(array('future_cleanup'), 1201);
$assert(
    is_array($future_claim)
        && (int) $future['task_id'] === (int) $future_claim['id']
        && 'future_cleanup' === ($future_claim['task_type'] ?? ''),
    'Type-owned future worker could not claim its stale-recovery fixture.'
);

$peer_stale = $repo->enqueue(
    'peertube_remote_reconcile',
    79,
    null,
    'peertube-primary',
    hash('sha256', 'awvp-task:v1:peertube_remote_reconcile:typed-recovery'),
    array('version'=>1,'operation_id'=>'upload_cccccccccccccccccccccccccccccccc'),
    1201,
    1190,
    100,
    5
);
$peer_stale_claim = $repo->claim_next_of_types(
    array('peertube_upload_advance','peertube_remote_reconcile'),
    1201
);
$assert(
    is_array($peer_stale_claim) && (int) $peer_stale['task_id'] === (int) $peer_stale_claim['id'],
    'Typed stale-recovery fixture did not claim.'
);

$recovered_owned = $repo->recover_stale_of_types(
    array('peertube_upload_advance','peertube_remote_reconcile'),
    1202,
    1210
);
$assert(1 === $recovered_owned, 'Typed stale recovery did not recover exactly one owned task.');
$assert(
    'processing' === ($repo->find((int) $future['task_id'])['status'] ?? ''),
    'Typed stale recovery stole an unrelated processing task.'
);
$assert(
    'queued' === ($repo->find((int) $peer_stale['task_id'])['status'] ?? ''),
    'Typed stale recovery did not requeue the owned PeerTube task.'
);
$assert(
    null === $repo->claim_next_of_types(array(), 1210)
        && null === $repo->claim_next_of_types(array('NOT VALID'), 1210)
        && 0 === $repo->recover_stale_of_types(array('NOT VALID'), 1200, 1210),
    'Invalid task-type ownership filters did not fail closed.'
);

// Detached-launch probing is advisory but must respect task ownership,
// run_after, attempt exhaustion, and stale-lock recovery boundaries.
$probe_other = $repo->enqueue(
    'probe_other',
    null,
    null,
    null,
    hash('sha256', 'awvp-task:v1:probe_other:1'),
    array('version'=>1),
    1300,
    1290,
    100,
    5
);
$probe_owned = $repo->enqueue(
    'probe_owned',
    null,
    null,
    null,
    hash('sha256', 'awvp-task:v1:probe_owned:1'),
    array('version'=>1),
    1310,
    1290,
    100,
    5
);
$assert(
    Task_Repository::APPLIED === $probe_other['status'] && Task_Repository::APPLIED === $probe_owned['status'],
    'Work-probe fixtures did not enqueue.'
);
$assert(
    ! $repo->has_work_of_types(array('probe_owned'), 1300, 1290),
    'Owned work probe ignored run_after or saw unrelated work.'
);
$assert(
    $repo->has_work_of_types(array('probe_owned'), 1310, 1300),
    'Owned work probe did not see an eligible queued task.'
);
$probe_claim = $repo->claim_next_of_types(array('probe_owned'), 1310);
$assert(is_array($probe_claim), 'Work-probe stale fixture could not be claimed.');
$assert(
    ! $repo->has_work_of_types(array('probe_owned'), 1311, 1310),
    'Fresh processing lock was incorrectly considered stale work.'
);
$assert(
    $repo->has_work_of_types(array('probe_owned'), 1320, 1311),
    'Stale owned processing task was not visible to the work probe.'
);
$assert(
    ! $repo->has_work_of_types(array(), 1320, 1311)
        && ! $repo->has_work_of_types(array('NOT VALID'), 1320, 1311)
        && ! $repo->has_work_of_types(array('probe_owned'), 0, 0)
        && ! $repo->has_work_of_types(array('probe_owned'), 1320, 1321),
    'Invalid work-probe input did not fail closed.'
);

$root = dirname(__DIR__);
foreach (array('includes/Admin.php','includes/CLI_Command.php','includes/Worker.php','includes/Worker_Launcher.php') as $relative) {
    $source = (string) file_get_contents($root . '/' . $relative);
    $assert(! str_contains($source, 'Task_Repository'), "R45 task repository leaked past CLI-only composition into {$relative}.");
}
$plugin = (string) file_get_contents($root . '/includes/Plugin.php');
$assert(
    str_contains($plugin, '$peertube_tasks = new Task_Repository();'),
    'R45.3b CLI-only PeerTube task composition is missing its durable repository.'
);

fwrite(STDOUT, "Task repository tests passed.\n");
