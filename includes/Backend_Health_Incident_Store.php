<?php
/** File: includes/Backend_Health_Incident_Store.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Bounded backend-wide outage state inferred from visitor-facing probes. */
final class Backend_Health_Incident_Store
{
    public const OPTION='argent_video_processor_backend_health_incidents';
    public const VERSION=1;
    private const MAX_BACKENDS=64;
    private const MAX_MESSAGE=512;

    /** @return array<string,array{failure_since:int,last_failure_at:int,reason_code:string,message:string,http_status:int}> */
    public function all():array
    {
        $raw=get_option(self::OPTION,array());
        if(!is_array($raw)||self::VERSION!==($raw['version']??null)||!is_array($raw['backends']??null))return array();
        $out=array();
        foreach($raw['backends'] as $id=>$row){$id=Backend_Identity::sanitize($id);$row=self::sanitize_row($row);if(''!==$id&&null!==$row)$out[$id]=$row;if(count($out)>=self::MAX_BACKENDS)break;}
        ksort($out,SORT_STRING);return $out;
    }
    /** @return array{failure_since:int,last_failure_at:int,reason_code:string,message:string,http_status:int}|null */
    public function get(string $id):?array{$id=Backend_Identity::sanitize($id);$all=$this->all();return ''!==$id?($all[$id]??null):null;}
    public function mark_failure(string $id,Serving_Viability $v,int $now):bool
    {
        $id=Backend_Identity::sanitize($id);if(''===$id||Backend_Registry::LOCAL_ID===$id||$now<1||!self::backend_wide($v))return false;
        $all=$this->all();$before=$all[$id]??null;$failure=is_array($before)?$before['failure_since']:$now;
        $all[$id]=array('failure_since'=>$failure,'last_failure_at'=>$now,'reason_code'=>$v->reason_code(),'message'=>$v->message(),'http_status'=>$v->http_status());
        return $this->write($all);
    }
    public function mark_healthy(string $id):bool
    {
        $id=Backend_Identity::sanitize($id);if(''===$id)return false;$all=$this->all();if(!isset($all[$id]))return true;unset($all[$id]);return $this->write($all);
    }
    public static function backend_wide(Serving_Viability $v):bool
    {
        return Serving_Viability::TEMPORARILY_UNAVAILABLE===$v->status()
            && (str_contains($v->reason_code(),'backend_unavailable')||str_contains($v->reason_code(),'public_unavailable'));
    }
    /** @param array<string,array<string,mixed>> $all */
    private function write(array $all):bool
    {
        if(count($all)>self::MAX_BACKENDS){uasort($all,static fn(array $a,array $b):int=>(int)$b['last_failure_at']<=>(int)$a['last_failure_at']);$all=array_slice($all,0,self::MAX_BACKENDS,true);}ksort($all,SORT_STRING);
        update_option(self::OPTION,array('version'=>self::VERSION,'backends'=>$all),false);return $this->all()===$all;
    }
    /** @return array{failure_since:int,last_failure_at:int,reason_code:string,message:string,http_status:int}|null */
    private static function sanitize_row(mixed $r):?array
    {
        if(!is_array($r))return null;$f=is_int($r['failure_since']??null)?$r['failure_since']:0;$l=is_int($r['last_failure_at']??null)?$r['last_failure_at']:0;$reason=is_string($r['reason_code']??null)?$r['reason_code']:'';$m=is_string($r['message']??null)?trim($r['message']):'';$h=is_int($r['http_status']??null)?$r['http_status']:0;
        if($f<1||$l<$f||1!==preg_match('/^[A-Za-z0-9][A-Za-z0-9_.:-]{0,63}$/D',$reason)||''===$m||strlen($m)>self::MAX_MESSAGE||($h!==0&&($h<100||$h>599)))return null;
        return array('failure_since'=>$f,'last_failure_at'=>$l,'reason_code'=>$reason,'message'=>$m,'http_status'=>$h);
    }
}
// EOF
