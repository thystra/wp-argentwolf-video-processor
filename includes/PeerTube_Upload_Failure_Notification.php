<?php
/**
 * File: includes/PeerTube_Upload_Failure_Notification.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use Throwable;

/**
 * Durable user-notification boundary for PeerTube upload failures/holds.
 *
 * Enqueue is credential-free and idempotent per operation record revision.
 * Delivery re-derives the recipient and WordPress/post/backend identity from
 * authoritative state. wp_mail() is called only while executing the dedicated
 * notification task, never from the upload HTTP/coordinator boundary.
 */
final class PeerTube_Upload_Failure_Notification
{
    public const TASK_TYPE = 'peertube_upload_failure_notify';
    public const PAYLOAD_VERSION = 1;

    public const STATUS_REQUEUED = 'requeued';
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CONFLICT = 'conflict';
    public const STATUS_INDETERMINATE = 'indeterminate';

    private const PRIORITY = 50;
    private const MAX_ATTEMPTS = 5;
    private const RETRY_DELAYS = array(300, 900, 3600, 10800);
    private const MAX_REASON_BYTES = 512;
    private const MAX_DETAIL_BYTES = 512;

    /** @var Closure(string):array<string,mixed>|null */
    private Closure $operation_reader;

    public function __construct(
        private readonly Task_Repository $tasks,
        callable $operation_reader
    ) {
        $this->operation_reader = Closure::fromCallable($operation_reader);
    }

    /**
     * @param array<string,mixed> $operation
     * @param array<string,mixed> $service_error
     * @return array{status:string,task_id:int}
     */
    public function enqueue(
        array $operation,
        string $reason,
        string $service_status,
        array $service_error,
        int $now
    ): array {
        if (! PeerTube_Staged_Upload_State_Machine::valid($operation) || $now < 1) {
            return array('status' => Task_Repository::CONFLICT, 'task_id' => 0);
        }

        $snapshot = self::failure_snapshot($operation, $reason, $service_status, $service_error, $now);
        if (null === $snapshot) {
            return array('status' => Task_Repository::CONFLICT, 'task_id' => 0);
        }

        $operation_id = (string) $operation['operation_id'];
        $revision = (int) $operation['record_revision'];
        $payload = array(
            'version' => self::PAYLOAD_VERSION,
            'operation_id' => $operation_id,
            'failure_revision' => $revision,
            'failure' => $snapshot,
        );

        return $this->tasks->enqueue(
            self::TASK_TYPE,
            (int) $operation['video_post_id'],
            null,
            (string) $operation['backend_id'],
            hash('sha256', 'awvp-task:v1:' . self::TASK_TYPE . ':' . $operation_id . ':' . $revision),
            $payload,
            $now,
            $now,
            self::PRIORITY,
            self::MAX_ATTEMPTS
        );
    }

    /**
     * @param array<string,mixed> $task
     * @return array{status:string,task_id:int,task_type:string,service_status:string,repository_status:string,run_after:int}
     */
    public function advance_claimed(array $task, int $now): array
    {
        $identity = self::claimed_identity($task);
        if (null === $identity || $now < 1 || self::TASK_TYPE !== $identity['task_type']) {
            return self::result(self::STATUS_CONFLICT);
        }

        $payload = self::payload($task['payload_json'] ?? null);
        if (null === $payload) {
            return $this->fail_task($identity, 'Failure-notification payload validation failed.', $now);
        }

        $operation = $this->read_operation($payload['operation_id']);
        if (null === $operation
            || (int) ($task['video_post_id'] ?? 0) !== (int) $operation['video_post_id']
            || ! is_string($task['backend_id'] ?? null)
            || $task['backend_id'] !== $operation['backend_id']) {
            return $this->fail_task($identity, 'Failure-notification task no longer matches its upload operation.', $now);
        }

        $recipient = self::recipient($operation);
        if ('' === $recipient) {
            return $this->fail_task($identity, 'No usable WordPress user email exists for the failed upload.', $now);
        }

        $post = function_exists('get_post') ? get_post((int) $operation['video_post_id']) : null;
        $title = is_object($post) && is_string($post->post_title ?? null)
            ? self::safe_text($post->post_title, 200)
            : '';
        if ('' === $title) {
            $title = 'Video #' . (int) $operation['video_post_id'];
        }
        $site = function_exists('get_bloginfo') ? self::safe_text((string) get_bloginfo('name'), 200) : '';
        if ('' === $site) {
            $site = 'WordPress';
        }

        $failure = $payload['failure'];
        $subject = sprintf('[%s] PeerTube upload requires attention: %s', $site, $title);
        $body = self::message($site, $title, $operation, $failure, $now);

        try {
            $accepted = function_exists('wp_mail') && true === wp_mail($recipient, $subject, $body);
        } catch (Throwable) {
            $accepted = false;
        }

        if ($accepted) {
            $repository_status = $this->tasks->complete($identity['task_id'], $identity['lock_token'], $now);
            return self::transition_result(
                $repository_status,
                self::STATUS_COMPLETE,
                $identity['task_id'],
                0
            );
        }

        $attempts = self::positive_int($task['attempts'] ?? null);
        $delay_index = max(0, min(count(self::RETRY_DELAYS) - 1, $attempts - 1));
        $delay = self::RETRY_DELAYS[$delay_index];
        $run_after = $now <= PHP_INT_MAX - $delay ? $now + $delay : $now;
        $repository_status = $this->tasks->reschedule(
            $identity['task_id'],
            $identity['lock_token'],
            $run_after,
            'wp_mail() did not accept the PeerTube failure notification; retrying delivery later.',
            $now
        );
        $status = Task_Repository::EXHAUSTED === $repository_status
            ? self::STATUS_FAILED
            : self::STATUS_REQUEUED;
        return self::transition_result($repository_status, $status, $identity['task_id'], $run_after);
    }

    /** @param array<string,mixed> $operation @return array<string,mixed>|null */
    private function read_operation(string $operation_id): ?array
    {
        if (1 !== preg_match('/\Aupload_[a-f0-9]{32}\z/D', $operation_id)) {
            return null;
        }
        try {
            $operation = ($this->operation_reader)($operation_id);
        } catch (Throwable) {
            return null;
        }
        return is_array($operation)
            && PeerTube_Staged_Upload_State_Machine::valid($operation)
            && hash_equals($operation_id, (string) ($operation['operation_id'] ?? ''))
            ? $operation
            : null;
    }

    /** @param array{task_id:int,task_type:string,lock_token:string} $identity */
    private function fail_task(array $identity, string $message, int $now): array
    {
        $repository_status = $this->tasks->fail($identity['task_id'], $identity['lock_token'], $message, $now);
        return self::transition_result(
            $repository_status,
            self::STATUS_FAILED,
            $identity['task_id'],
            0
        );
    }

    /** @param array<string,mixed> $operation */
    private static function recipient(array $operation): string
    {
        $user_ids = array();
        if (is_int($operation['created_by'] ?? null) && $operation['created_by'] > 0) {
            $user_ids[] = $operation['created_by'];
        }

        $post = function_exists('get_post') ? get_post((int) $operation['video_post_id']) : null;
        if (is_object($post)) {
            $author = self::positive_int($post->post_author ?? null);
            if ($author > 0 && ! in_array($author, $user_ids, true)) {
                $user_ids[] = $author;
            }
        }

        foreach ($user_ids as $user_id) {
            $user = function_exists('get_userdata') ? get_userdata($user_id) : false;
            $email = is_object($user) && is_string($user->user_email ?? null)
                ? trim($user->user_email)
                : '';
            if ('' !== $email && function_exists('is_email') && false !== is_email($email)) {
                return $email;
            }
        }

        return '';
    }

    /** @param array<string,mixed> $operation @param array<string,mixed> $failure */
    private static function message(string $site, string $title, array $operation, array $failure, int $now): string
    {
        $lines = array(
            'ArgentWolf Video Processor (AWVP) detected a PeerTube upload that requires attention.',
            '',
            'Site: ' . $site,
            'Video: ' . $title . ' (#' . (int) $operation['video_post_id'] . ')',
            'Upload operation: ' . (string) $operation['operation_id'],
            'PeerTube backend: ' . (string) $operation['backend_id'] . ' (' . (string) $operation['origin'] . ')',
            'State: ' . (string) $failure['state'],
            'Failure time: ' . Operator_Time::format((int) $failure['failed_at'], true),
            'Progress: ' . (int) $failure['confirmed_bytes'] . ' of ' . (int) $failure['source_bytes'] . ' bytes confirmed',
            'AWVP error code: ' . ('' !== $failure['awvp_error_code'] ? $failure['awvp_error_code'] : '(none)'),
            'HTTP status: ' . ((int) $failure['http_status'] > 0 ? (string) $failure['http_status'] : '(none)'),
            'Retry-after: ' . ((int) $failure['retry_after'] > 0 ? $failure['retry_after'] . ' seconds' : '(none)'),
            'Service status: ' . ('' !== $failure['service_status'] ? $failure['service_status'] : '(none)'),
        );

        if ((int) $failure['request_bytes'] > 0) {
            $lines[] = sprintf(
                'Last upload request: %s at byte %d for %d bytes',
                '' !== $failure['request_kind'] ? $failure['request_kind'] : 'request',
                (int) $failure['request_start'],
                (int) $failure['request_bytes']
            );
        }
        if ('' !== $failure['error_status']) {
            $lines[] = 'Error classification: ' . $failure['error_status'];
        }
        if ('' !== $failure['service_error_code']) {
            $lines[] = 'Transport/API code: ' . $failure['service_error_code'];
        }
        if ('' !== $failure['detail']) {
            $lines[] = 'Detail: ' . $failure['detail'];
        }
        if ('' !== $failure['reason']) {
            $lines[] = 'Worker reason: ' . $failure['reason'];
        }
        if ('transport_timeout' === $failure['error_status']) {
            $lines[] = 'Suggestion: after the upload state is safely reconciled, a smaller AWVP upload segment can reduce timeout/retransmission risk on a slow or unreliable remote link.';
        }

        $admin = function_exists('admin_url')
            ? admin_url('options-general.php?page=' . PeerTube_Connection_Admin::PAGE_SLUG)
            : '';
        if (is_string($admin) && '' !== $admin) {
            $lines[] = '';
            $lines[] = 'AWVP PeerTube settings: ' . $admin;
        }
        $lines[] = '';
        $lines[] = 'This message contains only sanitized diagnostic information; credentials and raw remote responses are excluded.';
        unset($now);

        return implode("\n", $lines);
    }

    /**
     * @param array<string,mixed> $operation
     * @param array<string,mixed> $service_error
     * @return array<string,mixed>|null
     */
    private static function failure_snapshot(
        array $operation,
        string $reason,
        string $service_status,
        array $service_error,
        int $now
    ): ?array {
        $last_error = is_array($operation['last_error'] ?? null) ? $operation['last_error'] : array();
        $error_code = self::safe_token($last_error['code'] ?? '', 191);
        $http_status = is_int($last_error['http_status'] ?? null) ? $last_error['http_status'] : 0;
        $retry_after = is_int($last_error['retry_after'] ?? null) ? $last_error['retry_after'] : 0;

        $service_http = is_int($service_error['http_status'] ?? null) ? $service_error['http_status'] : 0;
        if (0 === $http_status && $service_http >= 100 && $service_http <= 599) {
            $http_status = $service_http;
        }
        $service_retry = is_int($service_error['retry_after'] ?? null) ? $service_error['retry_after'] : 0;
        if (0 === $retry_after && $service_retry > 0 && $service_retry <= 86400) {
            $retry_after = $service_retry;
        }

        $snapshot = array(
            'state' => self::safe_token($operation['phase'] ?? '', 64),
            'failed_at' => $now,
            'confirmed_bytes' => is_int($operation['confirmed_bytes'] ?? null) ? max(0, $operation['confirmed_bytes']) : 0,
            'source_bytes' => is_int($operation['source']['bytes'] ?? null) ? max(0, $operation['source']['bytes']) : 0,
            'request_kind' => self::safe_token($operation['request_kind'] ?? '', 32),
            'request_start' => is_int($operation['request_start'] ?? null) ? max(0, $operation['request_start']) : 0,
            'request_bytes' => is_int($operation['request_bytes'] ?? null) ? max(0, $operation['request_bytes']) : 0,
            'awvp_error_code' => $error_code,
            'http_status' => max(0, min(599, $http_status)),
            'retry_after' => max(0, min(86400, $retry_after)),
            'service_status' => self::safe_token($service_status, 64),
            'error_status' => self::safe_token($service_error['status'] ?? '', 64),
            'service_error_code' => self::safe_token($service_error['code'] ?? '', 191),
            'detail' => self::safe_text($service_error['detail'] ?? '', self::MAX_DETAIL_BYTES),
            'reason' => self::safe_text($reason, self::MAX_REASON_BYTES),
        );

        return '' !== $snapshot['state'] && $snapshot['source_bytes'] > 0 ? $snapshot : null;
    }

    /** @param mixed $payload_json @return array<string,mixed>|null */
    private static function payload(mixed $payload_json): ?array
    {
        if (! is_string($payload_json) || '' === $payload_json || strlen($payload_json) > 16384) {
            return null;
        }
        try {
            $payload = json_decode($payload_json, true, 8, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($payload)
            || array('version','operation_id','failure_revision','failure') !== array_keys($payload)
            || self::PAYLOAD_VERSION !== ($payload['version'] ?? null)
            || ! is_string($payload['operation_id'] ?? null)
            || 1 !== preg_match('/\Aupload_[a-f0-9]{32}\z/D', $payload['operation_id'])
            || ! is_int($payload['failure_revision'] ?? null) || $payload['failure_revision'] < 1
            || ! self::valid_snapshot($payload['failure'] ?? null)) {
            return null;
        }
        return $payload;
    }

    /** @param mixed $snapshot */
    private static function valid_snapshot(mixed $snapshot): bool
    {
        if (! is_array($snapshot)
            || array('state','failed_at','confirmed_bytes','source_bytes','request_kind','request_start','request_bytes','awvp_error_code','http_status','retry_after','service_status','error_status','service_error_code','detail','reason') !== array_keys($snapshot)) {
            return false;
        }
        return is_string($snapshot['state']) && '' !== self::safe_token($snapshot['state'], 64)
            && is_int($snapshot['failed_at']) && $snapshot['failed_at'] > 0
            && is_int($snapshot['confirmed_bytes']) && $snapshot['confirmed_bytes'] >= 0
            && is_int($snapshot['source_bytes']) && $snapshot['source_bytes'] > 0
            && $snapshot['confirmed_bytes'] <= $snapshot['source_bytes']
            && is_string($snapshot['request_kind']) && self::safe_token($snapshot['request_kind'], 32) === $snapshot['request_kind']
            && is_int($snapshot['request_start']) && $snapshot['request_start'] >= 0 && $snapshot['request_start'] <= $snapshot['source_bytes']
            && is_int($snapshot['request_bytes']) && $snapshot['request_bytes'] >= 0 && $snapshot['request_bytes'] <= $snapshot['source_bytes']
            && $snapshot['request_start'] <= $snapshot['source_bytes'] - $snapshot['request_bytes']
            && is_string($snapshot['awvp_error_code']) && self::safe_token($snapshot['awvp_error_code'], 191) === $snapshot['awvp_error_code']
            && is_int($snapshot['http_status']) && $snapshot['http_status'] >= 0 && $snapshot['http_status'] <= 599
            && is_int($snapshot['retry_after']) && $snapshot['retry_after'] >= 0 && $snapshot['retry_after'] <= 86400
            && is_string($snapshot['service_status']) && self::safe_token($snapshot['service_status'], 64) === $snapshot['service_status']
            && is_string($snapshot['error_status']) && self::safe_token($snapshot['error_status'], 64) === $snapshot['error_status']
            && is_string($snapshot['service_error_code']) && self::safe_token($snapshot['service_error_code'], 191) === $snapshot['service_error_code']
            && is_string($snapshot['detail']) && self::safe_text($snapshot['detail'], self::MAX_DETAIL_BYTES) === $snapshot['detail']
            && is_string($snapshot['reason']) && self::safe_text($snapshot['reason'], self::MAX_REASON_BYTES) === $snapshot['reason'];
    }

    /** @param array<string,mixed> $task @return array{task_id:int,task_type:string,lock_token:string}|null */
    private static function claimed_identity(array $task): ?array
    {
        $task_id = self::positive_int($task['id'] ?? null);
        $task_type = is_string($task['task_type'] ?? null) ? $task['task_type'] : '';
        $lock_token = is_string($task['lock_token'] ?? null) ? $task['lock_token'] : '';
        if ($task_id < 1
            || Task_Repository::STATUS_PROCESSING !== ($task['status'] ?? null)
            || 1 !== preg_match('/\A[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/D', $lock_token)) {
            return null;
        }
        return array('task_id'=>$task_id,'task_type'=>$task_type,'lock_token'=>$lock_token);
    }

    private static function safe_token(mixed $value, int $max): string
    {
        if (! is_string($value) || strlen($value) > $max || ('' !== $value && 1 !== preg_match('/^[A-Za-z0-9_.:-]+$/D', $value))) {
            return '';
        }
        $folded = preg_replace('/[^a-z0-9]+/i', '', strtolower($value)) ?? '';
        foreach (array('accesstoken','refreshtoken','clientsecret','password','authorization','bearer','otp') as $marker) {
            if (str_contains($folded, $marker)) {
                return '';
            }
        }
        return $value;
    }

    private static function safe_text(mixed $value, int $max): string
    {
        if (! is_string($value) || '' === $value || 1 !== preg_match('//u', $value)) {
            return '';
        }
        if (function_exists('wp_strip_all_tags')) {
            $value = wp_strip_all_tags($value, true);
        } else {
            $value = wp_strip_all_tags($value);
        }
        $value = preg_replace('/(?:[\x00-\x1F\x7F]|\p{Cf})/u', ' ', $value) ?? '';
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? '';
        $folded = preg_replace('/[^a-z0-9]+/i', '', strtolower($value)) ?? '';
        foreach (array('accesstoken','refreshtoken','clientsecret','password','authorization','bearer','otp') as $marker) {
            if (str_contains($folded, $marker)) {
                return '';
            }
        }
        if (strlen($value) > $max) {
            $value = substr($value, 0, $max);
            while ('' !== $value && 1 !== preg_match('//u', $value)) {
                $value = substr($value, 0, -1);
            }
        }
        return $value;
    }

    private static function positive_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (! is_string($value) || 1 !== preg_match('/\A[1-9][0-9]*\z/D', $value)) {
            return 0;
        }
        $int = (int) $value;
        return $int > 0 && (string) $int === $value ? $int : 0;
    }

    /** @return array{status:string,task_id:int,task_type:string,service_status:string,repository_status:string,run_after:int} */
    private static function transition_result(string $repository_status, string $success, int $task_id, int $run_after): array
    {
        $status = match ($repository_status) {
            Task_Repository::APPLIED => $success,
            Task_Repository::EXHAUSTED => self::STATUS_FAILED,
            Task_Repository::CONFLICT => self::STATUS_CONFLICT,
            default => self::STATUS_INDETERMINATE,
        };
        return self::result($status, $task_id, $repository_status, $run_after);
    }

    /** @return array{status:string,task_id:int,task_type:string,service_status:string,repository_status:string,run_after:int} */
    private static function result(string $status, int $task_id = 0, string $repository_status = '', int $run_after = 0): array
    {
        return array(
            'status'=>$status,
            'task_id'=>max(0, $task_id),
            'task_type'=>self::TASK_TYPE,
            'service_status'=>'notification',
            'repository_status'=>$repository_status,
            'run_after'=>max(0, $run_after),
        );
    }
}

// EOF: includes/PeerTube_Upload_Failure_Notification.php
