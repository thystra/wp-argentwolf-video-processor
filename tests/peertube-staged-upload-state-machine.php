<?php
/** Focused tests for the resumable staged-upload state contract. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/Backend_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Connection_Input.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Source_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Staged_Upload_State_Machine.php';

use ArgentVideo\PeerTube_Staged_Upload_State_Machine as Machine;

$assert = static function (bool $condition, string $message): void { if (! $condition) { fwrite(STDERR,"FAIL: $message\n"); exit(1); } };
$source = array('kind'=>'wordpress_staging','relative_path'=>'77/staging/source.mp4','sha256'=>str_repeat('a',64),'bytes'=>1500000);
$upload = array('filename'=>'source.mp4','content_type'=>'video/mp4','name'=>'R43 staged fixture','privacy'=>3);
$make = static function(string $id='upload_11111111111111111111111111111111') use($source,$upload,$assert): array {
    $r=Machine::create(array('operation_id'=>$id,'video_post_id'=>77,'backend_id'=>'peertube-primary','origin'=>'https://video.example.org','destination_id'=>'41','source'=>$source,'upload'=>$upload),7,1000);
    $assert(is_array($r),'Could not create upload record.'); return $r;
};
$remote=array('id'=>'901','uuid'=>'12345678-1234-4abc-9def-1234567890ab');
$cap1=str_repeat('1',64); $cap2=str_repeat('2',64); $cap3=str_repeat('3',64);

$r=$make();
$assert(Machine::PHASE_READY===$r['phase'] && 0===$r['confirmed_bytes'] && ''===$r['upload_session_id'],'Initial resumable state drifted.');
$init=Machine::apply($r,Machine::EVENT_CLAIM_UPLOAD,array('attempt_capability'=>$cap1,'request_kind'=>'init','request_start'=>0,'request_bytes'=>0),1001);
$assert(is_array($init)&&Machine::PHASE_UPLOAD_IN_FLIGHT===$init['phase']&&'init'===$init['request_kind'],'Init claim failed.');
$session=Machine::apply($init,Machine::EVENT_UPLOAD_SESSION_CREATED,array('attempt_capability'=>$cap1,'session_id'=>'abcd1234efgh5678'),1002);
$assert(is_array($session)&&Machine::PHASE_READY===$session['phase']&&'abcd1234efgh5678'===$session['upload_session_id'],'Session commit failed.');

$chunk1=Machine::apply($session,Machine::EVENT_CLAIM_UPLOAD,array('attempt_capability'=>$cap2,'request_kind'=>'chunk','request_start'=>0,'request_bytes'=>Machine::MAX_CHUNK_BYTES),1003);
$assert(is_array($chunk1),'First chunk claim failed.');
$accepted=Machine::apply($chunk1,Machine::EVENT_UPLOAD_CHUNK_ACCEPTED,array('attempt_capability'=>$cap2,'confirmed_bytes'=>Machine::MAX_CHUNK_BYTES),1004);
$assert(is_array($accepted)&&Machine::PHASE_READY===$accepted['phase']&&Machine::MAX_CHUNK_BYTES===$accepted['confirmed_bytes'],'Chunk acknowledgement failed.');

$remaining=$source['bytes']-Machine::MAX_CHUNK_BYTES;
$final=Machine::apply($accepted,Machine::EVENT_CLAIM_UPLOAD,array('attempt_capability'=>$cap3,'request_kind'=>'chunk','request_start'=>Machine::MAX_CHUNK_BYTES,'request_bytes'=>$remaining),1005);
$assert(is_array($final),'Final chunk claim failed.');
$uncertain=Machine::apply($final,Machine::EVENT_UPLOAD_INDETERMINATE,array('attempt_capability'=>$cap3,'code'=>'peertube.upload.indeterminate','http_status'=>0),1006);
$assert(is_array($uncertain)&&Machine::PHASE_UPLOAD_INDETERMINATE===$uncertain['phase'],'Uncertain final chunk not fenced.');
$assert(null===Machine::apply($uncertain,Machine::EVENT_CLAIM_UPLOAD,array('attempt_capability'=>str_repeat('4',64),'request_kind'=>'chunk','request_start'=>Machine::MAX_CHUNK_BYTES,'request_bytes'=>$remaining),1007),'Uncertain final chunk could replay silently.');

// A zero-byte resume probe can prove that the uncertain final chunk was not
// received, after which a new explicit request may claim it again.
$recovered=Machine::apply($uncertain,Machine::EVENT_RECONCILE_OFFSET,array('confirmed_bytes'=>Machine::MAX_CHUNK_BYTES),1007);
$assert(is_array($recovered)&&Machine::PHASE_READY===$recovered['phase'],'Offset reconciliation did not reopen exact retry.');
$retry=Machine::apply($recovered,Machine::EVENT_CLAIM_UPLOAD,array('attempt_capability'=>str_repeat('4',64),'request_kind'=>'chunk','request_start'=>Machine::MAX_CHUNK_BYTES,'request_bytes'=>$remaining),1008);
$created=Machine::apply($retry,Machine::EVENT_REMOTE_CREATED,array('attempt_capability'=>str_repeat('4',64),'remote_identity'=>$remote),1009);
$assert(is_array($created)&&Machine::PHASE_REMOTE_CREATED===$created['phase']&&$source['bytes']===$created['confirmed_bytes'],'Final remote identity commit failed.');

// If reconciliation instead returns the final identity, no chunk replay is needed.
$final2=Machine::apply($accepted,Machine::EVENT_CLAIM_UPLOAD,array('attempt_capability'=>$cap3,'request_kind'=>'chunk','request_start'=>Machine::MAX_CHUNK_BYTES,'request_bytes'=>$remaining),1010);
$final2=Machine::apply($final2,Machine::EVENT_UPLOAD_INDETERMINATE,array('attempt_capability'=>$cap3,'code'=>'peertube.upload.indeterminate','http_status'=>0),1011);
$found=Machine::apply($final2,Machine::EVENT_RECONCILE_REMOTE_FOUND,array('remote_identity'=>$remote),1012);
$assert(is_array($found)&&Machine::PHASE_REMOTE_CREATED===$found['phase'],'Remote-found reconciliation failed.');

// Init 429 is retry-safe only because a definite initiation rejection cannot
// create a video. Chunk 429 remains uncertain and is not accepted here.
$rate=$make('upload_22222222222222222222222222222222');
$rate=Machine::apply($rate,Machine::EVENT_CLAIM_UPLOAD,array('attempt_capability'=>$cap1,'request_kind'=>'init','request_start'=>0,'request_bytes'=>0),1100);
$rate=Machine::apply($rate,Machine::EVENT_UPLOAD_RETRY_SAFE,array('attempt_capability'=>$cap1,'code'=>'peertube.upload.rate_limited','http_status'=>429,'retry_after'=>30),1101);
$assert(is_array($rate)&&Machine::PHASE_RETRY_WAIT===$rate['phase'],'Init 429 did not enter durable wait.');
$assert(null===Machine::apply($rate,Machine::EVENT_RESUME_AFTER_WAIT,array(),1130),'Wait resumed early.');
$rate=Machine::apply($rate,Machine::EVENT_RESUME_AFTER_WAIT,array(),1131);
$assert(is_array($rate)&&Machine::PHASE_READY===$rate['phase'],'Wait did not reopen ready state.');

$bad=$upload; $bad['privacy']=1;
$assert(null===Machine::create(array('operation_id'=>'upload_33333333333333333333333333333333','video_post_id'=>77,'backend_id'=>'peertube-primary','origin'=>'https://video.example.org','destination_id'=>'41','source'=>$source,'upload'=>$bad),7,1200),'Public upload intent was accepted.');
$poison=$upload; $poison['access_token']='canary';
$assert(null===Machine::create(array('operation_id'=>'upload_44444444444444444444444444444444','video_post_id'=>77,'backend_id'=>'peertube-primary','origin'=>'https://video.example.org','destination_id'=>'41','source'=>$source,'upload'=>$poison),7,1200),'Credential-like upload metadata was accepted.');

fwrite(STDOUT,"PeerTube resumable staged-upload state-machine tests passed.\n");
