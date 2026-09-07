<?php
/** File: includes/PeerTube_Publication_Staging_Service.php */
declare(strict_types=1);
namespace ArgentVideo;
use Throwable;
final class PeerTube_Publication_Staging_Service
{
    /** @return array{status:string,path:string,attachment_id:int} */
    public function stage(int $video_id): array
    {
        $attachment_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true));
        if ($video_id < 1 || $attachment_id < 1) return self::result('refused');
        $outputs = get_post_meta($attachment_id, '_argent_video_outputs', true);
        $mp4 = is_array($outputs) && is_array($outputs['mp4'] ?? null) ? (string)($outputs['mp4']['path'] ?? '') : '';
        if ('' !== $mp4 && Storage::is_managed_path($mp4) && is_file($mp4) && ! is_link($mp4) && is_readable($mp4)) {
            return self::result('ready', Storage::assert_managed_path($mp4), $attachment_id);
        }
        if ('video/mp4' !== get_post_mime_type($attachment_id)) return self::result('refused');

        $source_identity = WordPress_Source_File::capture($attachment_id);
        if (array() === $source_identity) return self::result('refused');

        try {
            $source_size = $source_identity['bytes'] ?? 0;
            $source_sha = WordPress_Source_File::sha256($attachment_id, $source_identity);
            if (! is_int($source_size) || $source_size < 1 || 64 !== strlen($source_sha)) return self::result('conflict');
            $dir = Storage::ensure_attachment_directory($attachment_id) . '/staging'; Storage::make_directory($dir);
            $target = Storage::assert_managed_path($dir . '/peertube-source-' . substr($source_sha,0,16) . '.mp4');
            if (is_file($target) && filesize($target)===$source_size && hash_file('sha256',$target)===$source_sha) return self::result('ready',$target,$attachment_id);
            $tmp = Storage::assert_managed_path($dir . '/.peertube-' . bin2hex(random_bytes(8)) . '.tmp');
            if (! WordPress_Source_File::copy_to_managed($attachment_id, $source_identity, $tmp)) {
                return self::result(WordPress_Source_File::matches($attachment_id, $source_identity) ? 'indeterminate' : 'conflict');
            }
            if (filesize($tmp)!==$source_size || hash_file('sha256',$tmp)!==$source_sha) { Storage::delete_file($tmp); return self::result('indeterminate'); }
            if (! WordPress_Source_File::matches($attachment_id, $source_identity)) { Storage::delete_file($tmp); return self::result('conflict'); }
            if (is_file($target)) Storage::delete_file($target);
            Storage::rename_path($tmp,$target); return self::result('ready',$target,$attachment_id);
        } catch (Throwable) { return self::result('indeterminate'); }
    }
    /** @return array{status:string,path:string,attachment_id:int} */
    private static function result(string $status,string $path='',int $attachment_id=0):array{return array('status'=>$status,'path'=>$path,'attachment_id'=>$attachment_id);}
}
// EOF
