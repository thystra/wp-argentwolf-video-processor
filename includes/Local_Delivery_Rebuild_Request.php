<?php
/** File: includes/Local_Delivery_Rebuild_Request.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Durable authorization for rebuilding local AWVP delivery from the retained source. */
final class Local_Delivery_Rebuild_Request
{
    public const VERSION = 1;
    public const PREPARED = 'prepared';
    public const QUEUED = 'queued';
    public const PROCESSING = 'processing';
    public const COMPLETE = 'complete';
    public const FAILED = 'failed';

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value) || array(
            'version','request_id','video_id','attachment_id','source_identity','source_signature','profile','job_id','status','requested_by','requested_at','updated_at','completed_at','error_message'
        ) !== array_keys($value)) {
            return array();
        }
        $request_id = is_string($value['request_id'] ?? null) && 1 === preg_match('/^[a-f0-9]{32}$/D', $value['request_id']) ? $value['request_id'] : '';
        $video_id = Video_Meta::sanitize_positive_id($value['video_id'] ?? 0);
        $attachment_id = Video_Meta::sanitize_positive_id($value['attachment_id'] ?? 0);
        $source = WordPress_Source_File::sanitize_identity($value['source_identity'] ?? null);
        $signature = is_string($value['source_signature'] ?? null) && 1 === preg_match('/^[a-f0-9]{64}$/D', $value['source_signature']) ? $value['source_signature'] : '';
        $profile = is_string($value['profile'] ?? null) && 1 === preg_match('/^[a-z0-9+_-]{1,64}$/D', $value['profile']) ? $value['profile'] : '';
        $job_id = is_int($value['job_id'] ?? null) ? max(0, $value['job_id']) : 0;
        $status = is_string($value['status'] ?? null) ? $value['status'] : '';
        $requested_by = Video_Meta::sanitize_positive_id($value['requested_by'] ?? 0);
        $requested_at = is_int($value['requested_at'] ?? null) ? $value['requested_at'] : 0;
        $updated_at = is_int($value['updated_at'] ?? null) ? $value['updated_at'] : 0;
        $completed_at = is_int($value['completed_at'] ?? null) ? max(0, $value['completed_at']) : 0;
        $error = self::text($value['error_message'] ?? '', 1000);
        $active = array(self::PREPARED,self::QUEUED,self::PROCESSING);
        if (self::VERSION !== ($value['version'] ?? null) || '' === $request_id || $video_id < 1 || $attachment_id < 1
            || array() === $source || '' === $signature || '' === $profile || $requested_by < 1
            || $requested_at < 1 || $updated_at < $requested_at || ! in_array($status, array(self::PREPARED,self::QUEUED,self::PROCESSING,self::COMPLETE,self::FAILED), true)
            || (self::PREPARED === $status && 0 !== $job_id)
            || (in_array($status, array(self::QUEUED,self::PROCESSING,self::COMPLETE), true) && $job_id < 1)
            || (self::COMPLETE === $status && $completed_at < $updated_at)
            || (self::FAILED === $status && ('' === $error || $completed_at < $updated_at))
            || (in_array($status, $active, true) && ($completed_at !== 0 || '' !== $error))) {
            return array();
        }
        return array(
            'version'=>self::VERSION,'request_id'=>$request_id,'video_id'=>$video_id,'attachment_id'=>$attachment_id,
            'source_identity'=>$source,'source_signature'=>$signature,'profile'=>$profile,'job_id'=>$job_id,'status'=>$status,
            'requested_by'=>$requested_by,'requested_at'=>$requested_at,'updated_at'=>$updated_at,'completed_at'=>$completed_at,'error_message'=>$error,
        );
    }

    private static function text(mixed $value, int $max): string
    {
        if (! is_string($value)) return '';
        $value = trim(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $value) ?? '');
        return function_exists('mb_substr') ? mb_substr($value, 0, $max) : substr($value, 0, $max);
    }
}
// EOF
