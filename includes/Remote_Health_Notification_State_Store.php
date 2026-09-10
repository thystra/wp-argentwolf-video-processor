<?php
/** File: includes/Remote_Health_Notification_State_Store.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Bounded delivery journal for remote-health alert/recovery email deduplication. */
final class Remote_Health_Notification_State_Store
{
    public const OPTION='argent_video_processor_remote_health_notification_state';
    public const VERSION=1;
    private const MAX_ASSETS=500;
    private const MAX_BACKENDS=64;
    private const MAX_AGE=7776000; // 90 days.
    private const ROLES=array('administrator','publishing_user','origin_author');

    /** @return array{failure_since:int,notified_roles:list<string>,recovered_roles:list<string>,updated_at:int} */
    public function asset(int $id):array{$all=$this->read();return $id>0?($all['assets'][(string)$id]??self::empty()):self::empty();}
    /** @return array{failure_since:int,notified_roles:list<string>,recovered_roles:list<string>,updated_at:int} */
    public function backend(string $id):array{$id=Backend_Identity::sanitize($id);$all=$this->read();return ''!==$id?($all['backends'][$id]??self::empty()):self::empty();}
    /** @param list<string> $roles */
    public function mark_asset(int $id,int $failure_since,array $roles,int $now,bool $recovery=false):bool{return $this->mark('assets',(string)$id,$failure_since,$roles,$now,$recovery);}
    /** @param list<string> $roles */
    public function mark_backend(string $id,int $failure_since,array $roles,int $now,bool $recovery=false):bool{$id=Backend_Identity::sanitize($id);return ''!==$id&&$this->mark('backends',$id,$failure_since,$roles,$now,$recovery);}
    public function clear_asset(int $id):bool{return $this->clear('assets',(string)$id);}
    public function clear_backend(string $id):bool{$id=Backend_Identity::sanitize($id);return ''!==$id&&$this->clear('backends',$id);}

    /** @return array{version:int,assets:array<string,array<string,mixed>>,backends:array<string,array<string,mixed>>} */
    private function read():array
    {
        $raw=get_option(self::OPTION,array());$out=array('version'=>self::VERSION,'assets'=>array(),'backends'=>array());if(!is_array($raw)||self::VERSION!==($raw['version']??null))return $out;
        foreach(array('assets','backends') as $bucket){if(!is_array($raw[$bucket]??null))continue;foreach($raw[$bucket] as $key=>$row){$clean=self::sanitize_row($row);if(null!==$clean)$out[$bucket][(string)$key]=$clean;}}
        return $out;
    }
    /** @param list<string> $roles */
    private function mark(string $bucket,string $key,int $failure_since,array $roles,int $now,bool $recovery):bool
    {
        if($failure_since<1||$now<$failure_since||''===$key)return false;$all=$this->read();$current=$all[$bucket][$key]??self::empty();if($current['failure_since']!==$failure_since)$current=array('failure_since'=>$failure_since,'notified_roles'=>array(),'recovered_roles'=>array(),'updated_at'=>$now);
        $target=$recovery?'recovered_roles':'notified_roles';foreach($roles as $role){if(in_array($role,self::ROLES,true)&&!in_array($role,$current[$target],true))$current[$target][]=$role;}sort($current[$target],SORT_STRING);$current['updated_at']=$now;$all[$bucket][$key]=$current;return $this->write($all,$now);
    }
    private function clear(string $bucket,string $key):bool{$all=$this->read();if(!isset($all[$bucket][$key]))return true;unset($all[$bucket][$key]);return $this->write($all,time());}
    /** @param array{version:int,assets:array<string,array<string,mixed>>,backends:array<string,array<string,mixed>>} $all */
    private function write(array $all,int $now):bool
    {
        foreach(array('assets'=>self::MAX_ASSETS,'backends'=>self::MAX_BACKENDS) as $bucket=>$max){foreach($all[$bucket] as $key=>$row){if((int)$row['updated_at']<$now-self::MAX_AGE)unset($all[$bucket][$key]);}if(count($all[$bucket])>$max){uasort($all[$bucket],static fn(array $a,array $b):int=>(int)$b['updated_at']<=>(int)$a['updated_at']);$all[$bucket]=array_slice($all[$bucket],0,$max,true);}}
        update_option(self::OPTION,$all,false);return $this->read()===$all;
    }
    /** @return array{failure_since:int,notified_roles:list<string>,recovered_roles:list<string>,updated_at:int} */
    private static function empty():array{return array('failure_since'=>0,'notified_roles'=>array(),'recovered_roles'=>array(),'updated_at'=>0);}
    /** @return array{failure_since:int,notified_roles:list<string>,recovered_roles:list<string>,updated_at:int}|null */
    private static function sanitize_row(mixed $r):?array
    {
        if(!is_array($r))return null;$f=is_int($r['failure_since']??null)?$r['failure_since']:0;$u=is_int($r['updated_at']??null)?$r['updated_at']:0;if($f<1||$u<$f)return null;$out=array('failure_since'=>$f,'notified_roles'=>array(),'recovered_roles'=>array(),'updated_at'=>$u);foreach(array('notified_roles','recovered_roles') as $k){if(!is_array($r[$k]??null))return null;foreach($r[$k] as $role){if(is_string($role)&&in_array($role,self::ROLES,true)&&!in_array($role,$out[$k],true))$out[$k][]=$role;}sort($out[$k],SORT_STRING);}return $out;
    }
}
// EOF
