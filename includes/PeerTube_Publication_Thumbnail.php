<?php
/** File: includes/PeerTube_Publication_Thumbnail.php */
declare(strict_types=1);
namespace ArgentVideo;
final class PeerTube_Publication_Thumbnail
{
    public const MAX_BYTES = 10485760;
    /** @return array{attachment_id:int,path:string,mime:string,bytes:int,sha256:string}|null */
    public static function capture(int $attachment_id): ?array
    {
        if ($attachment_id < 1 || ! wp_attachment_is_image($attachment_id)) return null;
        $path = (string) get_attached_file($attachment_id, true);
        if ('' === $path || ! is_file($path) || is_link($path) || ! is_readable($path)) return null;
        $bytes = filesize($path); if (! is_int($bytes) || $bytes < 1 || $bytes > self::MAX_BYTES) return null;
        $mime = get_post_mime_type($attachment_id);
        if (! in_array($mime, array('image/jpeg','image/png','image/webp'), true)) return null;
        $sha = hash_file('sha256', $path); if (! is_string($sha) || 64 !== strlen($sha)) return null;
        return array('attachment_id'=>$attachment_id,'path'=>$path,'mime'=>$mime,'bytes'=>$bytes,'sha256'=>$sha);
    }
}
// EOF
