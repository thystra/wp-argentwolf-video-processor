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
        $source = (string)get_attached_file($attachment_id, true);
        if ('' === $source || ! is_file($source) || is_link($source) || ! is_readable($source)) return self::result('refused');
        try {
            $source_size = filesize($source); $source_sha = hash_file('sha256',$source);
            if (! is_int($source_size) || $source_size < 1 || ! is_string($source_sha) || 64 !== strlen($source_sha)) return self::result('refused');
            $dir = Storage::ensure_attachment_directory($attachment_id) . '/staging'; Storage::make_directory($dir);
            $target = Storage::assert_managed_path($dir . '/peertube-source-' . substr($source_sha,0,16) . '.mp4');
            if (is_file($target) && filesize($target)===$source_size && hash_file('sha256',$target)===$source_sha) return self::result('ready',$target,$attachment_id);
            $tmp = Storage::assert_managed_path($dir . '/.peertube-' . bin2hex(random_bytes(8)) . '.tmp');
            $in = fopen($source,'rb');
            if (false === $in) throw new \RuntimeException('open');
            $out = fopen($tmp,'xb');
            if (false === $out) { fclose($in); throw new \RuntimeException('open'); }
            $copied=stream_copy_to_stream($in,$out); fclose($in); fflush($out); fclose($out);
            if ($copied !== $source_size || filesize($tmp)!==$source_size || hash_file('sha256',$tmp)!==$source_sha) { Storage::delete_file($tmp); return self::result('indeterminate'); }
            clearstatcache(true,$source); if (filesize($source)!==$source_size || hash_file('sha256',$source)!==$source_sha) { Storage::delete_file($tmp); return self::result('conflict'); }
            if (is_file($target)) Storage::delete_file($target);
            Storage::rename_path($tmp,$target); return self::result('ready',$target,$attachment_id);
        } catch (Throwable) { return self::result('indeterminate'); }
    }
    /** @return array{status:string,path:string,attachment_id:int} */
    private static function result(string $status,string $path='',int $attachment_id=0):array{return array('status'=>$status,'path'=>$path,'attachment_id'=>$attachment_id);}
}
// EOF
