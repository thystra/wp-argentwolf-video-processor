<?php
/**
 * File: includes/PeerTube_Publication_Execution.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Durable non-secret execution journal stored on the hidden AWVP Video. */
final class PeerTube_Publication_Execution
{
    public const VERSION = 1;

    /** @return array<string,mixed> */
    public static function create(array $manifest, int $now): array
    {
        $manifest = PeerTube_Publication_Manifest::sanitize($manifest);
        if (array() === $manifest || $now < 1) {
            return array();
        }
        return self::sanitize(array(
            'version'=>self::VERSION,
            'backend_id'=>$manifest['backend_id'],
            'channel_id'=>$manifest['channel_id'],
            'anchor_post_id'=>$manifest['anchor_post_id'],
            'manifest'=>$manifest,
            'manifest_sha256'=>PeerTube_Publication_Manifest::sha256($manifest),
            'operation_id'=>'',
            'remote_asset_id'=>0,
            'remote_uuid'=>'',
            'applied_manifest'=>array(),
            'applied_manifest_sha256'=>'',
            'updated_at'=>$now,
        ));
    }

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        $keys = array('version','backend_id','channel_id','anchor_post_id','manifest','manifest_sha256','operation_id','remote_asset_id','remote_uuid','applied_manifest','applied_manifest_sha256','updated_at');
        if (! is_array($value) || $keys !== array_keys($value) || self::VERSION !== ($value['version'] ?? null)) {
            return array();
        }
        $manifest = PeerTube_Publication_Manifest::sanitize($value['manifest'] ?? null);
        $sha = PeerTube_Publication_Manifest::sha256($manifest);
        $operation = is_string($value['operation_id'] ?? null) ? $value['operation_id'] : '';
        $asset = is_int($value['remote_asset_id'] ?? null) ? $value['remote_asset_id'] : -1;
        $uuid = is_string($value['remote_uuid'] ?? null) ? strtolower($value['remote_uuid']) : '';
        $applied = $value['applied_manifest'] ?? null;
        $applied_sha = is_string($value['applied_manifest_sha256'] ?? null) ? $value['applied_manifest_sha256'] : '';
        $updated = is_int($value['updated_at'] ?? null) ? $value['updated_at'] : 0;
        if (array() === $manifest || $sha !== ($value['manifest_sha256'] ?? null)
            || $manifest['backend_id'] !== ($value['backend_id'] ?? null)
            || $manifest['channel_id'] !== ($value['channel_id'] ?? null)
            || $manifest['anchor_post_id'] !== ($value['anchor_post_id'] ?? null)
            || ('' !== $operation && 1 !== preg_match('/^upload_[a-f0-9]{32}$/D', $operation))
            || $asset < 0 || $updated < 1
            || ('' !== $uuid && 1 !== preg_match('/^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/D', $uuid))
            || (($asset > 0 || '' !== $uuid) && ($asset < 1 || '' === $uuid))
        ) {
            return array();
        }
        if (array() === $applied) {
            if ('' !== $applied_sha) {
                return array();
            }
        } else {
            $applied = PeerTube_Publication_Manifest::sanitize($applied);
            if (array() === $applied || PeerTube_Publication_Manifest::sha256($applied) !== $applied_sha) {
                return array();
            }
        }
        return array(
            'version'=>self::VERSION,'backend_id'=>$manifest['backend_id'],'channel_id'=>$manifest['channel_id'],
            'anchor_post_id'=>$manifest['anchor_post_id'],'manifest'=>$manifest,'manifest_sha256'=>$sha,
            'operation_id'=>$operation,'remote_asset_id'=>$asset,'remote_uuid'=>$uuid,
            'applied_manifest'=>$applied,'applied_manifest_sha256'=>$applied_sha,'updated_at'=>$updated,
        );
    }

    /** @return array<string,mixed> */
    public static function with_manifest(array $record, array $manifest, int $now): array
    {
        $record = self::sanitize($record); $manifest = PeerTube_Publication_Manifest::sanitize($manifest);
        if (array() === $record || array() === $manifest || $now < 1
            || $record['backend_id'] !== $manifest['backend_id'] || $record['channel_id'] !== $manifest['channel_id']
            || $record['anchor_post_id'] !== $manifest['anchor_post_id']) return array();
        $record['manifest']=$manifest; $record['manifest_sha256']=PeerTube_Publication_Manifest::sha256($manifest); $record['updated_at']=$now;
        return self::sanitize($record);
    }

    /** @return array<string,mixed> */
    public static function with_operation(array $record, string $operation_id, int $now): array
    {
        $record=self::sanitize($record); if(array()===$record||1!==preg_match('/^upload_[a-f0-9]{32}$/D',$operation_id)||$now<1)return array();
        $record['operation_id']=$operation_id; $record['updated_at']=$now; return self::sanitize($record);
    }

    /** @return array<string,mixed> */
    public static function with_remote(array $record, int $asset_id, string $uuid, int $now): array
    {
        $record=self::sanitize($record); $uuid=strtolower($uuid); if(array()===$record||$asset_id<1||1!==preg_match('/^[a-f0-9-]{36}$/D',$uuid)||$now<1)return array();
        $record['remote_asset_id']=$asset_id; $record['remote_uuid']=$uuid; $record['updated_at']=$now; return self::sanitize($record);
    }

    /** @return array<string,mixed> */
    public static function mark_applied(array $record, array $manifest, int $now): array
    {
        $record=self::sanitize($record); $manifest=PeerTube_Publication_Manifest::sanitize($manifest); if(array()===$record||array()===$manifest||$now<1)return array();
        $record['applied_manifest']=$manifest; $record['applied_manifest_sha256']=PeerTube_Publication_Manifest::sha256($manifest); $record['updated_at']=$now; return self::sanitize($record);
    }
}

// EOF: includes/PeerTube_Publication_Execution.php
