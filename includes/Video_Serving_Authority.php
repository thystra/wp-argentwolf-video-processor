<?php
/**
 * File: includes/Video_Serving_Authority.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Durable, non-secret evidence that a verified PeerTube asset may serve a video. */
final class Video_Serving_Authority
{
    public const VERSION = 1;
    public const OPERATOR_VERSION = 2;
    public const MODE_PEERTUBE = 'peertube';
    public const BASIS_FINALIZER = 'finalizer';
    public const BASIS_OPERATOR_VERIFIED = 'operator_verified_remote';

    /** @return array<string,mixed> */
    public static function create(
        int $video_post_id,
        array $lifecycle,
        array $execution,
        array $asset,
        int $now
    ): array {
        $fields = self::validated_fields($video_post_id, $lifecycle, $execution, $asset, $now, true);
        if (array() === $fields) {
            return array();
        }
        return self::sanitize(array_merge(
            array('version'=>self::VERSION, 'mode'=>self::MODE_PEERTUBE),
            $fields
        ));
    }

    /**
     * Create explicit authority for an already-known publication after a fresh
     * visitor-facing operator check. This intentionally does not claim that a
     * historical finalizer/applied-manifest step succeeded.
     *
     * @return array<string,mixed>
     */
    public static function create_operator_adopted(
        int $video_post_id,
        array $lifecycle,
        array $execution,
        array $asset,
        int $now
    ): array {
        $fields = self::validated_fields($video_post_id, $lifecycle, $execution, $asset, $now, false);
        if (array() === $fields) {
            return array();
        }
        return self::sanitize(array_merge(
            array(
                'version'=>self::OPERATOR_VERSION,
                'mode'=>self::MODE_PEERTUBE,
                'basis'=>self::BASIS_OPERATOR_VERIFIED,
            ),
            $fields
        ));
    }

    /** @return array<string,mixed> */
    private static function validated_fields(
        int $video_post_id,
        array $lifecycle,
        array $execution,
        array $asset,
        int $now,
        bool $require_applied_manifest
    ): array {
        $lifecycle = PeerTube_Publication_Lifecycle::sanitize($lifecycle);
        $execution = PeerTube_Publication_Execution::sanitize($execution);
        if ($video_post_id < 1 || $now < 1 || array() === $lifecycle || array() === $execution) {
            return array();
        }
        $privacy_id = (string) ($lifecycle['target_privacy_id'] ?? '');
        if (! in_array($privacy_id, array('1', '2'), true)
            || true !== ($lifecycle['reveal_authorized'] ?? false)
            || $execution['backend_id'] !== ($lifecycle['backend_id'] ?? null)
            || $execution['anchor_post_id'] !== ($lifecycle['anchor_post_id'] ?? null)
            || ! hash_equals((string) $lifecycle['plan_sha256'], (string) ($execution['manifest']['plan_sha256'] ?? ''))
            || ($require_applied_manifest && ('' === (string) $execution['applied_manifest_sha256']
                || ! hash_equals((string) $execution['manifest_sha256'], (string) $execution['applied_manifest_sha256'])))) {
            return array();
        }
        $remote_asset_id = self::positive_int($asset['id'] ?? null);
        $remote_uuid = self::uuid($asset['remote_id'] ?? null);
        $embed_url = self::embed_url($asset['embed_url'] ?? null, $remote_uuid);
        $privacy_name = self::privacy_name($privacy_id);
        if ($remote_asset_id < 1
            || $video_post_id !== self::positive_int($asset['video_post_id'] ?? null)
            || $execution['backend_id'] !== ($asset['backend_id'] ?? null)
            || $execution['channel_id'] !== ($asset['channel_id'] ?? null)
            || $remote_asset_id !== (int) $execution['remote_asset_id']
            || $remote_uuid !== (string) $execution['remote_uuid']
            || ! in_array((string) ($asset['role'] ?? ''), array('secondary','primary'), true)
            || 'ready' !== ($asset['state'] ?? null)
            || $privacy_name !== ($asset['desired_privacy'] ?? null)
            || $privacy_name !== ($asset['actual_privacy'] ?? null)
            || '1:published' !== ($asset['remote_processing_state'] ?? null)
            || ! is_string($asset['last_verified_at'] ?? null) || '' === $asset['last_verified_at']
            || '' === $embed_url) {
            return array();
        }
        return array(
            'backend_id'=>$execution['backend_id'],
            'channel_id'=>$execution['channel_id'],
            'anchor_post_id'=>$execution['anchor_post_id'],
            'generation'=>$lifecycle['generation'],
            'plan_sha256'=>$lifecycle['plan_sha256'],
            'manifest_sha256'=>$execution['manifest_sha256'],
            'remote_asset_id'=>$remote_asset_id,
            'remote_uuid'=>$remote_uuid,
            'embed_url'=>$embed_url,
            'privacy_id'=>$privacy_id,
            'verified_at'=>$now,
        );
    }

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value) || ! is_int($value['version'] ?? null)) {
            return array();
        }
        if (self::VERSION === $value['version']) {
            $keys = array(
                'version','mode','backend_id','channel_id','anchor_post_id','generation',
                'plan_sha256','manifest_sha256','remote_asset_id','remote_uuid','embed_url',
                'privacy_id','verified_at',
            );
            if ($keys !== array_keys($value) || self::MODE_PEERTUBE !== ($value['mode'] ?? null)) {
                return array();
            }
        } elseif (self::OPERATOR_VERSION === $value['version']) {
            $keys = array(
                'version','mode','basis','backend_id','channel_id','anchor_post_id','generation',
                'plan_sha256','manifest_sha256','remote_asset_id','remote_uuid','embed_url',
                'privacy_id','verified_at',
            );
            if ($keys !== array_keys($value) || self::MODE_PEERTUBE !== ($value['mode'] ?? null)
                || self::BASIS_OPERATOR_VERIFIED !== ($value['basis'] ?? null)) {
                return array();
            }
        } else {
            return array();
        }
        $backend = Backend_Identity::sanitize($value['backend_id'] ?? null);
        $channel = PeerTube_Connection_Input::destination_id($value['channel_id'] ?? null);
        $anchor = self::positive_int($value['anchor_post_id'] ?? null);
        $generation = self::positive_int($value['generation'] ?? null);
        $asset = self::positive_int($value['remote_asset_id'] ?? null);
        $uuid = self::uuid($value['remote_uuid'] ?? null);
        $embed = self::embed_url($value['embed_url'] ?? null, $uuid);
        $privacy = is_string($value['privacy_id'] ?? null) ? $value['privacy_id'] : '';
        $verified = self::positive_int($value['verified_at'] ?? null);
        $plan_sha = is_string($value['plan_sha256'] ?? null) ? $value['plan_sha256'] : '';
        $manifest_sha = is_string($value['manifest_sha256'] ?? null) ? $value['manifest_sha256'] : '';
        if ('' === $backend || '' === $channel || $anchor < 1 || $generation < 1 || $asset < 1
            || '' === $uuid || '' === $embed || ! in_array($privacy, array('1','2'), true)
            || $verified < 1 || 1 !== preg_match('/^[a-f0-9]{64}$/D', $plan_sha)
            || 1 !== preg_match('/^[a-f0-9]{64}$/D', $manifest_sha)) {
            return array();
        }
        $out = array('version'=>$value['version'],'mode'=>self::MODE_PEERTUBE);
        if (self::OPERATOR_VERSION === $value['version']) {
            $out['basis'] = self::BASIS_OPERATOR_VERIFIED;
        }
        return array_merge($out, array(
            'backend_id'=>$backend,'channel_id'=>$channel,'anchor_post_id'=>$anchor,'generation'=>$generation,
            'plan_sha256'=>$plan_sha,'manifest_sha256'=>$manifest_sha,'remote_asset_id'=>$asset,
            'remote_uuid'=>$uuid,'embed_url'=>$embed,'privacy_id'=>$privacy,'verified_at'=>$verified,
        ));
    }

    public static function basis(array $authority): string
    {
        $authority = self::sanitize($authority);
        if (array() === $authority) {
            return '';
        }
        return self::OPERATOR_VERSION === $authority['version']
            ? self::BASIS_OPERATOR_VERIFIED
            : self::BASIS_FINALIZER;
    }

    public static function privacy_name(string $privacy_id): string
    {
        return match ($privacy_id) {
            '1' => 'public',
            '2' => 'unlisted',
            '3' => 'private',
            '4' => 'internal',
            default => '',
        };
    }

    private static function positive_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return 0;
        }
        $number = (int) $value;
        return $number > 0 && (string) $number === $value ? $number : 0;
    }

    private static function uuid(mixed $value): string
    {
        if (! is_string($value)) {
            return '';
        }
        $value = strtolower($value);
        return 1 === preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $value)
            ? $value
            : '';
    }

    private static function embed_url(mixed $value, string $uuid): string
    {
        if (! is_string($value) || '' === $uuid || strlen($value) > 2048
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return '';
        }
        $parts = wp_parse_url($value);
        $path = is_string($parts['path'] ?? null) ? (string) $parts['path'] : '';
        if (! is_array($parts)
            || ! in_array($parts['scheme'] ?? '', array('https','http'), true)
            || ! is_string($parts['host'] ?? null) || '' === $parts['host']
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || 1 !== preg_match('#^/videos/embed/[A-Za-z0-9_-]{1,191}$#D', $path)) {
            return '';
        }
        $origin = (string) $parts['scheme'] . '://' . (string) $parts['host'];
        if (isset($parts['port'])) {
            $origin .= ':' . (string) $parts['port'];
        }
        return '' !== PeerTube_Origin::sanitize($origin) ? $value : '';
    }
}

// EOF: includes/Video_Serving_Authority.php
