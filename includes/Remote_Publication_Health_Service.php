<?php
/**
 * File: includes/Remote_Publication_Health_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Bounded cron coordinator for backend-agnostic remote publication serving health. */
final class Remote_Publication_Health_Service
{
    public const BATCH = 20;

    public function __construct(
        private readonly Remote_Publication_Health_Repository $health,
        private readonly Backend_Registry $backends,
        private readonly Serving_Health_Adapter_Factory $adapters,
        private readonly ?PeerTube_Event_Repository $events = null,
        private readonly ?Backend_Processing_Estimator $processing_estimator = null,
        private readonly ?Backend_Health_Incident_Store $backend_incidents = null,
        private readonly ?Remote_Health_Notification_Service $notifications = null
    ) {
    }

    public function run(?int $now = null): int
    {
        $now = $now ?? time();
        if ($now < 1) {
            return 0;
        }
        $processed = 0;
        foreach ($this->health->due_publications($now, self::BATCH) as $asset) {
            // Periodic broken-publication monitoring is only for a remote
            // publication that has already proven it can serve visitors. A
            // newly uploaded asset may intentionally remain private while the
            // publication/finalizer state machine is still working; that
            // pre-cutover state must never create an outage incident or email.
            if (! $this->periodic_monitor_eligible($asset)) {
                continue;
            }
            $result = $this->probe_and_record($asset, $now);
            if (true === ($result['recorded'] ?? false)) {
                ++$processed;
            }
        }
        return $processed;
    }

    /** @param array<string,mixed> $asset */
    private function periodic_monitor_eligible(array $asset): bool
    {
        return $this->previously_publicly_qualified($asset, $this->health->find((int) ($asset['id'] ?? 0)));
    }

    /** @param array<string,mixed> $asset @param array<string,mixed>|null $health */
    private function previously_publicly_qualified(array $asset, ?array $health = null): bool
    {
        $asset_id = (int) ($asset['id'] ?? 0);
        $video_id = (int) ($asset['video_post_id'] ?? 0);
        $backend_id = Backend_Identity::sanitize((string) ($asset['backend_id'] ?? ''));
        if ($asset_id < 1 || $video_id < 1 || '' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id) {
            return false;
        }

        // last_healthy_at is the durable "was publicly qualified" marker. It
        // survives later failure/failover so a formerly serving publication
        // continues to be monitored even when it is no longer the active
        // serving candidate.
        if (is_array($health)
            && $video_id === (int) ($health['video_post_id'] ?? 0)
            && $backend_id === (string) ($health['backend_id'] ?? '')
            && is_string($health['last_healthy_at'] ?? null)
            && '' !== (string) $health['last_healthy_at']) {
            return true;
        }

        // RC10/early-RC11 installations may already have durable serving
        // authority without a publication-health row. Treat that exact
        // authority as equivalent historical cutover evidence so upgrades
        // enter periodic monitoring without manufacturing first-publication
        // health for unrelated ready/private assets.
        $authority = Video_Serving_Authority::sanitize(
            get_post_meta($video_id, Video_Meta::SERVING_AUTHORITY, true)
        );
        return array() !== $authority
            && $asset_id === (int) ($authority['remote_asset_id'] ?? 0)
            && $backend_id === (string) ($authority['backend_id'] ?? '');
    }

    /**
     * Probe one already-published serving candidate and durably record the
     * normalized visitor-facing result. This is also the final serving gate
     * used immediately after a publication mutation has been metadata-verified.
     *
     * processing_context is advisory and bounded. It lets the finalizer give
     * the health subsystem the source byte count and the time at which the
     * backend accepted the completed upload. This is used only for ETA/retry
     * estimation and recent performance learning; it is never publication
     * authority.
     *
     * @param array<string,mixed> $asset
     * @param array{source_bytes?:int,processing_started_at?:int,processing_verified_at?:int} $processing_context
     * @return array{
     *   recorded:bool,
     *   viability_status:string,
     *   reason_code:string,
     *   message:string,
     *   http_status:int,
     *   retry_after:int,
     *   estimated_ready_at:int,
     *   estimate_confidence:string,
     *   estimate_basis:string,
     *   estimate_sample_count:int
     * }
     */
    public function probe_and_record(
        array $asset,
        int $now,
        bool $initial_verified = false,
        array $processing_context = array()
    ): array {
        $asset_id = (int) ($asset['id'] ?? 0);
        $video_id = (int) ($asset['video_post_id'] ?? 0);
        $backend_id = Backend_Identity::sanitize((string) ($asset['backend_id'] ?? ''));
        if ($asset_id < 1 || $video_id < 1 || '' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id || $now < 1) {
            return self::probe_result(false, Serving_Viability::PROBE_INDETERMINATE, 'serving.health.asset_invalid', 'Stored remote publication identity is incomplete.', 0);
        }

        $context = self::processing_context($processing_context, $now);
        $before = $this->health->find($asset_id);
        $previously_qualified = $this->previously_publicly_qualified($asset, $before);
        $descriptor = $this->backends->get_fresh($backend_id);
        $adapter = is_array($descriptor) ? $this->adapters->resolve((string) ($descriptor['type'] ?? '')) : null;
        if (! is_array($descriptor) || null === $adapter) {
            $observation = Serving_Viability::create(
                Serving_Viability::PROBE_INDETERMINATE,
                'serving.health.backend_adapter_unavailable',
                'No active serving-health adapter is available for this backend.'
            );
        } else {
            $observation = $adapter->probe_publication($descriptor, $asset);
        }
        if (! $observation instanceof Serving_Viability) {
            return self::probe_result(false, Serving_Viability::PROBE_INDETERMINATE, 'serving.health.probe_invalid', 'The serving-health adapter returned an invalid result.', 0);
        }

        $estimate = self::empty_estimate();
        $next_check_override = 0;
        if (Serving_Viability::PROCESSING === $observation->status()
            && null !== $this->processing_estimator
            && $context['source_bytes'] > 0
            && $context['processing_started_at'] > 0
        ) {
            $estimate = $this->processing_estimator->estimate(
                $backend_id,
                $context['source_bytes'],
                $context['processing_started_at'],
                $now
            );
            if ($estimate['estimated_ready_at'] > 0) {
                $next_check_override = $now + Backend_Processing_Estimator::next_probe_delay(
                    $estimate['estimated_ready_at'],
                    $now
                );
            }
        }

        $written = $this->health->record(
            $asset_id,
            $video_id,
            $backend_id,
            $observation,
            $now,
            $initial_verified,
            $next_check_override
        );
        $recorded = in_array(
            $written,
            array(Remote_Publication_Health_Repository::APPLIED, Remote_Publication_Health_Repository::PRESENT),
            true
        );

        if ($recorded) {
            $this->record_transition($before, $asset, $observation, $now, $estimate, $context);
            $backend_outage = false;
            if (null !== $this->backend_incidents) {
                $incident_before = $this->backend_incidents->get($backend_id);
                if (Backend_Health_Incident_Store::backend_wide($observation)) {
                    $this->backend_incidents->mark_failure($backend_id, $observation, $now);
                    $incident_after = $this->backend_incidents->get($backend_id);
                    $backend_outage = is_array($incident_after);
                    if (null !== $this->notifications) {
                        $this->notifications->backend_observed($backend_id, $incident_before, $incident_after, $now);
                    }
                } elseif (Serving_Viability::HEALTHY === $observation->status() && is_array($incident_before)) {
                    $this->backend_incidents->mark_healthy($backend_id);
                    if (null !== $this->notifications) {
                        $this->notifications->backend_observed($backend_id, $incident_before, null, $now);
                    }
                }
            }
            if (null !== $this->notifications
                && ($previously_qualified || Serving_Viability::HEALTHY === $observation->status())) {
                $after_health = $this->health->find($asset_id);
                if (is_array($after_health)) {
                    $this->notifications->publication_observed($asset, $after_health, $now, $backend_outage);
                }
            }
            if (Serving_Viability::HEALTHY === $observation->status()
                && null !== $this->processing_estimator
                && $context['source_bytes'] > 0
                && $context['processing_started_at'] > 0
                && $context['processing_verified_at'] > $context['processing_started_at']
                && $context['processing_verified_at'] <= $now
                && (! is_array($before) || Serving_Viability::HEALTHY !== (string) ($before['status'] ?? ''))
            ) {
                $this->processing_estimator->observe(
                    $backend_id,
                    $context['source_bytes'],
                    $context['processing_verified_at'] - $context['processing_started_at'],
                    $context['processing_verified_at']
                );
            }
        }

        $retry_after = 0;
        if (Serving_Viability::PROCESSING === $observation->status()) {
            $retry_after = $next_check_override > $now
                ? $next_check_override - $now
                : Remote_Publication_Health_Repository::FIRST_FAILURE_RETRY;
        }

        return self::probe_result(
            $recorded,
            $observation->status(),
            $observation->reason_code(),
            $observation->message(),
            $observation->http_status(),
            $retry_after,
            $estimate
        );
    }

    /**
     * @param array<string,mixed> $context
     * @return array{source_bytes:int,processing_started_at:int,processing_verified_at:int}
     */
    private static function processing_context(array $context, int $now): array
    {
        $bytes = is_int($context['source_bytes'] ?? null) ? $context['source_bytes'] : 0;
        $started = is_int($context['processing_started_at'] ?? null) ? $context['processing_started_at'] : 0;
        $verified = is_int($context['processing_verified_at'] ?? null) ? $context['processing_verified_at'] : 0;
        if ($bytes < 1 || $started < 1 || $started > $now) {
            return array('source_bytes' => 0, 'processing_started_at' => 0, 'processing_verified_at' => 0);
        }
        if ($verified < $started || $verified > $now) {
            $verified = 0;
        }
        return array('source_bytes' => $bytes, 'processing_started_at' => $started, 'processing_verified_at' => $verified);
    }

    /**
     * @param array<string,mixed> $estimate
     * @return array{
     *   recorded:bool,
     *   viability_status:string,
     *   reason_code:string,
     *   message:string,
     *   http_status:int,
     *   retry_after:int,
     *   estimated_ready_at:int,
     *   estimate_confidence:string,
     *   estimate_basis:string,
     *   estimate_sample_count:int
     * }
     */
    private static function probe_result(
        bool $recorded,
        string $status,
        string $reason,
        string $message,
        int $http_status,
        int $retry_after = 0,
        array $estimate = array()
    ): array {
        $estimate = array() === $estimate ? self::empty_estimate() : $estimate;
        return array(
            'recorded' => $recorded,
            'viability_status' => $status,
            'reason_code' => $reason,
            'message' => $message,
            'http_status' => $http_status,
            'retry_after' => max(0, $retry_after),
            'estimated_ready_at' => (int) ($estimate['estimated_ready_at'] ?? 0),
            'estimate_confidence' => (string) ($estimate['confidence'] ?? 'low'),
            'estimate_basis' => (string) ($estimate['basis'] ?? 'unavailable'),
            'estimate_sample_count' => (int) ($estimate['sample_count'] ?? 0),
        );
    }

    /** @return array{seconds:int,estimated_ready_at:int,confidence:string,basis:string,sample_count:int,same_bucket_count:int} */
    private static function empty_estimate(): array
    {
        return array(
            'seconds' => 0,
            'estimated_ready_at' => 0,
            'confidence' => 'low',
            'basis' => 'unavailable',
            'sample_count' => 0,
            'same_bucket_count' => 0,
        );
    }

    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed> $asset
     * @param array<string,mixed> $estimate
     * @param array{source_bytes:int,processing_started_at:int} $context
     */
    private function record_transition(
        ?array $before,
        array $asset,
        Serving_Viability $observation,
        int $now,
        array $estimate,
        array $context
    ): void {
        if (null === $this->events) {
            return;
        }
        $previous = is_array($before) ? (string) ($before['status'] ?? '') : '';
        $status = $observation->status();
        if ($status === $previous) {
            return;
        }
        $video_id = (int) ($asset['video_post_id'] ?? 0);
        $asset_id = (int) ($asset['id'] ?? 0);
        $backend_id = (string) ($asset['backend_id'] ?? '');
        if (Serving_Viability::HEALTHY === $status) {
            if ('' === $previous) {
                return;
            }
            $this->events->record(
                $video_id,
                7,
                'serving_health_recovered',
                'info',
                'Remote publication serving health recovered.',
                $now,
                0,
                '',
                $asset_id,
                $backend_id,
                200,
                'AWVP will restore this backend after the recovery-confirmation threshold is satisfied.',
                'No action is required unless the publication continues to flap.',
                array('serving_health' => $status)
            );
            return;
        }
        if (Serving_Viability::PROCESSING === $status) {
            $context_event = array(
                'serving_health' => $status,
                'error_code' => $observation->reason_code(),
            );
            if ((int) ($estimate['estimated_ready_at'] ?? 0) > 0) {
                $context_event['estimated_ready_at'] = (int) $estimate['estimated_ready_at'];
                $context_event['estimate_confidence'] = (string) ($estimate['confidence'] ?? 'low');
                $context_event['estimate_basis'] = (string) ($estimate['basis'] ?? 'unavailable');
                $context_event['estimate_sample_count'] = (int) ($estimate['sample_count'] ?? 0);
            }
            if ($context['source_bytes'] > 0) {
                $context_event['source_bytes'] = $context['source_bytes'];
            }
            $this->events->record(
                $video_id,
                5,
                'serving_health_processing',
                'info',
                '' !== $observation->message() ? $observation->message() : 'The remote backend is still processing this publication.',
                $now,
                0,
                '',
                $asset_id,
                $backend_id,
                $observation->http_status(),
                'AWVP will keep the existing viable serving source and recheck the new publication automatically.',
                'No action is required while processing remains within the estimated window.',
                $context_event
            );
            return;
        }

        $message = '' !== $observation->message()
            ? $observation->message()
            : 'The remote publication is not currently viable for serving.';
        $this->events->record(
            $video_id,
            7,
            'serving_health_' . $status,
            'error',
            $message,
            $now,
            0,
            '',
            $asset_id,
            $backend_id,
            $observation->http_status(),
            'AWVP removed this publication from serving eligibility and will use the next viable backend or WordPress source.',
            'Review the publication health. Republish from the WordPress original if the remote copy is missing.',
            array('serving_health' => $status, 'error_code' => $observation->reason_code())
        );
    }
}

// EOF: includes/Remote_Publication_Health_Service.php
