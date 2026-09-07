<?php
/**
 * File: includes/Video_Block.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** R46.3b dynamic AWVP Video block registration and local-first rendering. */
final class Video_Block
{
    public const NAME = 'argentwolf-video-processor/video';

    public function __construct(private readonly ?Video_Serving_Resolver $serving = null)
    {
    }

    public function register(): void
    {
        register_block_type(
            ARGENT_VIDEO_DIR . 'blocks/video',
            array('render_callback' => array($this, 'render'))
        );
    }

    /** @param array<string,mixed> $attributes */
    public function render(array $attributes, string $content = '', mixed $block = null): string
    {
        unset($content, $block);
        $video_id = Video_Meta::sanitize_positive_id($attributes['videoId'] ?? null);
        if ($video_id < 1) {
            return '';
        }
        $video = get_post($video_id);
        if (! is_object($video)
            || Video_Post_Type::POST_TYPE !== ($video->post_type ?? null)
            || 'trash' === ($video->post_status ?? null)) {
            return '';
        }

        $attachment_id = Video_Meta::sanitize_positive_id(
            get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true)
        );
        if ($attachment_id < 1) {
            return '';
        }
        $attachment = get_post($attachment_id);
        $mime = (string) get_post_mime_type($attachment_id);
        $url = wp_get_attachment_url($attachment_id);
        if (! is_object($attachment)
            || 'attachment' !== ($attachment->post_type ?? null)
            || 'trash' === ($attachment->post_status ?? null)
            || ! str_starts_with($mime, 'video/')
            || ! is_string($url)
            || '' === $url) {
            return '';
        }

        $embed_url = null !== $this->serving ? $this->serving->peertube_embed_url($video_id) : '';
        if ('' !== $embed_url) {
            $title = get_the_title($video_id);
            $title = is_string($title) && '' !== $title ? $title : __('Video', 'argentwolf-video-processor');
            $player = sprintf(
                '<div class="awvp-peertube-embed"><iframe src="%s" title="%s" loading="lazy" allow="fullscreen; picture-in-picture" allowfullscreen></iframe></div>',
                esc_url($embed_url),
                esc_attr($title)
            );
        } else {
            // Missing, stale, or uncertain remote serving evidence always keeps
            // the established local WordPress/Renderer path authoritative.
            $player = wp_video_shortcode(
                array(
                    'src'     => $url,
                    'preload' => 'metadata',
                )
            );
        }
        if (! is_string($player) || '' === $player) {
            return '';
        }

        $wrapper = function_exists('get_block_wrapper_attributes')
            ? get_block_wrapper_attributes(array('data-awvp-video-id' => (string) $video_id))
            : 'class="wp-block-argentwolf-video-processor-video" data-awvp-video-id="' . esc_attr((string) $video_id) . '"';

        return '<div ' . $wrapper . '>' . $player . '</div>';
    }
}

// EOF: includes/Video_Block.php
