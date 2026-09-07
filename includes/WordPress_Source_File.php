<?php
/** File: includes/WordPress_Source_File.php */
declare(strict_types=1);
namespace ArgentVideo;

use RuntimeException;

/** Confined physical WordPress attachment source ownership/deletion boundary. */
final class WordPress_Source_File
{
    /** @return array<string,mixed> */
    public static function capture(int $attachment_id):array
    {
        if($attachment_id<1)return array();
        $post=get_post($attachment_id);$mime=(string)get_post_mime_type($attachment_id);
        if(!is_object($post)||'attachment'!==($post->post_type??null)||!str_starts_with($mime,'video/'))return array();
        $path=get_attached_file($attachment_id,true);if(!is_string($path)||''===$path)return array();
        try { [$path,$relative]=self::confined($path); } catch (RuntimeException) { return array(); }
        if(is_link($path)||!is_file($path))return array();
        $stat=@stat($path);if(!is_array($stat))return array();
        return self::sanitize_identity(array('relative_path'=>$relative,'bytes'=>(int)$stat['size'],'device'=>(int)$stat['dev'],'inode'=>(int)$stat['ino'],'mtime'=>(int)$stat['mtime'],'ctime'=>(int)$stat['ctime']));
    }

    /** @return array<string,mixed> */
    public static function sanitize_identity(mixed $v):array
    {
        if(!is_array($v)||array('relative_path','bytes','device','inode','mtime','ctime')!==array_keys($v)||!is_string($v['relative_path'])||''===$v['relative_path']||str_contains($v['relative_path'],"\0")||str_starts_with($v['relative_path'],'/'))return array();
        foreach(explode('/',$v['relative_path']) as $part){if(''===$part||'.'===$part||'..'===$part)return array();}
        foreach(array('bytes','device','inode','mtime','ctime') as $k){if(!is_int($v[$k])||$v[$k]<0)return array();}
        if($v['inode']<1)return array();
        return $v;
    }

    public static function matches(int $attachment_id,array $identity):bool{return self::sanitize_identity($identity)===self::capture($attachment_id);}

    public static function absent(int $attachment_id,array $identity):bool
    {
        $identity=self::sanitize_identity($identity);if(array()===$identity||$attachment_id<1)return false;
        $path=get_attached_file($attachment_id,true);if(!is_string($path)||''===$path)return false;
        try{[$path,$relative]=self::confined_absent($path);}catch(RuntimeException){return false;}
        return $relative===$identity['relative_path']&&!file_exists($path)&&!is_link($path);
    }

    public static function delete(int $attachment_id,array $identity):bool
    {
        $identity=self::sanitize_identity($identity);if(array()===$identity||!self::matches($attachment_id,$identity))return false;
        $uploads=wp_upload_dir();if(!is_array($uploads)||!empty($uploads['error'])||!is_string($uploads['basedir']??null))return false;
        $path=rtrim(wp_normalize_path((string)$uploads['basedir']),'/').'/'.$identity['relative_path'];
        try{[$path,$relative]=self::confined($path);}catch(RuntimeException){return false;}
        if($relative!==$identity['relative_path']||is_link($path)||!is_file($path))return false;
        $stat=@stat($path);if(!is_array($stat))return false;
        $immediate=self::sanitize_identity(array('relative_path'=>$relative,'bytes'=>(int)$stat['size'],'device'=>(int)$stat['dev'],'inode'=>(int)$stat['ino'],'mtime'=>(int)$stat['mtime'],'ctime'=>(int)$stat['ctime']));
        if($immediate!==$identity)return false;
        wp_delete_file($path);clearstatcache(true,$path);return !file_exists($path)&&!is_link($path);
    }

    /** @return array{0:string,1:string} */
    private static function confined_absent(string $path):array
    {
        if(''===$path||str_contains($path,"\0"))throw new RuntimeException('Invalid source path.');
        $uploads=wp_upload_dir();if(!is_array($uploads)||!empty($uploads['error'])||!is_string($uploads['basedir']??null)||''===$uploads['basedir'])throw new RuntimeException('Uploads unavailable.');
        $base=rtrim(wp_normalize_path((string)$uploads['basedir']),'/');$path=wp_normalize_path($path);
        if(!str_starts_with($path,$base.'/'))throw new RuntimeException('Source outside uploads.');
        $relative=substr($path,strlen($base)+1);if(false===$relative||''===$relative)throw new RuntimeException('Invalid relative path.');
        foreach(explode('/',$relative) as $part){if(''===$part||'.'===$part||'..'===$part)throw new RuntimeException('Unsafe path segment.');}
        $real_base=realpath($base);if(false===$real_base)throw new RuntimeException('Uploads cannot be resolved.');
        $real_base=rtrim(wp_normalize_path($real_base),'/');
        $cursor=$base;$parts=explode('/',$relative);foreach($parts as $i=>$part){$cursor.='/'.$part;if(is_link($cursor))throw new RuntimeException('Source traverses symbolic link.');if($i<count($parts)-1&&file_exists($cursor)){ $real=realpath($cursor); if(false===$real||($real_base!==rtrim(wp_normalize_path($real),'/')&&!str_starts_with(rtrim(wp_normalize_path($real),'/'),$real_base.'/')))throw new RuntimeException('Source parent escapes uploads.');}}
        return array($path,$relative);
    }

    /** @return array{0:string,1:string} */
    private static function confined(string $path):array
    {
        if(''===$path||str_contains($path,"\0"))throw new RuntimeException('Invalid source path.');
        $uploads=wp_upload_dir();if(!is_array($uploads)||!empty($uploads['error'])||!is_string($uploads['basedir']??null)||''===$uploads['basedir'])throw new RuntimeException('Uploads unavailable.');
        $base=rtrim(wp_normalize_path((string)$uploads['basedir']),'/');$path=wp_normalize_path($path);
        if(!str_starts_with($path,$base.'/'))throw new RuntimeException('Source outside uploads.');
        $relative=substr($path,strlen($base)+1);if(false===$relative||''===$relative)throw new RuntimeException('Invalid relative path.');
        foreach(explode('/',$relative) as $part){if(''===$part||'.'===$part||'..'===$part)throw new RuntimeException('Unsafe path segment.');}
        $real_base=realpath($base);$real_path=realpath($path);if(false===$real_base||false===$real_path)throw new RuntimeException('Source cannot be resolved.');
        $real_base=rtrim(wp_normalize_path($real_base),'/');$real_path=wp_normalize_path($real_path);
        if(!str_starts_with($real_path,$real_base.'/'))throw new RuntimeException('Resolved source outside uploads.');
        $cursor=$base;foreach(explode('/',$relative) as $part){$cursor.='/'.$part;if(is_link($cursor))throw new RuntimeException('Source traverses symbolic link.');}
        return array($path,$relative);
    }
}
// EOF
