<?php
/**
 * File: includes/PeerTube_Publication_Task_Coordinator.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use Closure;
use InvalidArgumentException;
use Throwable;

/**
 * R46.5b detached publication execution.
 *
 * The sync task prepares/reuses a private staged upload and queues the existing
 * upload coordinator plus a later publication finalizer. The finalizer is the
 * only R46.5b path that may mutate video metadata/privacy and it generation-
 * fences WordPress authority immediately before and after non-private reveal.
 */
final class PeerTube_Publication_Task_Coordinator
{
    public const TASK_SYNC = PeerTube_Publication_Synchronizer::TASK_TYPE;
    public const TASK_FINALIZE = 'peertube_publication_finalize';
    public const PAYLOAD_VERSION = 1;
    public const STATUS_COMPLETE = 'complete';
    public const STATUS_REQUEUED = 'requeued';
    public const STATUS_FAILED = 'failed';
    public const STATUS_INDETERMINATE = 'indeterminate';
    public const LOCK_SECONDS = 180;

    private const LOCK_PREFIX = 'argent_video_processor_publication_execution_lock_';
    private const TOKEN_SKEW_SECONDS = 60;

    /** @var Closure(string):PeerTube_Publication_Mutation_Api */
    private Closure $api_factory;

    public function __construct(
        private readonly Task_Repository $tasks,
        private readonly PeerTube_Staged_Upload_Operation_Store $operations,
        private readonly PeerTube_Staged_Upload_Service $upload,
        private readonly PeerTube_Upload_Task_Coordinator $upload_tasks,
        private readonly PeerTube_Publication_Staging_Service $staging,
        private readonly PeerTube_Publication_Asset_Store $assets,
        private readonly Backend_Registry $registry,
        private readonly Managed_Backend_Secret_Store $secrets,
        private readonly PeerTube_Publication_Catalog_Store $catalogs,
        private readonly Video_Publishing_Defaults_Store $defaults,
        callable $api_factory,
        private readonly ?PeerTube_Serving_Cutover_Service $cutover = null,
        private readonly ?PeerTube_Derivative_Cleanup_Service $derivative_cleanup = null,
        private readonly ?PeerTube_Publication_Authority_Repair $authority_repair = null,
        private readonly ?Remote_Publication_Health_Service $publication_health = null
    ) {
        $this->api_factory = Closure::fromCallable($api_factory);
    }

    /** @param array<string,mixed> $task @return array<string,mixed> */
    public function advance_claimed(array $task, int $now): array
    {
        $task_id = self::positive_int($task['id'] ?? null);
        $task_type = is_string($task['task_type'] ?? null) ? $task['task_type'] : '';
        $video_id = self::positive_int($task['video_post_id'] ?? null);
        $lock_token = is_string($task['lock_token'] ?? null) ? $task['lock_token'] : '';
        $payload = self::payload($task['payload_json'] ?? null);
        if ($task_id < 1 || $video_id < 1 || $now < 1 || ! in_array($task_type,array(self::TASK_SYNC,self::TASK_FINALIZE),true)
            || 1 !== preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$lock_token) || null === $payload) {
            return self::result(self::STATUS_INDETERMINATE,$task_id,$task_type,Task_Repository::CONFLICT);
        }

        $lock = $this->acquire_lock($video_id,$now);
        if (null === $lock) {
            return $this->reschedule($task_id,$task_type,$lock_token,$now + 30,'Publication execution is already active for this video.',$now);
        }
        try {
            return self::TASK_SYNC === $task_type
                ? $this->advance_sync($task,$payload,$now)
                : $this->advance_finalize($task,$payload,$now);
        } finally {
            $this->release_lock($video_id,$lock);
        }
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $payload */
    private function advance_sync(array $task,array $payload,int $now): array
    {
        $task_id=(int)$task['id']; $video_id=(int)$task['video_post_id']; $lock=(string)$task['lock_token'];
        $state=$this->current_authority($video_id,$task,$payload,false,$now);
        if ('stale' === $state['status'] || 'inactive' === $state['status']) {
            return $this->complete($task_id,self::TASK_SYNC,$lock,$now,$state['status']);
        }
        if ('ready' !== $state['status']) {
            return self::transient_authority((string) $state['status'])
                ? $this->reschedule($task_id,self::TASK_SYNC,$lock,$now+60,'Publication sync is waiting for the PeerTube server connection, credentials, or publishing choices to become current.',$now)
                : $this->fail($task_id,self::TASK_SYNC,$lock,'Publication sync checks failed because the current WordPress or PeerTube state no longer matches this task.',$now,(string)$state['status']);
        }
        if (true !== $state['lifecycle']['upload_authorized']) return $this->complete($task_id,self::TASK_SYNC,$lock,$now,'inactive');

        $manifest=PeerTube_Publication_Manifest::build($state['plan'],$state['catalog'],$state['defaults']);
        if (array()===$manifest) return $this->fail($task_id,self::TASK_SYNC,$lock,'Reviewed publication choices could not be frozen against current provider state.',$now,'manifest_refused');

        $execution=$this->load_execution($video_id,true);
        $execution_before=null;
        if (null === $execution) {
            $execution=PeerTube_Publication_Execution::create($manifest,$now);
        } elseif (array()===$execution) {
            return $this->fail($task_id,self::TASK_SYNC,$lock,'Stored publication execution state is malformed or future-version.',$now,'execution_refused');
        } else {
            $execution_before=$execution;
            $execution=PeerTube_Publication_Execution::with_manifest($execution,$manifest,$now);
        }
        if (array()===$execution || ! $this->save_execution($video_id,$execution,$execution_before)) {
            return $this->fail($task_id,self::TASK_SYNC,$lock,'Publication execution state could not be committed.',$now,'execution_indeterminate');
        }

        if ('' === $execution['operation_id']) {
            $staged=$this->staging->stage($video_id);
            if ('ready' !== ($staged['status'] ?? null)) {
                return $this->fail($task_id,self::TASK_SYNC,$lock,'The authoritative WordPress video source could not be staged for PeerTube upload.',$now,(string)($staged['status']??'staging_refused'));
            }
            $actor_id = self::positive_int(get_current_user_id());
            if ($actor_id < 1) {
                $actor_id = self::positive_int((string) get_post_field('post_author',(int)$manifest['anchor_post_id']));
            }
            if ($actor_id < 1) {
                return $this->fail($task_id,self::TASK_SYNC,$lock,'A positive WordPress actor could not be established for the private upload journal.',$now,'actor_refused');
            }
            $begun=$this->upload->begin(
                $video_id,
                (string)$manifest['backend_id'],
                (string)$staged['path'],
                (string)$manifest['title'],
                $actor_id,
                $now,
                (string)$manifest['channel_id'],
                (string)$staged['content_type']
            );
            if (PeerTube_Staged_Upload_Service::STATUS_ADVANCED !== ($begun['status']??null)
                || 1 !== preg_match('/^upload_[a-f0-9]{32}$/D',(string)($begun['operation_id']??''))) {
                return $this->fail($task_id,self::TASK_SYNC,$lock,'Private staged upload could not be established.',$now,(string)($begun['status']??'upload_begin_refused'));
            }
            $execution_before=$execution;
            $execution=PeerTube_Publication_Execution::with_operation($execution,(string)$begun['operation_id'],$now);
            if (array()===$execution || ! $this->save_execution($video_id,$execution,$execution_before)) {
                return $this->fail($task_id,self::TASK_SYNC,$lock,'Staged upload identity could not be persisted.',$now,'execution_indeterminate');
            }
        }

        $queued=$this->upload_tasks->enqueue_upload((string)$execution['operation_id'],$now);
        if (! in_array($queued['status']??null,array(Task_Repository::APPLIED,Task_Repository::PRESENT),true)) {
            return $this->reschedule($task_id,self::TASK_SYNC,$lock,$now+60,'Waiting to save the next private upload continuation task.',$now);
        }
        $finalize=$this->enqueue_finalize($video_id,$state['lifecycle'],$now);
        if (! in_array($finalize['status']??null,array(Task_Repository::APPLIED,Task_Repository::PRESENT),true)) {
            return $this->reschedule($task_id,self::TASK_SYNC,$lock,$now+60,'Waiting to save the publication finalization task.',$now);
        }
        return $this->complete($task_id,self::TASK_SYNC,$lock,$now,'private_upload_handoff');
    }

    /** @param array<string,mixed> $task @param array<string,mixed> $payload */
    private function advance_finalize(array $task,array $payload,int $now): array
    {
        $task_id=(int)$task['id']; $video_id=(int)$task['video_post_id']; $lock=(string)$task['lock_token'];
        $retry_lifecycle=$this->lifecycle_for_payload($video_id,$payload);
        if (null !== $this->cutover && is_array($retry_lifecycle) && true === ($retry_lifecycle['reveal_authorized'] ?? false)) {
            $existing=$this->load_execution($video_id,true);
            if (is_array($existing) && array()!==$existing && ''!==(string)$existing['applied_manifest_sha256']
                && hash_equals((string)$existing['manifest_sha256'],(string)$existing['applied_manifest_sha256'])
                && $this->asset_matches_applied($existing,(string)$retry_lifecycle['target_privacy_id'])) {
                return $this->finish_cutover($task_id,$lock,$video_id,$now,'cutover_retry',$payload,(string)$retry_lifecycle['target_privacy_id'],(int)$existing['anchor_post_id']);
            }
        }
        $state=$this->current_authority($video_id,$task,$payload,true,$now);
        if ('stale' === $state['status'] || 'inactive' === $state['status']) return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,$state['status']);
        if ('ready' !== $state['status']) {
            return self::transient_authority((string) $state['status'])
                ? $this->reschedule($task_id,self::TASK_FINALIZE,$lock,$now+60,'Publication finalization is waiting for the PeerTube server connection, credentials, publishing choices, or API client to become current.',$now)
                : $this->fail($task_id,self::TASK_FINALIZE,$lock,'Publication finalization checks failed because the current WordPress or PeerTube state no longer matches this task.',$now,(string)$state['status']);
        }
        if (true !== $state['lifecycle']['upload_authorized']) return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'inactive');

        $execution=$this->load_execution($video_id,true);
        if (! is_array($execution) || array()===$execution || ''===$execution['operation_id']) return $this->fail($task_id,self::TASK_FINALIZE,$lock,'Publication execution/upload identity is unavailable.',$now,'execution_refused');
        $operation=$this->operations->get((string)$execution['operation_id']);
        if (! is_array($operation) || ! PeerTube_Staged_Upload_State_Machine::valid($operation)) return $this->fail($task_id,self::TASK_FINALIZE,$lock,'Staged upload journal is unavailable.',$now,'operation_refused');
        if (PeerTube_Staged_Upload_State_Machine::PHASE_UPLOAD_INDETERMINATE === $operation['phase']) {
            return $this->fail($task_id,self::TASK_FINALIZE,$lock,'PeerTube upload is waiting at an explicit intervention boundary; publication finalization will not poll or replay it automatically.',$now,'upload_intervention_required');
        }
        if (PeerTube_Staged_Upload_State_Machine::PHASE_OPERATOR_ABANDONED === $operation['phase']) {
            // The administrator has explicitly retired this unreconcilable
            // initialization after confirming that no matching remote video
            // exists. This generation is intentionally finished locally; a
            // later explicit Republish owns any new remote publication.
            return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'upload_operator_abandoned');
        }
        if (PeerTube_Staged_Upload_State_Machine::PHASE_FAILED === $operation['phase']) return $this->fail($task_id,self::TASK_FINALIZE,$lock,'PeerTube upload/reconciliation failed before publication finalization.',$now,'upload_failed');
        if (PeerTube_Staged_Upload_State_Machine::PHASE_READY_VERIFIED !== $operation['phase']) {
            return $this->reschedule($task_id,self::TASK_FINALIZE,$lock,$now+60,'Waiting for the private PeerTube copy to become ready and verified.',$now);
        }
        if ((int)$operation['remote_asset_id']<1 || ''===(string)$operation['remote_identity']['uuid']) return $this->fail($task_id,self::TASK_FINALIZE,$lock,'Verified upload is missing the remote video identity needed to continue.',$now,'remote_identity_refused');
        if (null===$this->lifecycle_for_payload($video_id,$payload)) {
            return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale');
        }
        $execution_before=$execution;
        $execution=PeerTube_Publication_Execution::with_remote($execution,(int)$operation['remote_asset_id'],(string)$operation['remote_identity']['uuid'],$now);
        if (array()===$execution || ! $this->save_execution($video_id,$execution,$execution_before)) {
            return null===$this->lifecycle_for_payload($video_id,$payload)
                ? $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale')
                : $this->fail($task_id,self::TASK_FINALIZE,$lock,'Remote asset identity could not be persisted into publication execution.',$now,'execution_indeterminate');
        }

        $manifest=$execution['manifest'];
        if ((string)$manifest['plan_sha256'] !== (string)$state['lifecycle']['plan_sha256'] || ! $this->manifest_provider_valid($manifest,$state['catalog'])) {
            return $this->fail($task_id,self::TASK_FINALIZE,$lock,'The reviewed PeerTube publication settings no longer match the current server choices.',$now,'manifest_context_changed');
        }
        if (! self::clear_transition_safe($execution['applied_manifest'],$manifest)) {
            return $this->fail($task_id,self::TASK_FINALIZE,$lock,'Publication edit requires an unsupported destructive provider clear operation.',$now,'clear_unsupported');
        }

        $target=(string)$state['lifecycle']['target_privacy_id'];
        $post=self::fresh_post((int)$manifest['anchor_post_id']);
        if (! is_object($post)) return $this->fail($task_id,self::TASK_FINALIZE,$lock,'WordPress anchor post is unavailable.',$now,'anchor_missing');
        if (! self::lifecycle_allows_target_state($state['lifecycle'], $target, (string) ($post->post_status ?? ''))) {
            return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'reveal_superseded');
        }

        if ('' !== (string)$execution['applied_manifest_sha256']
            && hash_equals((string)$execution['manifest_sha256'],(string)$execution['applied_manifest_sha256'])
            && $this->asset_matches_applied($execution,$target)) {
            return $this->finish_cutover($task_id,$lock,$video_id,$now,'already_verified',$payload,$target,(int)$manifest['anchor_post_id']);
        }

        $api=$state['api']; $secret=$state['secret'];
        $thumbnail=null;
        if ((int)$manifest['thumbnail_attachment_id']>0) {
            $thumbnail_capture=PeerTube_Publication_Thumbnail::capture((int)$manifest['thumbnail_attachment_id']);
            if (null===$thumbnail_capture
                || ! hash_equals((string)$manifest['thumbnail_sha256'],(string)$thumbnail_capture['sha256'])
                || (int)$manifest['thumbnail_bytes'] !== (int)$thumbnail_capture['bytes']
                || (string)$manifest['thumbnail_mime'] !== (string)$thumbnail_capture['mime']) {
                return $this->fail($task_id,self::TASK_FINALIZE,$lock,'Selected PeerTube thumbnail changed or is unreadable.',$now,'thumbnail_changed');
            }
            $thumbnail=array(
                'filename'=>basename((string)$thumbnail_capture['relative_path']),
                'mime'=>(string)$thumbnail_capture['mime'],
                'content'=>(string)$thumbnail_capture['content'],
            );
        }
        // Read before the consequential PUT. A prior worker may have completed
        // the provider mutation and crashed before persisting local evidence.
        // Skip replay only when the GET proves the complete desired publication
        // state; privacy/channel alone are intentionally insufficient.
        if ($this->remote_matches_manifest($api,(string)$secret['access_token'],$execution,$manifest,$target)) {
            if (null===$this->finalizer_fence($video_id,$payload,$target,(int)$manifest['anchor_post_id'])) {
                return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale');
            }
            $asset_status=$this->assets->record_publication_observation((int)$execution['remote_asset_id'],$video_id,(string)$execution['backend_id'],(string)$execution['remote_uuid'],(string)$execution['channel_id'],$target,$now);
            if (! in_array($asset_status,array(PeerTube_Remote_Asset_Store::APPLIED,PeerTube_Remote_Asset_Store::PRESENT),true)
                || ! $this->asset_matches_applied($execution,$target)) {
                return $this->fail($task_id,self::TASK_FINALIZE,$lock,'Existing PeerTube publication was verified but local asset observation was indeterminate.',$now,'asset_indeterminate');
            }
            if (true !== ($state['lifecycle']['reveal_authorized'] ?? false)) {
                if(null!==$this->cutover)$this->cutover->reconcile($video_id,$now);
                return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'3' === $target ? 'staged_private' : 'staged_prepublication');
            }
            if (null===$this->finalizer_fence($video_id,$payload,$target,(int)$manifest['anchor_post_id'])) {
                return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale');
            }
            $execution_before=$execution;
            $execution=PeerTube_Publication_Execution::mark_applied($execution,$manifest,$now);
            if (array()===$execution || ! $this->save_execution($video_id,$execution,$execution_before)) {
                return null===$this->finalizer_fence($video_id,$payload,$target,(int)$manifest['anchor_post_id'])
                    ? $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale')
                    : $this->fail($task_id,self::TASK_FINALIZE,$lock,'The already-applied PeerTube publication settings could not be recorded locally.',$now,'execution_indeterminate');
            }
            return $this->finish_cutover($task_id,$lock,$video_id,$now,'verified_existing',$payload,$target,(int)$manifest['anchor_post_id']);
        }

        if (null===$this->finalizer_fence($video_id,$payload,$target,(int)$manifest['anchor_post_id'])) {
            return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale');
        }
        try {
            $updated=$api->update_publication((string)$secret['access_token'],(string)$execution['remote_uuid'],$manifest,$target,$thumbnail);
        } catch (InvalidArgumentException) {
            $message='PeerTube publication update was refused locally before transmission; an explicit retry is safe after correcting the input.';
            return self::with_operator_event($this->fail($task_id,self::TASK_FINALIZE,$lock,$message,$now,'mutation_not_sent'),0,'invalid_input',$message);
        } catch (Throwable) {
            $message='PeerTube publication update ended without a definitive provider response; automatic replay is refused.';
            return self::with_operator_event($this->fail($task_id,self::TASK_FINALIZE,$lock,$message,$now,'mutation_indeterminate'),0,'transport_error',$message);
        }
        if (true !== ($updated['ok']??false)) {
            $error=is_array($updated['error']??null)?$updated['error']:array();
            $code=is_string($error['code']??null)?$error['code']:'';
            if ('publication_update_input_invalid'===$code) {
                $message='PeerTube publication update was refused locally before transmission; an explicit retry is safe after correcting the input.';
                return self::with_operator_event($this->fail($task_id,self::TASK_FINALIZE,$lock,$message,$now,'mutation_not_sent'),0,'invalid_input',$message);
            }
            $http_status=self::http_status($error['http_status']??null);
            $error_status=self::machine_error_status($error['status']??null);
            if ($http_status >= 400 && $http_status < 500) {
                $message='PeerTube rejected the publication update with HTTP '.$http_status;
                if ('' !== $error_status) {
                    $message.=' ('.$error_status.')';
                }
                $message.='; no successful mutation is assumed, and an explicit retry is safe after the cause is corrected.';
                return self::with_operator_event($this->fail($task_id,self::TASK_FINALIZE,$lock,$message,$now,'mutation_rejected'),$http_status,$error_status,$message);
            }
            $message='PeerTube publication update was not definitively accepted; automatic replay is refused.';
            if ($http_status > 0) {
                $message.=' Provider response: HTTP '.$http_status;
                if ('' !== $error_status) {
                    $message.=' ('.$error_status.')';
                }
                $message.='.';
            } elseif ('' !== $error_status) {
                $message.=' Transport classification: '.$error_status.'.';
            }
            return self::with_operator_event($this->fail($task_id,self::TASK_FINALIZE,$lock,$message,$now,'mutation_indeterminate'),$http_status,$error_status,$message);
        }
        if (! $this->remote_matches_manifest($api,(string)$secret['access_token'],$execution,$manifest,$target)) {
            return $this->fail($task_id,self::TASK_FINALIZE,$lock,'PeerTube publication update could not be positively verified against the complete reviewed manifest.',$now,'verification_failed');
        }

        // A WordPress transition may have advanced the lifecycle while the
        // consequential provider request was in flight. Never let that stale
        // generation write current execution state. If the newer lifecycle no
        // longer permits the just-applied non-private target, fail closed by
        // correcting the remote copy to Private before returning stale.
        if (null===$this->finalizer_fence($video_id,$payload,$target,(int)$manifest['anchor_post_id'])) {
            $latest=$this->current_lifecycle_fresh($video_id);
            if ('3'!==$target && ! self::lifecycle_allows_remote_target($latest,$target,(int)$manifest['anchor_post_id'])) {
                try { $corrected=$api->update_privacy((string)$secret['access_token'],(string)$execution['remote_uuid'],'3'); }
                catch(Throwable){ $corrected=array('ok'=>false); }
                if (true !== ($corrected['ok']??false) || ! $this->verify_remote($api,(string)$secret['access_token'],$execution,'3')) {
                    return $this->fail($task_id,self::TASK_FINALIZE,$lock,'WordPress publication state changed and emergency private correction could not be verified.',$now,'private_correction_failed');
                }
                $asset_status=$this->assets->record_publication_observation((int)$execution['remote_asset_id'],$video_id,(string)$execution['backend_id'],(string)$execution['remote_uuid'],(string)$execution['channel_id'],'3',$now);
                return in_array($asset_status,array(PeerTube_Remote_Asset_Store::APPLIED,PeerTube_Remote_Asset_Store::PRESENT),true)
                    ? $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'corrected_private')
                    : $this->fail($task_id,self::TASK_FINALIZE,$lock,'Private correction was remote-verified but local asset observation was indeterminate.',$now,'asset_indeterminate');
            }
            return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale');
        }

        $asset_status=$this->assets->record_publication_observation((int)$execution['remote_asset_id'],$video_id,(string)$execution['backend_id'],(string)$execution['remote_uuid'],(string)$execution['channel_id'],$target,$now);
        if (! in_array($asset_status,array(PeerTube_Remote_Asset_Store::APPLIED,PeerTube_Remote_Asset_Store::PRESENT),true)
            || ! $this->asset_matches_applied($execution,$target)) {
            return $this->fail($task_id,self::TASK_FINALIZE,$lock,'Verified PeerTube publication could not be recorded locally.',$now,'asset_indeterminate');
        }
        if (true !== ($state['lifecycle']['reveal_authorized'] ?? false)) {
            if(null!==$this->cutover)$this->cutover->reconcile($video_id,$now);
            return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'3' === $target ? 'staged_private' : 'staged_prepublication');
        }
        if (null===$this->finalizer_fence($video_id,$payload,$target,(int)$manifest['anchor_post_id'])) {
            return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale');
        }
        $execution_before=$execution;
        $execution=PeerTube_Publication_Execution::mark_applied($execution,$manifest,$now);
        if (array()===$execution || ! $this->save_execution($video_id,$execution,$execution_before)) {
            return null===$this->finalizer_fence($video_id,$payload,$target,(int)$manifest['anchor_post_id'])
                ? $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale')
                : $this->fail($task_id,self::TASK_FINALIZE,$lock,'The applied PeerTube publication settings could not be recorded locally.',$now,'execution_indeterminate');
        }
        return $this->finish_cutover($task_id,$lock,$video_id,$now,'verified',$payload,$target,(int)$manifest['anchor_post_id']);
    }

    /** @param array<string,mixed> $payload */
    private function finish_cutover(
        int $task_id,
        string $lock,
        int $video_id,
        int $now,
        string $service,
        array $payload,
        string $target_privacy_id,
        int $anchor_post_id
    ):array {
        // Final serving qualification/cutover is still owned by this exact
        // publication generation. A long-lived worker must not probe health,
        // write serving authority, or clean local derivatives after WordPress
        // has advanced the lifecycle underneath it.
        if (null===$this->finalizer_fence($video_id,$payload,$target_privacy_id,$anchor_post_id)) {
            return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale');
        }
        if (null === $this->cutover) {
            return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,$service);
        }
        if (null !== $this->publication_health) {
            $execution=$this->load_execution($video_id,true);
            $asset=is_array($execution)?$this->assets->find((int)($execution['remote_asset_id']??0)):null;
            if (! is_array($asset)) {
                return $this->reschedule($task_id,self::TASK_FINALIZE,$lock,$now+60,'Publication settings are verified; waiting for the remote serving record before checking the visitor-facing URL.',$now);
            }
            $processing_context=array();
            $operation_id=is_string($execution['operation_id']??null)?(string)$execution['operation_id']:'';
            $operation=''!==$operation_id?$this->operations->get($operation_id):null;
            if(is_array($operation)){
                $source_bytes=is_int($operation['source']['bytes']??null)?(int)$operation['source']['bytes']:0;
                $processing_started=is_int($operation['accepted_at']??null)?(int)$operation['accepted_at']:0;
                $processing_verified=is_int($operation['verified_at']??null)?(int)$operation['verified_at']:0;
                if($source_bytes>0&&$processing_started>0&&$processing_started<=$now){
                    $processing_context=array(
                        'source_bytes'=>$source_bytes,
                        'processing_started_at'=>$processing_started,
                        'processing_verified_at'=>($processing_verified>=$processing_started&&$processing_verified<=$now)?$processing_verified:0,
                    );
                }
            }
            if (null===$this->finalizer_fence($video_id,$payload,$target_privacy_id,$anchor_post_id)) {
                return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale');
            }
            $public=$this->publication_health->probe_and_record($asset,$now,true,$processing_context);
            if (true !== ($public['recorded']??false)) {
                return $this->reschedule($task_id,self::TASK_FINALIZE,$lock,$now+60,'Publication settings are verified; waiting to record the visitor-facing serving check.',$now);
            }
            if (Serving_Viability::HEALTHY !== (string)($public['viability_status']??'')) {
                $message=is_string($public['message']??null)?trim((string)$public['message']):'';
                $message=''!==$message?$message:'The published serving URL is not yet usable by an unauthenticated visitor.';
                $delay=60;
                if(Serving_Viability::PROCESSING===(string)($public['viability_status']??'')){
                    $retry=is_int($public['retry_after']??null)?(int)$public['retry_after']:0;
                    if($retry>0){$delay=max(60,min(900,$retry));}
                    $eta=is_int($public['estimated_ready_at']??null)?(int)$public['estimated_ready_at']:0;
                    if($eta>$now){
                        $message.=' Estimated readiness: '.gmdate('Y-m-d H:i:s',$eta).' UTC ('.(string)($public['estimate_confidence']??'low').' confidence).';
                    }
                }
                return $this->reschedule($task_id,self::TASK_FINALIZE,$lock,$now+$delay,'Publication metadata is verified, but public serving qualification has not passed: '.$message,$now);
            }
        }
        if (null===$this->finalizer_fence($video_id,$payload,$target_privacy_id,$anchor_post_id)) {
            return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale');
        }
        $status=$this->cutover->reconcile($video_id,$now);
        if (in_array($status,array(PeerTube_Serving_Cutover_Service::APPLIED,PeerTube_Serving_Cutover_Service::PRESENT),true)) {
            if (null !== $this->derivative_cleanup) {
                // Serving cutover may have completed just before WordPress advanced
                // the lifecycle. Reconfirm exact generation ownership before any
                // local derivative deletion so a stale finalizer cannot remove the
                // fallback needed by the newer publication state.
                if (null===$this->finalizer_fence($video_id,$payload,$target_privacy_id,$anchor_post_id)) {
                    return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,'stale');
                }
                $cleanup=$this->derivative_cleanup->cleanup($video_id);
                if (! in_array($cleanup,array(PeerTube_Derivative_Cleanup_Service::APPLIED,PeerTube_Derivative_Cleanup_Service::PRESENT),true)) {
                    return $this->reschedule($task_id,self::TASK_FINALIZE,$lock,$now+60,'PeerTube serving is verified; waiting for bounded cleanup of local AWVP derivatives.',$now);
                }
                $service.=':cleanup_'.$cleanup;
            }
            return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,$service.':'.$status);
        }
        if (PeerTube_Serving_Cutover_Service::LOCAL===$status) {
            return $this->complete($task_id,self::TASK_FINALIZE,$lock,$now,$service.':'.$status);
        }
        if (PeerTube_Serving_Cutover_Service::INDETERMINATE===$status) {
            return $this->reschedule($task_id,self::TASK_FINALIZE,$lock,$now+60,'PeerTube serving is verified; waiting to record the local serving switch.',$now);
        }
        return $this->fail($task_id,self::TASK_FINALIZE,$lock,'Verified publication does not satisfy serving-cutover evidence.',$now,'cutover_refused');
    }

    private function asset_matches_applied(array $execution,string $privacy_id):bool
    {
        $asset=$this->assets->find((int)($execution['remote_asset_id']??0));
        $privacy=Video_Serving_Authority::privacy_name($privacy_id);
        return is_array($asset)&&''!==$privacy
            &&(int)$execution['remote_asset_id']===(int)($asset['id']??0)
            &&$execution['backend_id']===($asset['backend_id']??null)
            &&$execution['channel_id']===($asset['channel_id']??null)
            &&$execution['remote_uuid']===strtolower((string)($asset['remote_id']??''))
            &&'ready'===($asset['state']??null)&&$privacy===($asset['desired_privacy']??null)
            &&$privacy===($asset['actual_privacy']??null)&&'1:published'===($asset['remote_processing_state']??null)
            &&is_string($asset['last_verified_at']??null)&&''!==$asset['last_verified_at'];
    }

    /** @return array<string,mixed> */
    private function current_authority(int $video_id,array $task,array $payload,bool $remote,int $now):array
    {
        self::refresh_post_meta_cache($video_id);
        $lifecycle=PeerTube_Publication_Lifecycle::sanitize(get_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE,true));
        if (array()===$lifecycle) return array('status'=>'refused');
        if ((int)$payload['generation'] !== (int)$lifecycle['generation'] || ! hash_equals((string)$payload['plan_sha256'],(string)$lifecycle['plan_sha256'])) return array('status'=>'stale');
        if (false === $lifecycle['upload_authorized']) return array('status'=>'inactive','lifecycle'=>$lifecycle);
        $plan=PeerTube_Publication_Plan::sanitize(get_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_PLAN,true));
        $destination=Video_Destination::sanitize(get_post_meta($video_id,Video_Meta::DESTINATION,true));
        if (array()===$plan || !PeerTube_Publication_Plan::ready_for_dispatch($plan) || array()===$destination
            || $lifecycle['backend_id']!==$plan['backend_id'] || $plan['backend_id']!==($destination['backend_id']??null)
            || $plan['channel_id']!==(string)($destination['channel_id']??'')
            || $lifecycle['anchor_post_id']!==$plan['anchor_post_id']
            || !hash_equals($lifecycle['plan_sha256'],PeerTube_Publication_Lifecycle::plan_sha256($plan))) return array('status'=>'refused');
        if (null !== $this->authority_repair) {
            // The sync path is the send boundary: require a recently refreshed
            // provider catalog before staging/opening an upload. Finalizer
            // checks still repair credentials/stale context but do not force a
            // five-minute catalog refresh loop while PeerTube is transcoding.
            $repair=$this->authority_repair->repair((string)$plan['backend_id'],$now,!$remote);
            $repair_status=is_string($repair['status']??null)?(string)$repair['status']:'';
            if (PeerTube_Token_Lifecycle_Service::STATUS_COMPLETE !== $repair_status) {
                return array('status'=>PeerTube_Token_Lifecycle_Service::STATUS_REAUTHENTICATION_REQUIRED===$repair_status?'credential_unavailable':'catalog_unavailable');
            }
        }
        $descriptor=$this->registry->get_fresh((string)$plan['backend_id']);
        if (!is_array($descriptor)||'active'!==($descriptor['state']??null)||Backend_Registry::PEERTUBE_TYPE!==($descriptor['type']??null)) return array('status'=>'backend_unavailable');
        try{$secret=$this->secrets->read((string)$descriptor['secret_ref'],(string)$plan['backend_id']);}catch(Throwable){$secret=null;}
        if (!self::valid_secret($secret,$now)) return array('status'=>'credential_unavailable');
        $catalog=$this->catalogs->get_for_context_fresh((string)$plan['backend_id'],(string)$descriptor['config']['origin'],(int)$secret['generation']);
        if (!is_array($catalog)||true===($catalog['stale']??true)) return array('status'=>'catalog_unavailable');
        $settings=$this->defaults->get(); if(null===$settings)return array('status'=>'defaults_unavailable');
        try{$api=($this->api_factory)((string)$descriptor['config']['origin']);}catch(Throwable){$api=null;}
        if($remote && (!$api instanceof PeerTube_Publication_Mutation_Api || $api->origin()!==$descriptor['config']['origin']))return array('status'=>'api_unavailable');
        return array('status'=>'ready','lifecycle'=>$lifecycle,'plan'=>$plan,'destination'=>$destination,'descriptor'=>$descriptor,'secret'=>$secret,'catalog'=>$catalog,'defaults'=>$settings,'api'=>$api);
    }

    /** @param array<string,mixed>|null $secret */
    private static function valid_secret(?array $secret,int $now):bool
    {
        return is_array($secret)&&array('access_token','refresh_token','access_expires_at','refresh_expires_at','generation')===array_keys($secret)
            &&is_string($secret['access_token'])&&''!==$secret['access_token']&&is_int($secret['generation'])&&$secret['generation']>0
            &&is_int($secret['access_expires_at'])&&is_int($secret['refresh_expires_at'])&&$now<=PHP_INT_MAX-self::TOKEN_SKEW_SECONDS
            &&$secret['access_expires_at']>$now+self::TOKEN_SKEW_SECONDS&&$secret['refresh_expires_at']>$now+self::TOKEN_SKEW_SECONDS;
    }

    private function manifest_provider_valid(array $manifest,array $catalog):bool
    {
        $manifest=PeerTube_Publication_Manifest::sanitize($manifest); $catalog=PeerTube_Publication_Catalog::sanitize($catalog);
        if(array()===$manifest||array()===$catalog||true===$catalog['stale']||$manifest['backend_id']!==$catalog['backend_id']||'5'===$manifest['final_privacy_id'])return false;
        $channel=false;foreach($catalog['channels'] as $row){if($manifest['channel_id']===($row['id']??null)&&'owned'===($row['authority']??null)){$channel=true;break;}}
        if(!$channel||!isset($catalog['privacies'][$manifest['final_privacy_id']]))return false;
        if(''!==$manifest['licence_id']&&!isset($catalog['licences'][$manifest['licence_id']]))return false;
        if(''!==$manifest['category_id']&&!isset($catalog['categories'][$manifest['category_id']]))return false;
        return ''===$manifest['language']||isset($catalog['languages'][$manifest['language']]);
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after */
    private static function clear_transition_safe(array $before,array $after):bool
    {
        if(array()===$before)return true;
        $before=PeerTube_Publication_Manifest::sanitize($before);$after=PeerTube_Publication_Manifest::sanitize($after);if(array()===$before||array()===$after)return false;
        if([]!==$before['tags']&&[]===$after['tags'])return false;
        foreach(array('licence_id','category_id','language','originally_published_at') as $field){if(''!==$before[$field]&&''===$after[$field])return false;}
        if((int)$before['thumbnail_attachment_id']>0&&(int)$after['thumbnail_attachment_id']<1)return false;
        return true;
    }

    private function remote_matches_manifest(PeerTube_Publication_Mutation_Api $api,string $token,array $execution,array $manifest,string $privacy):bool
    {
        $manifest=PeerTube_Publication_Manifest::sanitize($manifest);
        if (array()===$manifest || (int)$manifest['thumbnail_attachment_id']>0) return false;
        try{$status=$api->video_status($token,(string)$execution['remote_uuid']);}catch(Throwable){return false;}
        $data=is_array($status['data']??null)?$status['data']:array();
        $publication=is_array($data['publication']??null)?$data['publication']:array();
        if (true!==($status['ok']??false)||array()===$publication||1!==(int)($data['state_id']??0)
            ||$privacy!==(string)($data['privacy_id']??'')||(string)$execution['channel_id']!==(string)($data['channel_id']??'')
            ||!hash_equals((string)$execution['remote_uuid'],(string)($data['uuid']??''))) return false;
        $expected=array(
            'title'=>(string)$manifest['title'],
            'description_markdown'=>(string)$manifest['description_markdown'],
            'tags'=>self::semantic_tags($manifest['tags']),
            'support_markdown'=>(string)$manifest['support_markdown'],
            'licence_id'=>(string)$manifest['licence_id'],
            'category_id'=>(string)$manifest['category_id'],
            'language'=>(string)$manifest['language'],
            'download_enabled'=>(bool)$manifest['download_enabled'],
            'originally_published_at'=>(string)$manifest['originally_published_at'],
            'comments_policy'=>(string)$manifest['comments_policy'],
            'moderation'=>array(
                'sensitive'=>(bool)$manifest['moderation']['sensitive'],
                'reason'=>(string)$manifest['moderation']['reason'],
                'violent'=>(bool)$manifest['moderation']['violent'],
                'sexually_explicit'=>(bool)$manifest['moderation']['sexually_explicit'],
            ),
        );
        $publication['tags']=self::semantic_tags($publication['tags'] ?? null);
        return null !== $expected['tags'] && null !== $publication['tags'] && $expected===$publication;
    }

    /** @return list<string>|null */
    private static function semantic_tags(mixed $value): ?array
    {
        if (! is_array($value) || array_values($value) !== $value || count($value) > 5) {
            return null;
        }
        $tags = array();
        foreach ($value as $tag) {
            if (! is_string($tag) || '' === $tag) {
                return null;
            }
            $tags[] = $tag;
        }
        sort($tags, SORT_STRING);
        return $tags;
    }

    private function verify_remote(PeerTube_Publication_Mutation_Api $api,string $token,array $execution,string $privacy):bool
    {
        try{$status=$api->video_status($token,(string)$execution['remote_uuid']);}catch(Throwable){return false;}
        return true===($status['ok']??false)&&is_array($status['data']??null)&&1===(int)$status['data']['state_id']
            &&$privacy===(string)$status['data']['privacy_id']&&(string)$execution['channel_id']===(string)$status['data']['channel_id']
            &&hash_equals((string)$execution['remote_uuid'],(string)$status['data']['uuid']);
    }

    /** @return array<string,mixed>|null|array{} */
    private function load_execution(int $video_id,bool $fresh=false):array|null
    {
        if($fresh)self::refresh_post_meta_cache($video_id);
        if(!metadata_exists('post',$video_id,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION))return null;
        return PeerTube_Publication_Execution::sanitize(get_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION,true));
    }
    private function save_execution(int $video_id,array $record,?array $previous=null):bool
    {
        $record=PeerTube_Publication_Execution::sanitize($record);if(array()===$record)return false;
        if(null!==$previous){
            $previous=PeerTube_Publication_Execution::sanitize($previous);
            if(array()===$previous)return false;
            update_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION,$record,$previous);
        }else{
            update_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION,$record);
        }
        self::refresh_post_meta_cache($video_id);
        return $record===PeerTube_Publication_Execution::sanitize(get_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_EXECUTION,true));
    }

    public static function finalize_idempotency_key(int $video_id, int $generation, string $plan_sha256): string
    {
        if ($video_id < 1 || $generation < 1 || 1 !== preg_match('/^[a-f0-9]{64}$/D', $plan_sha256)) {
            return '';
        }
        return hash('sha256', 'awvp-task:v1:' . self::TASK_FINALIZE . ':' . $video_id . ':' . $generation . ':' . $plan_sha256);
    }

    /** @param array<string,mixed> $lifecycle @return array{status:string,task_id:int} */
    private function enqueue_finalize(int $video_id,array $lifecycle,int $now):array
    {
        $payload=array('version'=>self::PAYLOAD_VERSION,'generation'=>(int)$lifecycle['generation'],'plan_sha256'=>(string)$lifecycle['plan_sha256']);
        $key=self::finalize_idempotency_key($video_id,(int)$payload['generation'],(string)$payload['plan_sha256']);
        if ('' === $key) {
            return array('status'=>Task_Repository::CONFLICT,'task_id'=>0);
        }
        return $this->tasks->enqueue(self::TASK_FINALIZE,$video_id,null,(string)$lifecycle['backend_id'],$key,$payload,$now+15,$now,110,720);
    }

    /** @return array{version:int,generation:int,plan_sha256:string}|null */
    private static function payload(mixed $json):?array
    {
        if(!is_string($json)||''===$json||strlen($json)>16384)return null;
        try{$v=json_decode($json,true,8,JSON_THROW_ON_ERROR);}catch(Throwable){return null;}
        if(!is_array($v)||array('version','generation','plan_sha256')!==array_keys($v)||self::PAYLOAD_VERSION!==($v['version']??null)||self::positive_int($v['generation']??null)<1||!is_string($v['plan_sha256']??null)||1!==preg_match('/^[a-f0-9]{64}$/D',$v['plan_sha256']))return null;
        return array('version'=>self::PAYLOAD_VERSION,'generation'=>(int)$v['generation'],'plan_sha256'=>$v['plan_sha256']);
    }


    /** @return array<string,mixed>|null */
    private function lifecycle_for_payload(int $video_id,array $payload):?array
    {
        self::refresh_post_meta_cache($video_id);
        $lifecycle=PeerTube_Publication_Lifecycle::sanitize(get_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE,true));
        return array()!==$lifecycle
            &&(int)$payload['generation']===(int)$lifecycle['generation']
            &&hash_equals((string)$payload['plan_sha256'],(string)$lifecycle['plan_sha256'])
            ? $lifecycle
            : null;
    }

    private function finalizer_fence(int $video_id,array $payload,string $target_privacy_id,int $anchor_post_id):?array
    {
        $lifecycle=$this->lifecycle_for_payload($video_id,$payload);
        if(null===$lifecycle || $target_privacy_id!==(string)$lifecycle['target_privacy_id'])return null;
        if('3'!==$target_privacy_id){
            $anchor=self::fresh_post($anchor_post_id);
            if(!is_object($anchor)||!self::lifecycle_allows_target_state($lifecycle,$target_privacy_id,(string)($anchor->post_status??'')))return null;
        }
        return $lifecycle;
    }

    /** @return array<string,mixed> */
    private function current_lifecycle_fresh(int $video_id):array
    {
        self::refresh_post_meta_cache($video_id);
        return PeerTube_Publication_Lifecycle::sanitize(
            get_post_meta($video_id,Video_Meta::PEERTUBE_PUBLICATION_LIFECYCLE,true)
        );
    }

    private static function refresh_post_meta_cache(int $post_id):void
    {
        if($post_id>0&&function_exists('wp_cache_delete'))wp_cache_delete($post_id,'post_meta');
    }

    private static function fresh_post(int $post_id):mixed
    {
        if($post_id<1)return null;
        if(function_exists('wp_cache_delete'))wp_cache_delete($post_id,'posts');
        return get_post($post_id);
    }

    private static function lifecycle_allows_remote_target(array $lifecycle,string $target_privacy_id,int $anchor_post_id):bool
    {
        $lifecycle=PeerTube_Publication_Lifecycle::sanitize($lifecycle);
        if(array()===$lifecycle||$target_privacy_id!==(string)$lifecycle['target_privacy_id'])return false;
        if('3'===$target_privacy_id)return true;
        $anchor=self::fresh_post($anchor_post_id);
        return is_object($anchor)
            && self::lifecycle_allows_target_state($lifecycle,$target_privacy_id,(string)($anchor->post_status??''));
    }

    /** @param array<string,mixed> $lifecycle */
    private static function lifecycle_allows_target_state(array $lifecycle,string $target_privacy_id,string $anchor_status):bool
    {
        $lifecycle=PeerTube_Publication_Lifecycle::sanitize($lifecycle);
        if(array()===$lifecycle||$target_privacy_id!==(string)$lifecycle['target_privacy_id'])return false;
        if(PeerTube_Publication_Plan::PRE_PUBLISH_PRIVATE===$target_privacy_id)return true;
        if(true===($lifecycle['reveal_authorized']??false)) {
            return 'publish'===($lifecycle['wordpress_status']??null)&&'publish'===$anchor_status;
        }
        return PeerTube_Publication_Lifecycle::pre_publish_target_allowed(
            (string)($lifecycle['wordpress_status']??''),
            (string)($lifecycle['dispatch_policy']??''),
            true===($lifecycle['upload_authorized']??false),
            $target_privacy_id
        ) && 'future'===$anchor_status;
    }

    private static function transient_authority(string $status): bool
    {
        return in_array($status, array(
            'backend_unavailable',
            'credential_unavailable',
            'catalog_unavailable',
            'api_unavailable',
        ), true);
    }

    private function acquire_lock(int $video_id,int $now):?string
    {
        $name=self::LOCK_PREFIX.hash('sha256',(string)$video_id);$token=bin2hex(random_bytes(16));$candidate=array('version'=>1,'token'=>$token,'created_at'=>$now);
        $current=get_option($name,null);
        if(null===$current){return add_option($name,$candidate,'',false)?$token:($candidate===get_option($name,null)?$token:null);}
        if(!is_array($current)||array('version','token','created_at')!==array_keys($current)||1!==$current['version']||!is_string($current['token'])||1!==preg_match('/^[a-f0-9]{32}$/D',$current['token'])||!is_int($current['created_at'])||$current['created_at']<1||$current['created_at']>$now)return null;
        if($current['created_at']>$now-self::LOCK_SECONDS)return null;
        delete_option($name);return add_option($name,$candidate,'',false)?$token:null;
    }
    private function release_lock(int $video_id,string $token):void
    {
        $name=self::LOCK_PREFIX.hash('sha256',(string)$video_id);$current=get_option($name,null);
        if(is_array($current)&&hash_equals($token,(string)($current['token']??'')))delete_option($name);
    }

    private function complete(int $id,string $type,string $lock,int $now,string $service):array{return self::result(self::STATUS_COMPLETE,$id,$type,$this->tasks->complete($id,$lock,$now),0,$service);}
    private function fail(int $id,string $type,string $lock,string $message,int $now,string $service):array{return self::result(self::STATUS_FAILED,$id,$type,$this->tasks->fail($id,$lock,$message,$now),0,$service);}
    private function reschedule(int $id,string $type,string $lock,int $after,string $message,int $now):array{return self::result(self::STATUS_REQUEUED,$id,$type,$this->tasks->defer($id,$lock,$after,$message,$now),$after,'waiting');}
    private static function result(string $status,int $task_id,string $task_type,string $repo,int $run_after=0,string $service=''):array{return array('status'=>$status,'task_id'=>$task_id,'task_type'=>$task_type,'service_status'=>$service,'repository_status'=>$repo,'run_after'=>$run_after);}
    /** @param array<string,mixed> $result @return array<string,mixed> */
    private static function with_operator_event(array $result,int $http_status,string $error_status,string $message): array
    {
        $result['http_status']=$http_status;
        $result['error_status']=$error_status;
        $result['operator_message']=$message;
        return $result;
    }

    private static function http_status(mixed $value): int
    {
        return is_int($value) && $value >= 100 && $value <= 599 ? $value : 0;
    }

    private static function machine_error_status(mixed $value): string
    {
        if (! is_string($value) || '' === $value || strlen($value) > 64) {
            return '';
        }
        return 1 === preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $value) ? $value : '';
    }

    private static function positive_int(mixed $v):int{if(is_int($v))return$v>0?$v:0;if(!is_string($v)||1!==preg_match('/^[1-9][0-9]*$/D',$v))return 0;$n=(int)$v;return$n>0&&(string)$n===$v?$n:0;}
}

// EOF: includes/PeerTube_Publication_Task_Coordinator.php
