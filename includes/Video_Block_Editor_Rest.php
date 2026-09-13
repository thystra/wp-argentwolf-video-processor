<?php
/**
 * File: includes/Video_Block_Editor_Rest.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Purpose-built, capability-aware REST surface for the R46.3b block only. */
final class Video_Block_Editor_Rest
{
    public const NAMESPACE = 'argentwolf-video-processor/v1';

    public function __construct(private readonly Video_Block_Editor_Service $service)
    {
    }

    public function register(): void
    {
        register_rest_route(
            self::NAMESPACE,
            '/editor/videos',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'bind'),
                'permission_callback' => array($this, 'can_bind'),
            )
        );
        register_rest_route(
            self::NAMESPACE,
            '/editor/videos/(?P<video_id>[1-9][0-9]*)',
            array(
                'methods'             => 'GET',
                'callback'            => array($this, 'read'),
                'permission_callback' => array($this, 'can_edit_video'),
            )
        );
        register_rest_route(
            self::NAMESPACE,
            '/editor/videos/(?P<video_id>[1-9][0-9]*)/destination',
            array(
                'methods'             => 'POST',
                'callback'            => array($this, 'destination'),
                'permission_callback' => array($this, 'can_edit_video'),
            )
        );
    }

    public function can_bind(\WP_REST_Request $request): bool
    {
        $attachment_id = Video_Meta::sanitize_positive_id($request->get_param('attachment_id'));
        $origin_post_id = Video_Meta::sanitize_positive_id($request->get_param('origin_post_id'));
        return $attachment_id > 0
            && $origin_post_id > 0
            && current_user_can('upload_files')
            && current_user_can('edit_post', $attachment_id)
            && current_user_can('edit_post', $origin_post_id);
    }

    public function can_edit_video(\WP_REST_Request $request): bool
    {
        $video_id = Video_Meta::sanitize_positive_id($request->get_param('video_id'));
        return $video_id > 0
            && current_user_can('upload_files')
            && current_user_can('edit_post', $video_id);
    }

    public function bind(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $attachment_id = Video_Meta::sanitize_positive_id($request->get_param('attachment_id'));
        $origin_post_id = Video_Meta::sanitize_positive_id($request->get_param('origin_post_id'));
        $result = $this->service->bind_local_attachment(
            $attachment_id,
            $origin_post_id,
            get_current_user_id()
        );

        if (Video_Block_Editor_Service::BUSY === $result['status']) {
            return $this->error('argentwolf_video_processor_editor_busy', __('That video is being bound by another editor request. Try again.', 'argentwolf-video-processor'), 409);
        }
        if (! in_array($result['status'], array(Video_Block_Editor_Service::APPLIED, Video_Block_Editor_Service::PRESENT), true)
            || $result['video_id'] < 1) {
            return $this->error('argentwolf_video_processor_editor_bind_failed', __('The WordPress video could not be bound safely to an AWVP Video.', 'argentwolf-video-processor'), 409);
        }

        $state = $this->service->editor_state($result['video_id']);
        if (null === $state) {
            return $this->error('argentwolf_video_processor_editor_state_unavailable', __('The AWVP Video was bound but its editor state could not be verified.', 'argentwolf-video-processor'), 500);
        }
        $state['status'] = $result['status'];
        return rest_ensure_response($state);
    }

    public function read(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $video_id = Video_Meta::sanitize_positive_id($request->get_param('video_id'));
        $state = $this->service->editor_state($video_id);
        return null === $state
            ? $this->error('argentwolf_video_processor_editor_video_unavailable', __('This AWVP Video is unavailable or has no usable local or remote serving source.', 'argentwolf-video-processor'), 404)
            : rest_ensure_response($state);
    }

    public function destination(\WP_REST_Request $request): \WP_REST_Response|\WP_Error
    {
        $video_id = Video_Meta::sanitize_positive_id($request->get_param('video_id'));
        $mode = $request->get_param('mode');
        $backend_id = $request->get_param('backend_id');
        if (! is_string($mode) || ! in_array($mode, array('site_default', 'local', 'backend'), true)) {
            return $this->error('argentwolf_video_processor_editor_destination_invalid', __('The requested destination selection is invalid.', 'argentwolf-video-processor'), 400);
        }
        if ('backend' === $mode) {
            if (! is_string($backend_id) || $backend_id !== Backend_Identity::sanitize($backend_id)) {
                return $this->error('argentwolf_video_processor_editor_destination_invalid', __('The requested backend is invalid.', 'argentwolf-video-processor'), 400);
            }
        } else {
            $backend_id = '';
        }

        $result = $this->service->set_destination($video_id, $mode, $backend_id);
        if (! in_array($result['status'], array(Video_Block_Editor_Service::APPLIED, Video_Block_Editor_Service::PRESENT), true)) {
            return $this->error('argentwolf_video_processor_editor_destination_refused', __('The destination could not be stored safely. Check the backend and publishing defaults.', 'argentwolf-video-processor'), 409);
        }

        $state = $this->service->editor_state($video_id);
        if (null === $state) {
            return $this->error('argentwolf_video_processor_editor_state_unavailable', __('The destination was stored but the resulting editor state could not be verified.', 'argentwolf-video-processor'), 500);
        }
        $state['status'] = $result['status'];
        return rest_ensure_response($state);
    }

    private function error(string $code, string $message, int $status): \WP_Error
    {
        return new \WP_Error($code, $message, array('status' => $status));
    }
}

// EOF: includes/Video_Block_Editor_Rest.php
