<?php
/** File: includes/PeerTube_Publication_Staging_Service.php */
declare(strict_types=1);
namespace ArgentVideo;
use Throwable;

/**
 * Capture the authoritative WordPress attachment into AWVP-managed staging.
 *
 * PeerTube owns its own transcoding pipeline. Publication staging therefore
 * copies the exact Media Library source rather than reusing an AWVP FFmpeg
 * derivative. The copy remains confined to the plugin-managed uploads tree.
 */
final class PeerTube_Publication_Staging_Service
{
    /** @return array{status:string,path:string,attachment_id:int,content_type:string} */
    public function stage(int $video_id): array
    {
        $attachment_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true));
        if ($video_id < 1 || $attachment_id < 1) return self::result('refused');

        $content_type = self::video_content_type(get_post_mime_type($attachment_id));
        if ('' === $content_type) return self::result('refused');
        $source_identity = WordPress_Source_File::capture($attachment_id);
        if (array() === $source_identity) return self::result('refused');

        try {
            $source_size = $source_identity['bytes'] ?? 0;
            $source_sha = WordPress_Source_File::sha256($attachment_id, $source_identity);
            if (! is_int($source_size) || $source_size < 1 || 64 !== strlen($source_sha)) return self::result('conflict');
            $extension = self::safe_extension((string)($source_identity['relative_path'] ?? ''));
            $dir = Storage::ensure_attachment_directory($attachment_id) . '/staging';
            Storage::make_directory($dir);
            $target = Storage::assert_managed_path(
                $dir . '/peertube-source-' . substr($source_sha,0,16) . ('' === $extension ? '' : '.' . $extension)
            );
            if (is_file($target) && ! is_link($target) && filesize($target)===$source_size && hash_file('sha256',$target)===$source_sha) {
                return self::result('ready',$target,$attachment_id,$content_type);
            }
            $tmp = Storage::assert_managed_path($dir . '/.peertube-' . bin2hex(random_bytes(8)) . '.tmp');
            if (! WordPress_Source_File::copy_to_managed($attachment_id, $source_identity, $tmp)) {
                return self::result(WordPress_Source_File::matches($attachment_id, $source_identity) ? 'indeterminate' : 'conflict');
            }
            if (filesize($tmp)!==$source_size || hash_file('sha256',$tmp)!==$source_sha) { Storage::delete_file($tmp); return self::result('indeterminate'); }
            if (! WordPress_Source_File::matches($attachment_id, $source_identity)) { Storage::delete_file($tmp); return self::result('conflict'); }
            if (is_file($target) || is_link($target)) Storage::delete_file($target);
            Storage::rename_path($tmp,$target);
            return self::result('ready',$target,$attachment_id,$content_type);
        } catch (Throwable) { return self::result('indeterminate'); }
    }

    private static function video_content_type(mixed $value): string
    {
        if (! is_string($value) || trim($value) !== $value || strlen($value) > 128) return '';
        $value = strtolower($value);
        return 1 === preg_match('/\Avideo\/[a-z0-9][a-z0-9.+_-]{0,126}\z/D', $value) ? $value : '';
    }

    private static function safe_extension(string $relative_path): string
    {
        $extension = strtolower((string) pathinfo($relative_path, PATHINFO_EXTENSION));
        return 1 === preg_match('/\A[a-z0-9]{1,16}\z/D', $extension) ? $extension : '';
    }

    /** @return array{status:string,path:string,attachment_id:int,content_type:string} */
    private static function result(string $status,string $path='',int $attachment_id=0,string $content_type=''):array
    {
        return array('status'=>$status,'path'=>$path,'attachment_id'=>$attachment_id,'content_type'=>$content_type);
    }
}
// EOF
