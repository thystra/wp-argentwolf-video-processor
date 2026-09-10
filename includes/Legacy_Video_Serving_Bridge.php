<?php
/**
 * File: includes/Legacy_Video_Serving_Bridge.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Runtime-only serving bridge for explicitly adopted historical video content.
 *
 * Stored core/video blocks and supported [video] shortcodes remain byte-identical.
 * Before verified 2.0 serving authority this filter is inert; afterward only the
 * adopted video's unambiguous origin post may render the verified PeerTube embed.
 */
final class Legacy_Video_Serving_Bridge
{
    public const STYLE_HANDLE = 'argentwolf-video-processor-video-style';

    public function __construct(private readonly Video_Serving_Resolver $serving)
    {
    }

    /** @param array<string,mixed> $block */
    public function render_block(string $content, array $block): string
    {
        $attachment_id = Video_Meta::sanitize_positive_id($block['attrs']['id'] ?? null);
        return $this->bridge($content, $attachment_id);
    }

    /** @param array<string,mixed> $atts */
    public function render_shortcode(string $output, array $atts): string
    {
        $attachment_id = Video_Meta::sanitize_positive_id($atts['id'] ?? null);
        if ($attachment_id < 1) {
            $url = (string) ($atts['src'] ?? $atts['mp4'] ?? $atts['webm'] ?? '');
            $attachment_id = '' !== $url ? attachment_url_to_postid($url) : 0;
        }
        return $this->bridge($output, $attachment_id);
    }

    private function bridge(string $local_html, int $attachment_id): string
    {
        if ($attachment_id < 1
            || ! metadata_exists('post', $attachment_id, Legacy_Video_Adoption_Service::ATTACHMENT_ASSET_META)
        ) {
            return $local_html;
        }
        $video_id = Video_Meta::sanitize_positive_id(
            get_post_meta($attachment_id, Legacy_Video_Adoption_Service::ATTACHMENT_ASSET_META, true)
        );
        $video = $video_id > 0 ? get_post($video_id) : null;
        if (! is_object($video)
            || Video_Post_Type::POST_TYPE !== ($video->post_type ?? null)
            || 'trash' === ($video->post_status ?? null)
            || $attachment_id !== Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true))
        ) {
            return $local_html;
        }

        // Historical attachments reused across posts are deliberately not
        // rerouted globally. Only the durable adoption anchor follows the new
        // serving authority; all other contexts retain their local rendering.
        $anchor_post_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true));
        $current_post_id = function_exists('get_the_ID') ? Video_Meta::sanitize_positive_id(get_the_ID()) : 0;
        if ($anchor_post_id < 1 || $current_post_id < 1 || $anchor_post_id !== $current_post_id) {
            return $local_html;
        }

        $embed_url = $this->serving->peertube_embed_url($video_id);
        if ('' === $embed_url) {
            return $local_html;
        }
        if (function_exists('wp_enqueue_style')) {
            wp_enqueue_style(self::STYLE_HANDLE);
        }
        $title = get_the_title($video_id);
        $title = is_string($title) && '' !== trim($title) ? $title : __('Video', 'argentwolf-video-processor');

        return sprintf(
            '<div class="wp-block-argentwolf-video-processor-video awvp-legacy-serving-bridge" data-awvp-video-id="%d"><div class="awvp-peertube-embed"><iframe src="%s" title="%s" loading="lazy" allow="fullscreen; picture-in-picture" allowfullscreen></iframe></div></div>',
            $video_id,
            esc_url($embed_url),
            esc_attr($title)
        );
    }
}

// EOF: includes/Legacy_Video_Serving_Bridge.php
