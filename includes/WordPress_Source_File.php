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

    /** Hash the exact confined attachment object represented by the identity. */
    public static function sha256(int $attachment_id,array $identity):string
    {
        $opened=self::open_verified($attachment_id,$identity);if(!is_array($opened))return '';
        [$handle,$path,$relative]=$opened;
        try{
            $context=hash_init('sha256');$read=hash_update_stream($context,$handle);
            if(!is_int($read)||$read!==$identity['bytes']||!self::verified_handle_matches($handle,$path,$relative,$identity))return '';
            $sha=hash_final($context);return 1===preg_match('/^[a-f0-9]{64}$/D',$sha)?$sha:'';
        }finally{
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the exact read-only descriptor for an already-confined WordPress attachment source.
            fclose($handle);
        }
    }

    /** Copy one exact confined attachment source into plugin-managed uploads storage. */
    public static function copy_to_managed(int $attachment_id,array $identity,string $target):bool
    {
        $identity=self::sanitize_identity($identity);if(array()===$identity)return false;
        try{$target=Storage::assert_managed_path($target);}catch(RuntimeException){return false;}
        if(file_exists($target)||is_link($target))return false;
        $opened=self::open_verified($attachment_id,$identity);if(!is_array($opened))return false;
        [$source_handle,$source_path,$relative]=$opened;$target_handle=false;$keep=false;
        try{
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Exclusive creation is required for a plugin-managed staging file; Storage confines the destination above.
            $target_handle=@fopen($target,'xb');if(false===$target_handle)return false;
            $copied=stream_copy_to_stream($source_handle,$target_handle);$flushed=fflush($target_handle);
            $target_stat=fstat($target_handle);
            if(!is_int($copied)||$copied!==$identity['bytes']||!$flushed||!is_array($target_stat)||(int)($target_stat['size']??-1)!==$identity['bytes']||!self::verified_handle_matches($source_handle,$source_path,$relative,$identity))return false;
            $keep=true;return true;
        }finally{
            if(is_resource($target_handle)){
                // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the exclusive plugin-managed staging descriptor opened above.
                fclose($target_handle);
            }
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the exact read-only descriptor for an already-confined WordPress attachment source.
            fclose($source_handle);
            if(!$keep&&(is_file($target)||is_link($target))){try{Storage::delete_file($target);}catch(RuntimeException){}}
        }
    }

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

    /** @return array{0:resource,1:string,2:string}|null */
    private static function open_verified(int $attachment_id,array $identity):?array
    {
        $identity=self::sanitize_identity($identity);if(array()===$identity||$attachment_id<1)return null;
        $path=get_attached_file($attachment_id,true);if(!is_string($path)||''===$path)return null;
        try{[$path,$relative]=self::confined($path);}catch(RuntimeException){return null;}
        if($relative!==$identity['relative_path']||is_link($path)||!is_file($path))return null;
        $stat=@stat($path);if(!is_array($stat)||self::identity_from_stat($relative,$stat)!==$identity)return null;
        // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- A stable read-only descriptor is required to bind hashing/copying to the exact confined attachment object.
        $handle=@fopen($path,'rb');if(false===$handle)return null;
        if(!self::verified_handle_matches($handle,$path,$relative,$identity)){
            // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closes the rejected read-only attachment descriptor.
            fclose($handle);return null;
        }
        return array($handle,$path,$relative);
    }

    /** @param resource $handle */
    private static function verified_handle_matches($handle,string $path,string $relative,array $identity):bool
    {
        if(!is_resource($handle)||is_link($path))return false;
        $handle_stat=fstat($handle);$path_stat=@stat($path);
        return is_array($handle_stat)&&is_array($path_stat)&&self::identity_from_stat($relative,$handle_stat)===$identity&&self::identity_from_stat($relative,$path_stat)===$identity;
    }

    /** @param array<string,mixed> $stat @return array<string,mixed> */
    private static function identity_from_stat(string $relative,array $stat):array
    {
        return self::sanitize_identity(array('relative_path'=>$relative,'bytes'=>(int)($stat['size']??-1),'device'=>(int)($stat['dev']??-1),'inode'=>(int)($stat['ino']??-1),'mtime'=>(int)($stat['mtime']??-1),'ctime'=>(int)($stat['ctime']??-1)));
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
