<?php
/**
 * File: includes/PeerTube_Publication_Lifecycle.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Strict local lifecycle intent for one PeerTube-bound AWVP Video.
 *
 * This record is WordPress-authoritative scheduling/reveal intent only. It is
 * not a remote operation, credential, task claim, or serving-cutover record.
 */
final class PeerTube_Publication_Lifecycle
{
    public const VERSION = 1;

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value) || self::VERSION !== ($value['version'] ?? null)) {
            return array();
        }

        $expected = array(
            'version','generation','backend_id','anchor_post_id','plan_sha256',
            'dispatch_policy','wordpress_status','upload_authorized',
            'reveal_authorized','target_privacy_id','task_pending','updated_at',
        );
        if ($expected !== array_keys($value)) {
            return array();
        }

        $generation = self::positive_int($value['generation'] ?? null);
        $backend_id = Backend_Identity::sanitize($value['backend_id'] ?? null);
        $anchor_post_id = self::positive_int($value['anchor_post_id'] ?? null);
        $plan_sha256 = is_string($value['plan_sha256'] ?? null) ? $value['plan_sha256'] : '';
        $dispatch = is_string($value['dispatch_policy'] ?? null) ? $value['dispatch_policy'] : '';
        $status = is_string($value['wordpress_status'] ?? null) ? $value['wordpress_status'] : '';
        $upload = self::boolean($value['upload_authorized'] ?? null);
        $reveal = self::boolean($value['reveal_authorized'] ?? null);
        $privacy = self::decimal_id($value['target_privacy_id'] ?? null);
        $pending = self::boolean($value['task_pending'] ?? null);
        $updated_at = self::positive_int($value['updated_at'] ?? null);

        if (
            $generation < 1
            || '' === $backend_id
            || Backend_Registry::LOCAL_ID === $backend_id
            || $anchor_post_id < 1
            || 1 !== preg_match('/^[a-f0-9]{64}$/D', $plan_sha256)
            || ! in_array($dispatch, array(
                PeerTube_Publication_Plan::DISPATCH_SEND_NOW,
                PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,
            ), true)
            || ! self::valid_post_status($status)
            || null === $upload
            || null === $reveal
            || '' === $privacy
            || null === $pending
            || $updated_at < 1
        ) {
            return array();
        }

        // Public/non-private target is authorized only by an actual WordPress
        // publish state. Every other state must target PeerTube private.
        if ($reveal) {
            if ('publish' !== $status || ! $upload) {
                return array();
            }
        } elseif ('3' !== $privacy) {
            return array();
        }

        return array(
            'version'            => self::VERSION,
            'generation'         => $generation,
            'backend_id'         => $backend_id,
            'anchor_post_id'     => $anchor_post_id,
            'plan_sha256'        => $plan_sha256,
            'dispatch_policy'    => $dispatch,
            'wordpress_status'   => $status,
            'upload_authorized'  => $upload,
            'reveal_authorized'  => $reveal,
            'target_privacy_id'  => $privacy,
            'task_pending'       => $pending,
            'updated_at'         => $updated_at,
        );
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public static function semantic(array $record): array
    {
        $record = self::sanitize($record);
        if (array() === $record) {
            return array();
        }
        unset($record['generation'], $record['task_pending'], $record['updated_at']);
        return $record;
    }

    public static function plan_sha256(array $plan): string
    {
        $plan = PeerTube_Publication_Plan::sanitize($plan);
        if (array() === $plan) {
            return '';
        }
        $json = wp_json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? hash('sha256', $json) : '';
    }

    public static function status_bucket(string $status): string
    {
        return self::valid_post_status($status) ? $status : 'other';
    }

    private static function valid_post_status(string $status): bool
    {
        return in_array($status, array(
            'draft','pending','future','publish','private','trash','auto-draft','inherit','other',
        ), true);
    }

    private static function positive_int(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }

    private static function boolean(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private static function decimal_id(mixed $value): string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : '';
        }
        return is_string($value) && 1 === preg_match('/^[1-9][0-9]*$/D', $value) ? $value : '';
    }
}

// EOF: includes/PeerTube_Publication_Lifecycle.php
