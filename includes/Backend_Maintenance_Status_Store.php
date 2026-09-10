<?php
/**
 * File: includes/Backend_Maintenance_Status_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Bounded non-secret status for periodic backend maintenance. */
final class Backend_Maintenance_Status_Store
{
    public const OPTION = 'argent_video_processor_backend_maintenance_status';
    public const VERSION = 1;
    public const HEALTHY = 'healthy';
    public const WARNING = 'warning';
    public const ERROR = 'error';
    private const MAX_BACKENDS = 64;
    private const MAX_MESSAGE_BYTES = 512;

    /** @return array<string,array{status:string,code:string,message:string,checked_at:int}> */
    public function all(): array
    {
        $raw = get_option(self::OPTION, array());
        if (! is_array($raw) || self::VERSION !== ($raw['version'] ?? null) || ! is_array($raw['backends'] ?? null)) {
            return array();
        }
        $out = array();
        foreach ($raw['backends'] as $backend_id => $record) {
            $backend_id = Backend_Identity::sanitize($backend_id);
            $record = self::sanitize_record($record);
            if ('' !== $backend_id && null !== $record) {
                $out[$backend_id] = $record;
            }
            if (count($out) >= self::MAX_BACKENDS) {
                break;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @return array{status:string,code:string,message:string,checked_at:int}|null */
    public function get(string $backend_id): ?array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        $all = $this->all();
        return '' !== $backend_id ? ($all[$backend_id] ?? null) : null;
    }

    public function record(string $backend_id, string $status, string $code, string $message, int $now): bool
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        $record = self::sanitize_record(array(
            'status' => $status,
            'code' => $code,
            'message' => $message,
            'checked_at' => $now,
        ));
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id || null === $record) {
            return false;
        }
        $all = $this->all();
        $all[$backend_id] = $record;
        if (count($all) > self::MAX_BACKENDS) {
            uasort($all, static fn (array $a, array $b): int => $b['checked_at'] <=> $a['checked_at']);
            $all = array_slice($all, 0, self::MAX_BACKENDS, true);
        }
        ksort($all, SORT_STRING);
        $desired = array('version' => self::VERSION, 'backends' => $all);
        update_option(self::OPTION, $desired, false);
        return $this->all() === $all;
    }

    /** @return array{status:string,code:string,message:string,checked_at:int}|null */
    private static function sanitize_record(mixed $record): ?array
    {
        if (! is_array($record)) {
            return null;
        }
        $status = is_string($record['status'] ?? null) ? $record['status'] : '';
        $code = is_string($record['code'] ?? null) ? $record['code'] : '';
        $message = is_string($record['message'] ?? null) ? trim($record['message']) : '';
        $checked_at = is_int($record['checked_at'] ?? null) ? $record['checked_at'] : 0;
        if (! in_array($status, array(self::HEALTHY, self::WARNING, self::ERROR), true)
            || $checked_at < 1
            || 1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D', $code)
            || '' === $message
            || strlen($message) > self::MAX_MESSAGE_BYTES
            || 1 === preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $message)) {
            return null;
        }
        return array('status'=>$status,'code'=>$code,'message'=>$message,'checked_at'=>$checked_at);
    }
}

// EOF
