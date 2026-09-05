<?php
/** Focused dependency-free tests for R45.4b4 durable PeerTube failure email. */
declare(strict_types=1);

namespace {
    $GLOBALS['awvp_failure_users'] = array();
    $GLOBALS['awvp_failure_posts'] = array();
    $GLOBALS['awvp_failure_mail'] = array();
    $GLOBALS['awvp_failure_mail_accept'] = true;

    function get_userdata(int $user_id): object|false
    {
        return $GLOBALS['awvp_failure_users'][$user_id] ?? false;
    }
    function get_post(int $post_id): object|null
    {
        return $GLOBALS['awvp_failure_posts'][$post_id] ?? null;
    }
    function get_bloginfo(string $show): string
    {
        unset($show);
        return 'AWVP Test Site';
    }
    function admin_url(string $path = ''): string
    {
        return 'https://wp.example.test/wp-admin/' . ltrim($path, '/');
    }
    function is_email(string $email): string|false
    {
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : false;
    }
    function wp_strip_all_tags(string $text, bool $remove_breaks = false): string
    {
        unset($remove_breaks);
        return strip_tags($text);
    }
    function wp_mail(string|array $to, string $subject, string $message, string|array $headers = '', array $attachments = array()): bool
    {
        unset($headers, $attachments);
        $GLOBALS['awvp_failure_mail'][] = array('to'=>$to,'subject'=>$subject,'message'=>$message);
        return (bool) $GLOBALS['awvp_failure_mail_accept'];
    }
}

namespace ArgentVideo {
    final class PeerTube_Connection_Admin
    {
        public const PAGE_SLUG = 'argentwolf-video-processor-peertube';
    }

    final class PeerTube_Staged_Upload_State_Machine
    {
        public static function valid(mixed $operation): bool
        {
            return is_array($operation)
                && isset(
                    $operation['operation_id'],
                    $operation['record_revision'],
                    $operation['video_post_id'],
                    $operation['backend_id'],
                    $operation['origin'],
                    $operation['source']['bytes'],
                    $operation['phase'],
                    $operation['confirmed_bytes'],
                    $operation['last_error'],
                    $operation['created_by']
                );
        }
    }

    final class Task_Repository
    {
        public const STATUS_PROCESSING = 'processing';
        public const APPLIED = 'applied';
        public const PRESENT = 'present';
        public const CONFLICT = 'conflict';
        public const INDETERMINATE = 'indeterminate';
        public const EXHAUSTED = 'exhausted';

        public array $enqueued = array();
        public array $completed = array();
        public array $failed = array();
        public array $rescheduled = array();
        public string $enqueue_status = self::APPLIED;
        public string $complete_status = self::APPLIED;
        public string $fail_status = self::APPLIED;
        public string $reschedule_status = self::APPLIED;
        private int $next_id = 91;

        public function enqueue(
            string $task_type,
            ?int $video_post_id,
            ?int $remote_asset_id,
            ?string $backend_id,
            string $idempotency_key,
            array $payload,
            int $run_after,
            int $now,
            int $priority = 100,
            int $max_attempts = 5
        ): array {
            $this->enqueued[] = compact(
                'task_type','video_post_id','remote_asset_id','backend_id','idempotency_key',
                'payload','run_after','now','priority','max_attempts'
            );
            return array('status'=>$this->enqueue_status,'task_id'=>$this->enqueue_status===self::APPLIED?$this->next_id++:0);
        }

        public function complete(int $task_id, string $lock_token, int $now): string
        {
            $this->completed[] = compact('task_id','lock_token','now');
            return $this->complete_status;
        }

        public function fail(int $task_id, string $lock_token, string $message, int $now): string
        {
            $this->failed[] = compact('task_id','lock_token','message','now');
            return $this->fail_status;
        }

        public function reschedule(int $task_id, string $lock_token, int $run_after, string $message, int $now): string
        {
            $this->rescheduled[] = compact('task_id','lock_token','run_after','message','now');
            return $this->reschedule_status;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/includes/PeerTube_Upload_Failure_Notification.php';

    use ArgentVideo\PeerTube_Upload_Failure_Notification as Notification;
    use ArgentVideo\Task_Repository;

    $assert = static function (bool $ok, string $message): void {
        if (! $ok) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $operation_id = 'upload_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    $operation = array(
        'operation_id'=>$operation_id,
        'record_revision'=>17,
        'video_post_id'=>77,
        'backend_id'=>'peertube-primary',
        'origin'=>'https://video.example.test',
        'source'=>array('bytes'=>1073741824),
        'phase'=>'upload_indeterminate',
        'confirmed_bytes'=>268435456,
        'request_kind'=>'chunk',
        'request_start'=>268435456,
        'request_bytes'=>134217728,
        'last_error'=>array(
            'code'=>'peertube.upload.indeterminate',
            'http_status'=>0,
            'retry_after'=>0,
        ),
        'created_by'=>7,
    );
    $reader = static fn(string $wanted): ?array => $wanted === $operation_id ? $operation : null;

    $GLOBALS['awvp_failure_users'] = array(
        7 => (object) array('user_email'=>'creator@example.com'),
        9 => (object) array('user_email'=>'author@example.com'),
    );
    $GLOBALS['awvp_failure_posts'] = array(
        77 => (object) array('post_author'=>'9','post_title'=>'A <b>Test</b> Video'),
    );

    // Enqueue is deterministic per operation revision and stores only a bounded,
    // credential-free diagnostic snapshot.
    $tasks = new Task_Repository();
    $notification = new Notification($tasks, $reader);
    $queued = $notification->enqueue(
        $operation,
        'Upload service stopped at an explicit intervention boundary.',
        'indeterminate',
        array(
            'status'=>'transport_timeout',
            'http_status'=>0,
            'code'=>'curl_28',
            'detail'=>'The PeerTube request timed out before a definitive response was received; possible causes include a stalled or insufficient-throughput network path.',
            'retry_after'=>0,
        ),
        2000
    );
    $assert(Task_Repository::APPLIED === $queued['status'] && 1 === count($tasks->enqueued), 'Failure notification was not durably enqueued.');
    $row = $tasks->enqueued[0];
    $assert(Notification::TASK_TYPE === $row['task_type'], 'Failure notification task type drifted.');
    $assert(50 === $row['priority'] && 5 === $row['max_attempts'], 'Failure notification priority/attempt policy drifted.');
    $assert(
        hash('sha256', 'awvp-task:v1:' . Notification::TASK_TYPE . ':' . $operation_id . ':17') === $row['idempotency_key'],
        'Failure notification idempotency key was not bound to the operation revision.'
    );
    $serialized = serialize($row['payload']);
    foreach (array('access_token','refresh_token','client_secret','Bearer ','/srv/wordpress','raw response') as $forbidden) {
        $assert(! str_contains($serialized, $forbidden), 'Failure notification snapshot retained forbidden material: ' . $forbidden);
    }

    // Delivery prefers the operation creator and includes useful, sanitized
    // failure details, including timeout/cURL classification and progress.
    $payload_json = json_encode($row['payload'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $claimed = array(
        'id'=>91,
        'task_type'=>Notification::TASK_TYPE,
        'video_post_id'=>77,
        'backend_id'=>'peertube-primary',
        'status'=>Task_Repository::STATUS_PROCESSING,
        'lock_token'=>'00000000-0000-4000-8000-000000000091',
        'attempts'=>1,
        'payload_json'=>$payload_json,
    );
    $GLOBALS['awvp_failure_mail'] = array();
    $GLOBALS['awvp_failure_mail_accept'] = true;
    $delivered = $notification->advance_claimed($claimed, 2010);
    $assert(Notification::STATUS_COMPLETE === $delivered['status'], 'Accepted failure notification was not completed.');
    $assert(1 === count($GLOBALS['awvp_failure_mail']) && 'creator@example.com' === $GLOBALS['awvp_failure_mail'][0]['to'], 'Failure email did not prefer the initiating user.');
    $mail = $GLOBALS['awvp_failure_mail'][0]['message'];
    foreach (array(
        'A Test Video (#77)',
        'Upload operation: ' . $operation_id,
        'peertube-primary (https://video.example.test)',
        'State: upload_indeterminate',
        '268435456 of 1073741824 bytes confirmed',
        'AWVP error code: peertube.upload.indeterminate',
        'Last upload request: chunk at byte 268435456 for 134217728 bytes',
        'Error classification: transport_timeout',
        'Transport/API code: curl_28',
        'The PeerTube request timed out before a definitive response was received; possible causes include a stalled or insufficient-throughput network path.',
        'a smaller AWVP upload segment can reduce timeout/retransmission risk',
        'options-general.php?page=argentwolf-video-processor-peertube',
    ) as $needle) {
        $assert(str_contains($mail, $needle), 'Failure email omitted expected diagnostic: ' . $needle);
    }

    // Missing/unusable initiating email falls back to the current post author.
    $GLOBALS['awvp_failure_users'][7] = (object) array('user_email'=>'not-an-email');
    $GLOBALS['awvp_failure_mail'] = array();
    $fallback = $notification->advance_claimed($claimed, 2020);
    $assert(Notification::STATUS_COMPLETE === $fallback['status'], 'Fallback-recipient delivery did not complete.');
    $assert('author@example.com' === $GLOBALS['awvp_failure_mail'][0]['to'], 'Failure email did not fall back to the post author.');

    // Mailer rejection remains a durable notification retry, not an upload retry.
    $GLOBALS['awvp_failure_users'][7] = (object) array('user_email'=>'creator@example.com');
    $GLOBALS['awvp_failure_mail_accept'] = false;
    $tasks->reschedule_status = Task_Repository::APPLIED;
    $requeued = $notification->advance_claimed($claimed, 2030);
    $assert(Notification::STATUS_REQUEUED === $requeued['status'], 'Rejected mail was not durably requeued.');
    $assert(2330 === $tasks->rescheduled[array_key_last($tasks->rescheduled)]['run_after'], 'First mail retry did not use the five-minute delay.');

    // No creator or post-author recipient fails only the notification task.
    $GLOBALS['awvp_failure_users'] = array();
    $GLOBALS['awvp_failure_posts'][77] = (object) array('post_author'=>'0','post_title'=>'A Test Video');
    $GLOBALS['awvp_failure_mail_accept'] = true;
    $none = $notification->advance_claimed($claimed, 2040);
    $assert(Notification::STATUS_FAILED === $none['status'], 'Missing email recipient did not fail the notification task.');
    $assert(str_contains($tasks->failed[array_key_last($tasks->failed)]['message'], 'No usable WordPress user email'), 'Missing recipient failure reason was not retained.');

    echo "PeerTube upload failure notification tests passed.\n";
}
