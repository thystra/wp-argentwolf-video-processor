<?php
/** File: includes/Remote_Health_Notification_Policy_Store.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Site-wide email policy for visitor-facing remote-publication health incidents. */
final class Remote_Health_Notification_Policy_Store
{
    public const OPTION = 'argent_video_processor_remote_health_notification_policy';
    public const VERSION = 1;
    public const OFF = 'off';
    public const DELAYED = 'delayed';
    public const IMMEDIATE = 'immediate';
    public const DELAY_SECONDS = 7200;

    /** @return array{version:int,administrator:string,publishing_user:string,origin_author:string} */
    public static function defaults(): array
    {
        return array(
            'version'=>self::VERSION,
            'administrator'=>self::DELAYED,
            'publishing_user'=>self::DELAYED,
            'origin_author'=>self::OFF,
        );
    }

    /** @return array{version:int,administrator:string,publishing_user:string,origin_author:string} */
    public function get(): array
    {
        $raw=get_option(self::OPTION,array());
        $clean=self::sanitize($raw);
        return null===$clean?self::defaults():$clean;
    }

    public function save(mixed $value): bool
    {
        $clean=self::sanitize($value);
        if(null===$clean)return false;
        update_option(self::OPTION,$clean,false);
        return $this->get()===$clean;
    }

    /** @return array{version:int,administrator:string,publishing_user:string,origin_author:string}|null */
    public static function sanitize(mixed $value): ?array
    {
        if(!is_array($value))return null;
        // Missing policy on upgrade is handled by get() defaults. Explicit save
        // requires the versioned complete record so malformed state fails closed.
        if(self::VERSION!==($value['version']??null))return null;
        $out=array('version'=>self::VERSION);
        foreach(array('administrator','publishing_user','origin_author') as $role){
            $mode=is_string($value[$role]??null)?$value[$role]:'';
            if(!in_array($mode,array(self::OFF,self::DELAYED,self::IMMEDIATE),true))return null;
            $out[$role]=$mode;
        }
        return $out;
    }

    public static function due(string $mode,int $failure_since,int $now): bool
    {
        if($failure_since<1||$now<$failure_since)return false;
        return self::IMMEDIATE===$mode||(self::DELAYED===$mode&&$now-$failure_since>=self::DELAY_SECONDS);
    }
}
// EOF
