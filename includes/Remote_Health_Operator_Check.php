<?php
/** File: includes/Remote_Health_Operator_Check.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Durable evidence for one explicit operator-requested remote serving check. */
final class Remote_Health_Operator_Check
{
    public const VERSION = 1;

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value) || array(
            'version','remote_asset_id','backend_id','checked_by','checked_at','viability_status','reason_code','http_status','restored_by','restored_at'
        ) !== array_keys($value)) {
            return array();
        }
        $remote_asset_id = Video_Meta::sanitize_positive_id($value['remote_asset_id'] ?? 0);
        $backend_id = Backend_Identity::sanitize($value['backend_id'] ?? '');
        $checked_by = Video_Meta::sanitize_positive_id($value['checked_by'] ?? 0);
        $checked_at = is_int($value['checked_at'] ?? null) ? $value['checked_at'] : 0;
        $status = is_string($value['viability_status'] ?? null) ? $value['viability_status'] : '';
        $reason = self::token($value['reason_code'] ?? '', 191);
        $http = is_int($value['http_status'] ?? null) ? $value['http_status'] : 0;
        $restored_by = is_int($value['restored_by'] ?? null) ? max(0, $value['restored_by']) : 0;
        $restored_at = is_int($value['restored_at'] ?? null) ? max(0, $value['restored_at']) : 0;
        $statuses = array(
            Serving_Viability::HEALTHY,
            Serving_Viability::PROCESSING,
            Serving_Viability::MISSING,
            Serving_Viability::PRIVATE_OR_RESTRICTED,
            Serving_Viability::EMBED_DISALLOWED,
            Serving_Viability::TEMPORARILY_UNAVAILABLE,
            Serving_Viability::PROBE_INDETERMINATE,
        );
        if (self::VERSION !== ($value['version'] ?? null)
            || $remote_asset_id < 1
            || '' === $backend_id
            || Backend_Registry::LOCAL_ID === $backend_id
            || $checked_by < 1
            || $checked_at < 1
            || ! in_array($status, $statuses, true)
            || $http < 0 || $http > 599
            || (0 === $restored_at && 0 !== $restored_by)
            || ($restored_at > 0 && ($restored_by < 1 || $restored_at < $checked_at))) {
            return array();
        }
        return array(
            'version' => self::VERSION,
            'remote_asset_id' => $remote_asset_id,
            'backend_id' => $backend_id,
            'checked_by' => $checked_by,
            'checked_at' => $checked_at,
            'viability_status' => $status,
            'reason_code' => $reason,
            'http_status' => $http,
            'restored_by' => $restored_by,
            'restored_at' => $restored_at,
        );
    }

    private static function token(mixed $value, int $max): string
    {
        if (! is_string($value) || '' === $value || strlen($value) > $max
            || 1 !== preg_match('/^[a-z0-9][a-z0-9._-]*$/D', $value)) {
            return '';
        }
        return $value;
    }
}
// EOF
