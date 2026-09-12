<?php
/**
 * File: includes/Remote_Health_Notification_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use Throwable;

/** Best-effort, deduplicated email alerts for durable remote-serving health incidents. */
final class Remote_Health_Notification_Service
{
    /** @var Closure(string):array<string,mixed>|null */
    private Closure $operation_reader;

    public function __construct(
        private readonly Remote_Health_Notification_Policy_Store $policy,
        private readonly Remote_Health_Notification_State_Store $state,
        private readonly Video_Serving_Service $serving,
        callable $operation_reader,
        private readonly ?PeerTube_Event_Repository $events = null,
        private readonly ?Video_Reference_Index $references = null
    ) {
        $this->operation_reader = Closure::fromCallable($operation_reader);
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $health */
    public function publication_observed(array $asset, array $health, int $now, bool $backend_outage = false): void
    {
        $asset_id=(int)($asset['id']??0);$video_id=(int)($asset['video_post_id']??0);
        if($asset_id<1||$video_id<1||$now<1)return;
        $status=(string)($health['status']??'');
        if(Serving_Viability::PROCESSING===$status)return;
        $failure_since=self::mysql_timestamp($health['failure_since']??null);
        $journal=$this->state->asset($asset_id);

        if(Serving_Viability::HEALTHY===$status){
            if(1!==(int)($health['eligible']??0))return;
            if($journal['failure_since']>0&&array()!==$journal['notified_roles']){
                $this->send_publication_recovery($asset,$journal,$now);
            }elseif($journal['failure_since']>0){
                $this->state->clear_asset($asset_id);
            }
            return;
        }
        if($backend_outage||$failure_since<1)return;
        if($journal['failure_since']!==$failure_since)$journal=array('failure_since'=>$failure_since,'notified_roles'=>array(),'recovered_roles'=>array(),'updated_at'=>0);
        $policy=$this->policy->get();$recipients=$this->recipient_roles($video_id);
        $due=array();
        foreach(array('administrator','publishing_user','origin_author') as $role){
            if(in_array($role,$journal['notified_roles'],true))continue;
            $mode=(string)($policy[$role]??Remote_Health_Notification_Policy_Store::OFF);
            if(Remote_Health_Notification_Policy_Store::due($mode,$failure_since,$now)&&isset($recipients[$role]))$due[$role]=$recipients[$role];
        }
        if(array()===$due)return;
        $sent=$this->deliver_by_role($due,$this->publication_subject($video_id,false),$this->publication_body($asset,$health,$failure_since,false));
        if(array()!==$sent){
            $this->state->mark_asset($asset_id,$failure_since,$sent,$now,false);
            $this->record_email_event($video_id,$asset,$sent,false,$now);
        }
    }

    /** @param array<string,mixed>|null $incident @param array<string,mixed>|null $previous */
    public function backend_observed(string $backend_id, ?array $previous, ?array $incident, int $now): void
    {
        $backend_id=Backend_Identity::sanitize($backend_id);if(''===$backend_id||$now<1)return;
        $journal=$this->state->backend($backend_id);
        if(null===$incident){
            if($journal['failure_since']>0&&in_array('administrator',$journal['notified_roles'],true)){
                $email=$this->administrator_email();
                if(''!==$email&&$this->mail($email,$this->backend_subject($backend_id,true),$this->backend_body($backend_id,$previous,true))){
                    $this->state->clear_backend($backend_id);
                }
            }elseif($journal['failure_since']>0){$this->state->clear_backend($backend_id);}
            return;
        }
        $failure_since=(int)($incident['failure_since']??0);if($failure_since<1)return;
        if($journal['failure_since']!==$failure_since)$journal=array('failure_since'=>$failure_since,'notified_roles'=>array(),'recovered_roles'=>array(),'updated_at'=>0);
        if(in_array('administrator',$journal['notified_roles'],true))return;
        $mode=(string)($this->policy->get()['administrator']??Remote_Health_Notification_Policy_Store::OFF);
        if(!Remote_Health_Notification_Policy_Store::due($mode,$failure_since,$now))return;
        $email=$this->administrator_email();
        if(''!==$email&&$this->mail($email,$this->backend_subject($backend_id,false),$this->backend_body($backend_id,$incident,false))){
            $this->state->mark_backend($backend_id,$failure_since,array('administrator'),$now,false);
        }
    }

    /** @param array<string,mixed> $asset @param array<string,mixed> $journal */
    private function send_publication_recovery(array $asset,array $journal,int $now):void
    {
        $asset_id=(int)$asset['id'];$video_id=(int)$asset['video_post_id'];$recipients=$this->recipient_roles($video_id);$due=array();
        foreach($journal['notified_roles'] as $role){if(!in_array($role,$journal['recovered_roles'],true)&&isset($recipients[$role]))$due[$role]=$recipients[$role];}
        if(array()===$due){$this->state->clear_asset($asset_id);return;}
        $sent=$this->deliver_by_role($due,$this->publication_subject($video_id,true),$this->publication_body($asset,array(),(int)$journal['failure_since'],true));
        if(array()!==$sent){$this->state->mark_asset($asset_id,(int)$journal['failure_since'],$sent,$now,true);$this->record_email_event($video_id,$asset,$sent,true,$now);}
        $after=$this->state->asset($asset_id);if(array_diff($after['notified_roles'],$after['recovered_roles'])===array())$this->state->clear_asset($asset_id);
    }

    /** @return array<string,string> */
    private function recipient_roles(int $video_id):array
    {
        $out=array();$admin=$this->administrator_email();if(''!==$admin)$out['administrator']=$admin;
        $execution=PeerTube_Publication_Execution::sanitize(get_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION,true));
        $operation_id=is_string($execution['operation_id']??null)?$execution['operation_id']:'';
        if(''!==$operation_id){try{$op=($this->operation_reader)($operation_id);}catch(Throwable){$op=null;}$uid=is_array($op)&&is_int($op['created_by']??null)?$op['created_by']:0;$email=$this->user_email($uid);if(''!==$email)$out['publishing_user']=$email;}
        $anchor=Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ORIGIN_POST_ID,true));$post=$anchor>0?get_post($anchor):null;$author=is_object($post)?Video_Meta::sanitize_positive_id($post->post_author??0):0;$email=$this->user_email($author);if(''!==$email)$out['origin_author']=$email;
        return $out;
    }
    private function administrator_email():string{$email=is_string(get_option('admin_email',''))?trim((string)get_option('admin_email','')):'';return self::valid_email($email)?$email:'';}
    private function user_email(int $id):string{if($id<1)return '';$u=get_userdata($id);$email=is_object($u)&&is_string($u->user_email??null)?trim($u->user_email):'';return self::valid_email($email)?$email:'';}
    private static function valid_email(string $email):bool{return ''!==$email&&function_exists('is_email')&&false!==is_email($email);}

    /** @param array<string,string> $roles @return list<string> */
    private function deliver_by_role(array $roles,string $subject,string $body):array
    {
        $groups=array();foreach($roles as $role=>$email){$groups[$email][]=$role;}$sent=array();foreach($groups as $email=>$mapped){if($this->mail($email,$subject,$body))foreach($mapped as $role)$sent[]=$role;}return array_values(array_unique($sent));
    }
    private function mail(string $email,string $subject,string $body):bool{try{return function_exists('wp_mail')&&true===wp_mail($email,$subject,$body);}catch(Throwable){return false;}}

    private function publication_subject(int $video_id,bool $recovered):string{$title=self::video_title($video_id);$site=self::site_name();return sprintf('[%s] Remote video %s: %s',$site,$recovered?'recovered':'needs attention',$title);}
    /** @param array<string,mixed> $asset @param array<string,mixed> $health */
    private function publication_body(array $asset,array $health,int $failure_since,bool $recovered):string
    {
        $video_id=(int)($asset['video_post_id']??0);$backend=(string)($asset['backend_id']??'');$serving=$this->serving->serving_candidate($video_id);$fallback=Backend_Registry::LOCAL_ID===(string)($serving['backend_id']??'')?'WordPress original':((string)($serving['backend_id']??'')?:'no verified fallback');
        $lines=array(
            $recovered
                ? 'ArgentWolf Video Processor (AWVP) detected that a previously unhealthy remote video is serving-eligible again.'
                : 'ArgentWolf Video Processor (AWVP) detected that a remote video is not currently viable for public serving.',
            '',
            'Site: '.self::site_name(),
            'Video: '.self::video_title($video_id).' (#'.$video_id.')',
        );
        foreach($this->affected_post_lines($video_id) as $line){$lines[]=$line;}
        $lines[]='Backend: '.$backend;
        $lines[]='Current serving source: '.$fallback;
        $lines[]='Incident began: '.Operator_Time::format($failure_since,true);
        if(!$recovered){$lines[]='Health state: '.(string)($health['status']??'unknown');$lines[]='Reason: '.(string)($health['message']??'Remote serving check failed.');$http=(int)($health['http_status']??0);if($http>0)$lines[]='HTTP status: '.$http;$lines[]='AWVP will continue periodic public-serving checks and use the highest-priority viable fallback.';}
        return implode("\n",$lines)."\n";
    }
    private function backend_subject(string $backend,bool $recovered):string{return sprintf('[%s] Backend %s %s',self::site_name(),$backend,$recovered?'recovered':'is unavailable');}
    /** @param array<string,mixed>|null $incident */
    private function backend_body(string $backend,?array $incident,bool $recovered):string{$lines=array($recovered?'ArgentWolf Video Processor (AWVP) detected that a remote video backend is responding again.':'ArgentWolf Video Processor (AWVP) detected a backend-wide serving outage.','', 'Site: '.self::site_name(),'Backend: '.$backend);if(!$recovered&&is_array($incident)){$lines[]='Incident began: '.Operator_Time::format((int)$incident['failure_since'],true);$lines[]='Reason: '.(string)$incident['message'];$h=(int)$incident['http_status'];if($h>0)$lines[]='HTTP status: '.$h;$lines[]='Affected videos are using their next viable serving source by priority.';}return implode("\n",$lines)."\n";}

    /** @return list<string> */
    private function affected_post_lines(int $video_id):array
    {
        $attachment_id=Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ATTACHMENT_ID,true));
        $origin_id=Video_Meta::sanitize_positive_id(get_post_meta($video_id,Video_Meta::ORIGIN_POST_ID,true));
        $posts=null!==$this->references?$this->references->posts_for($video_id,$attachment_id,$origin_id):array();
        $lines=array('Affected posts:');
        if(array()===$posts){
            $lines[]='- No referencing WordPress posts were found.';
            return $lines;
        }
        foreach($posts as $post){
            $post_id=(int)($post['id']??0);
            if($post_id<1)continue;
            $title=is_string($post['title']??null)?trim((string)$post['title']):'';
            if(''===$title)$title='Post #'.$post_id;
            $url=function_exists('get_permalink')?get_permalink($post_id):false;
            if((!is_string($url)||''===trim($url))&&function_exists('get_edit_post_link'))$url=get_edit_post_link($post_id,'');
            $line='- '.$title.' (#'.$post_id.')';
            if(is_string($url)&&''!==trim($url))$line.=': '.trim($url);
            $lines[]=$line;
        }
        if(null!==$this->references&&$this->references->truncated())$lines[]='- Additional references may exist beyond the limited site scan.';
        return $lines;
    }
    private static function site_name():string{$v=function_exists('get_bloginfo')?(string)get_bloginfo('name'):'';return ''!==trim($v)?trim($v):'WordPress';}
    private static function video_title(int $video_id):string{$v=$video_id>0?(string)get_the_title($video_id):'';return ''!==trim($v)?trim($v):'Video #'.$video_id;}
    private static function mysql_timestamp(mixed $value):int{if(!is_string($value)||''===$value)return 0;$d=\DateTimeImmutable::createFromFormat('!Y-m-d H:i:s',$value,new \DateTimeZone('UTC'));return false===$d?0:$d->getTimestamp();}
    /** @param list<string> $roles @param array<string,mixed> $asset */
    private function record_email_event(int $video_id,array $asset,array $roles,bool $recovery,int $now):void{if(null===$this->events)return;$this->events->record($video_id,7,$recovery?'serving_health_recovery_email':'serving_health_alert_email','info',$recovery?'AWVP sent remote-serving recovery email.':'AWVP sent remote-serving health alert email.',$now,0,'',(int)($asset['id']??0),(string)($asset['backend_id']??''),0,'','',array('serving_health'=>$recovery?'recovered':'alerted'));}
}
// EOF
