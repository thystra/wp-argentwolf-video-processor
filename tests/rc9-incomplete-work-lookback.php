<?php
/** Dependency-free RC9 incomplete-work recovery-window tests. */
declare(strict_types=1);

namespace ArgentVideo {
    $GLOBALS['awvp_rc9_meta'] = array();
    $GLOBALS['awvp_rc9_posts'] = array();
    $GLOBALS['awvp_rc9_ids'] = array();

    final class Video_Post_Type { public const POST_TYPE='argent_video'; }
    final class Video_Meta {
        public const PEERTUBE_PUBLICATION_LIFECYCLE='_lifecycle';
        public const PEERTUBE_RECOVERY_WINDOW='_recovery';
        public static function sanitize_positive_id(mixed $v):int { return is_int($v)&&$v>0?$v:(is_string($v)&&ctype_digit($v)&&(int)$v>0?(int)$v:0); }
        public static function sanitize_peertube_recovery_window(mixed $v):array {
            if(!is_array($v)||array('version','origin_at','resumed_at')!==array_keys($v)||1!==($v['version']??null)||!is_int($v['origin_at']??null)||$v['origin_at']<1||!is_int($v['resumed_at']??null)||$v['resumed_at']<0||($v['resumed_at']>0&&$v['resumed_at']<$v['origin_at']))return array();
            return $v;
        }
    }
    final class PeerTube_Publication_Lifecycle { public static function sanitize(mixed $v):array { return is_array($v)?$v:array(); } }
    final class Task_Repository { public const APPLIED='applied'; public const PRESENT='present'; }
    class PeerTube_Publication_Synchronizer {
        public array $calls=array();
        public string $status=Task_Repository::APPLIED;
        public function sync_video(int $id,string $status,int $now):array { $this->calls[]=array($id,$status,$now); return array('status'=>$this->status); }
    }
    class PeerTube_Serving_Cutover_Service {
        public const APPLIED='applied'; public const PRESENT='present'; public const LOCAL='local'; public const REFUSED='refused'; public const INDETERMINATE='indeterminate';
        public array $calls=array(); public array $statuses=array();
        public function reconcile(int $id,int $now):string { $this->calls[]=array($id,$now); return $this->statuses[$id]??self::REFUSED; }
    }
    class PeerTube_Publication_Finalizer_Recovery {
        public const NONE='none'; public const RESUMABLE='resumable'; public const MISSING='missing'; public const TERMINAL='terminal';
        public array $statuses=array(); public array $resume_calls=array(); public array $restore_calls=array(); public string $resume_status=Task_Repository::APPLIED; public string $restore_status=Task_Repository::APPLIED;
        public function status(int $id):array { return $this->statuses[$id]??array('status'=>self::NONE,'task_id'=>0,'generation'=>0,'anchor_post_id'=>0); }
        public function resume(int $id,int $now):string { $this->resume_calls[]=array($id,$now); return $this->resume_status; }
        public function restore_missing(int $id,int $now):string { $this->restore_calls[]=array($id,$now); $this->statuses[$id]=array('status'=>self::NONE,'task_id'=>77,'generation'=>3,'anchor_post_id'=>(int)($this->statuses[$id]['anchor_post_id']??0)); return $this->restore_status; }
    }
    function get_post_meta(int $id,string $key,bool $single=true):mixed { unset($single); return $GLOBALS['awvp_rc9_meta'][$id][$key]??''; }
    function metadata_exists(string $type,int $id,string $key):bool { unset($type); return array_key_exists($key,$GLOBALS['awvp_rc9_meta'][$id]??array()); }
    function update_post_meta(int $id,string $key,mixed $value):bool { $GLOBALS['awvp_rc9_meta'][$id][$key]=$value; return true; }
    function delete_post_meta(int $id,string $key):bool { unset($GLOBALS['awvp_rc9_meta'][$id][$key]); return true; }
    function get_posts(array $args):array { unset($args); return $GLOBALS['awvp_rc9_ids']; }
    function get_post(int $id):object|false { return $GLOBALS['awvp_rc9_posts'][$id]??false; }
}

namespace {
    require_once dirname(__DIR__).'/includes/PeerTube_Incomplete_Work_Reconciler.php';
    use ArgentVideo\PeerTube_Incomplete_Work_Reconciler;
    use ArgentVideo\PeerTube_Publication_Synchronizer;
    use ArgentVideo\PeerTube_Serving_Cutover_Service;
    use ArgentVideo\PeerTube_Publication_Finalizer_Recovery;
    use ArgentVideo\Video_Meta;

    $assert=static function(bool $ok,string $m):void{if(!$ok){fwrite(STDERR,"FAIL: {$m}\n");exit(1);}};
    $pending=static function(int $updated,int $anchor=10):array{return array('task_pending'=>true,'updated_at'=>$updated,'anchor_post_id'=>$anchor);};

    $sync=new PeerTube_Publication_Synchronizer();
    $r=new PeerTube_Incomplete_Work_Reconciler($sync);
    $GLOBALS['awvp_rc9_meta'][101][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]=$pending(1000);

    $initial=$r->status(101,1000+86400,true);
    $assert($initial['eligible']&&!$initial['resumable']&&1000===$initial['origin_at'],'Initial 24-hour recovery window drifted.');
    $attention=$r->status(101,1000+86401,true);
    $assert(!$attention['eligible']&&$attention['resumable']&&'needs_attention'===$attention['status'],'24-hour expiry did not require explicit Resume.');
    $assert($r->resume(101,1000+90000),'Explicit Resume did not open a fresh recovery window.');
    $resumed=$r->status(101,1000+90000,true);
    $assert($resumed['eligible']&&!$resumed['resumable']&&1000+90000+86400===$resumed['expires_at'],'Resume did not create a fresh bounded 24-hour window.');

    // Repeated lifecycle timestamps cannot extend the hard maximum because the
    // persisted recovery origin remains the first observed incomplete point.
    $GLOBALS['awvp_rc9_meta'][101][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]=$pending(500000);
    $expired=$r->status(101,1000+604801,true);
    $assert('expired'===$expired['status']&&!$expired['eligible']&&!$expired['resumable']&&1000===$expired['origin_at'],'Repeated reconciliation extended the 168-hour hard limit.');

    // Eligible recovery replays only the local publication synchronizer. It has
    // no staged-upload operation or remote mutation authority.
    $GLOBALS['awvp_rc9_meta'][102][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]=$pending(2000,11);
    $GLOBALS['awvp_rc9_posts'][11]=(object)array('post_status'=>'publish');
    $GLOBALS['awvp_rc9_ids']=array(102);
    $count=$r->recover(2100);
    $assert(1===$count&&array(array(102,'publish',2100))===$sync->calls,'Eligible incomplete lifecycle was not reconciled exactly once.');
    $assert(!isset($GLOBALS['awvp_rc9_meta'][102][Video_Meta::PEERTUBE_RECOVERY_WINDOW]),'Successful recovery retained stale window metadata.');

    // RC10 live-upgrade fixture: RC9 already consumed the lifecycle enqueue
    // (task_pending=false), but remote publication evidence is complete and only
    // local serving authority remains missing. Recovery must invoke the local-only
    // cutover path without requiring publication synchronization.
    $sync2=new PeerTube_Publication_Synchronizer();
    $cutover=new PeerTube_Serving_Cutover_Service();
    $cutover->statuses[103]=PeerTube_Serving_Cutover_Service::APPLIED;
    $r2=new PeerTube_Incomplete_Work_Reconciler($sync2,$cutover);
    $GLOBALS['awvp_rc9_meta'][103][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]=array('task_pending'=>false,'updated_at'=>3000,'anchor_post_id'=>12);
    $GLOBALS['awvp_rc9_ids']=array(103);
    $count2=$r2->recover(3100);
    $assert(1===$count2&&array(array(103,3100))===$cutover->calls,'RC10 recovery did not reconcile verified local-only cutover with task_pending=false.');
    $assert(array()===$sync2->calls,'RC10 local-only cutover recovery unnecessarily invoked publication synchronization.');

    // RC12 live-test fixture: the upload is ready and a finalizer proved that no
    // provider mutation was sent. This is an explicit, operator-resumable local
    // retry boundary even though lifecycle task_pending is already false.
    $sync3=new PeerTube_Publication_Synchronizer();
    $retry=new PeerTube_Publication_Finalizer_Recovery();
    $retry->statuses[104]=array('status'=>PeerTube_Publication_Finalizer_Recovery::RESUMABLE,'task_id'=>29,'generation'=>2,'anchor_post_id'=>13);
    $r3=new PeerTube_Incomplete_Work_Reconciler($sync3,null,$retry);
    $GLOBALS['awvp_rc9_meta'][104][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]=array('task_pending'=>false,'updated_at'=>4000,'anchor_post_id'=>13);
    $retry_state=$r3->status(104,4100,false);
    $assert('finalizer_retry'===($retry_state['status']??'')&&true===($retry_state['pending']??false)&&true===($retry_state['resumable']??false),'RC12 safe failed finalizer was not exposed as resumable incomplete work.');
    $assert($r3->resume(104,4101)&&array(array(104,4101))===$retry->resume_calls,'RC12 resume did not requeue the exact safe failed finalizer.');
    $assert(array()===$sync3->calls,'RC12 safe finalizer retry incorrectly manufactured a new publication synchronization generation.');


    // RC13.1/Test-8 fixture: task_pending is false because the generation-3 sync
    // task itself completed, but the current reveal-authorized generation has no
    // finalizer. Overview must surface it and the periodic reconciler may safely
    // restore only the missing finalizer task without manufacturing generation 4.
    $sync4=new PeerTube_Publication_Synchronizer();
    $retry4=new PeerTube_Publication_Finalizer_Recovery();
    $retry4->statuses[105]=array('status'=>PeerTube_Publication_Finalizer_Recovery::MISSING,'task_id'=>0,'generation'=>3,'anchor_post_id'=>14);
    $r4=new PeerTube_Incomplete_Work_Reconciler($sync4,null,$retry4);
    $GLOBALS['awvp_rc9_meta'][105][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]=array('task_pending'=>false,'updated_at'=>5000,'anchor_post_id'=>14);
    $missing_state=$r4->status(105,5100,false);
    $assert('finalizer_missing'===($missing_state['status']??'')&&true===($missing_state['pending']??false)&&true===($missing_state['eligible']??false)&&false===($missing_state['resumable']??true),'RC13.1 missing current-generation finalizer was not surfaced as automatically recoverable incomplete work.');
    $GLOBALS['awvp_rc9_ids']=array(105);
    $count4=$r4->recover(5101);
    $assert(1===$count4&&array(array(105,5101))===$retry4->restore_calls,'RC13.1 recovery did not restore the missing current-generation finalizer exactly once.');
    $assert(array()===$sync4->calls,'RC13.1 missing-finalizer recovery incorrectly manufactured a new publication sync/generation.');

    // Terminal current-generation finalizer work must remain visible but must not
    // be auto-replayed because its provider-mutation outcome may be unsafe.
    $sync5=new PeerTube_Publication_Synchronizer();
    $retry5=new PeerTube_Publication_Finalizer_Recovery();
    $retry5->statuses[106]=array('status'=>PeerTube_Publication_Finalizer_Recovery::TERMINAL,'task_id'=>88,'generation'=>3,'anchor_post_id'=>15);
    $r5=new PeerTube_Incomplete_Work_Reconciler($sync5,null,$retry5);
    $GLOBALS['awvp_rc9_meta'][106][Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE]=array('task_pending'=>false,'updated_at'=>6000,'anchor_post_id'=>15);
    $terminal_state=$r5->status(106,6100,false);
    $assert('finalizer_terminal'===($terminal_state['status']??'')&&true===($terminal_state['pending']??false)&&false===($terminal_state['eligible']??true)&&false===($terminal_state['resumable']??true),'Terminal finalizer gap was hidden or incorrectly made replayable.');

    $source=(string)file_get_contents(dirname(__DIR__).'/includes/PeerTube_Incomplete_Work_Reconciler.php');
    foreach(array('PeerTube_Staged_Upload_Operation_Store','upload_indeterminate','update_publication(','resumable_upload') as $forbidden){$assert(!str_contains($source,$forbidden),'Incomplete-work reconciler acquired remote mutation authority: '.$forbidden);}
    $assert(str_contains($source,'DEFAULT_WINDOW_SECONDS = 86400')&&str_contains($source,'MAX_WINDOW_SECONDS = 604800'),'RC9 24h/168h recovery constants drifted.');
    $assert(str_contains($source,'MAX_SCAN = 250'),'RC9 bounded recovery scan limit drifted from 250 videos.');
    $assert(!str_contains($source,"'meta_key'"),'RC9 recovery scan reintroduced a slow postmeta SQL filter.');

    fwrite(STDOUT,"RC9 incomplete-work lookback tests passed.\n");
}
