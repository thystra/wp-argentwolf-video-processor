<?php
/**
 * File: includes/PeerTube_Publication_Editor_Rest.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Capability-aware R46.3c REST boundary for publication-plan editing only. */
final class PeerTube_Publication_Editor_Rest
{
    public const NAMESPACE = 'argentwolf-video-processor/v1';

    public function __construct(private readonly PeerTube_Publication_Editor_Service $service)
    {
    }

    public function register(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/editor/videos/(?P<video_id>[1-9][0-9]*)/publication',
            array(
                array(
                    'methods'             => 'GET',
                    'callback'            => array($this, 'read'),
                    'permission_callback' => array($this, 'can_edit'),
                ),
                array(
                    'methods'             => 'POST',
                    'callback'            => array($this, 'save'),
                    'permission_callback' => array($this, 'can_edit'),
                ),
            )
        );
    }

    public function can_edit(\WP_REST_Request $request): bool
    {
        $video_id = Video_Meta::sanitize_positive_id($request->get_param('video_id'));
        if ($video_id < 1 || ! current_user_can('upload_files') || ! current_user_can('edit_post', $video_id)) {
            return false;
        }

        // Publication timing/release policy is anchored to the original
        // WordPress post. Reusing an AWVP Video in another post must not become
        // an indirect grant to change the origin post's publication intent.
        $state = $this->service->editor_state($video_id);
        $anchor_post_id = is_array($state)
            ? Video_Meta::sanitize_positive_id($state['draft']['anchor_post_id'] ?? 0)
            : 0;
        if ($anchor_post_id < 1 || ! current_user_can('edit_post', $anchor_post_id)) {
            return false;
        }

        $plan = $request->get_param('plan');
        if (is_array($plan)) {
            $thumbnail_id = Video_Meta::sanitize_positive_id($plan['thumbnail_attachment_id'] ?? 0);
            if ($thumbnail_id > 0 && ! current_user_can('edit_post', $thumbnail_id)) {
                return false;
            }
        }
        return true;
    }

    public function read(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $video_id = Video_Meta::sanitize_positive_id($request->get_param('video_id'));
        $state = $this->service->editor_state($video_id);
        return null === $state
            ? $this->error('argentwolf_video_processor_publication_unavailable', __('PeerTube publication settings are unavailable for this AWVP Video.', 'argentwolf-video-processor'), 404)
            : rest_ensure_response($state);
    }

    public function save(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $video_id = Video_Meta::sanitize_positive_id($request->get_param('video_id'));
        $plan = $request->get_param('plan');
        $replace = $request->get_param('replace_existing');
        if (! is_array($plan) || ! is_bool($replace)) {
            return $this->error('argentwolf_video_processor_publication_invalid', __('The PeerTube publication plan request is invalid.', 'argentwolf-video-processor'), 400);
        }

        $result = $this->service->save($video_id, $plan, $replace);
        if (PeerTube_Publication_Editor_Service::REFUSED === $result['status']) {
            return $this->error('argentwolf_video_processor_publication_refused', __('The PeerTube publication plan could not be stored safely. Refresh provider choices and review the required fields.', 'argentwolf-video-processor'), 409);
        }
        if (PeerTube_Publication_Editor_Service::INDETERMINATE === $result['status']) {
            return $this->error('argentwolf_video_processor_publication_indeterminate', __('AWVP could not verify the complete publication-plan update. Reload the block before retrying.', 'argentwolf-video-processor'), 409);
        }

        $state = $this->service->editor_state($video_id);
        if (null === $state) {
            return $this->error('argentwolf_video_processor_publication_state_unavailable', __('The publication plan was stored but its resulting editor state could not be verified.', 'argentwolf-video-processor'), 500);
        }
        $state['status'] = $result['status'];
        return rest_ensure_response($state);
    }

    private function error(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error($code, $message, array('status'=>$status));
    }
}

// EOF: includes/PeerTube_Publication_Editor_Rest.php
