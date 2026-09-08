<?php
/**
 * File: includes/Renderer.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Renderer
{
    public function __construct(private readonly Player $player)
    {
    }

    /** @param array<string, mixed> $block */
    public function render_block(string $content, array $block): string
    {
        $attachment_id = isset($block['attrs']['id']) ? (int) $block['attrs']['id'] : 0;
        if ($attachment_id < 1) {
            $attachment_id = $this->attachment_from_html($content);
        }

        return $this->replace($content, $attachment_id);
    }

    /** @param array<string, mixed> $atts */
    public function render_shortcode(string $output, array $atts): string
    {
        $url = (string) ($atts['src'] ?? $atts['mp4'] ?? $atts['webm'] ?? '');
        $attachment_id = '' !== $url ? attachment_url_to_postid($url) : 0;
        return $this->replace($output, $attachment_id);
    }

    /** Render one AWVP-owned native player without WordPress MediaElement wrapping. */
    public function render_attachment_player(int $attachment_id): string
    {
        if ($attachment_id < 1) {
            return '';
        }
        $url = wp_get_attachment_url($attachment_id);
        $mime = (string) get_post_mime_type($attachment_id);
        if (! is_string($url) || '' === $url || ! str_starts_with($mime, 'video/')) {
            return '';
        }
        $html = sprintf(
            '<video controls playsinline preload="metadata"><source src="%s" type="%s"></video>',
            esc_url($url),
            esc_attr($mime)
        );
        return $this->replace($html, $attachment_id);
    }

    private function replace(string $html, int $attachment_id): string
    {
        if ($attachment_id < 1) {
            return $html;
        }

        $outputs = get_post_meta($attachment_id, '_argent_video_outputs', true);
        if (! is_array($outputs) || [] === $outputs) {
            return $html;
        }
        if (! empty($outputs['hls']['url'])) {
            $this->player->enqueue();
        }

        return (string) preg_replace_callback(
            '~<video\b([^>]*)>(.*?)</video>~is',
            function (array $matches) use ($outputs, $attachment_id): string {
                $attributes = preg_replace('/\s+src\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $matches[1]);
                $attributes = preg_replace('/\s+preload\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $attributes);
                $attributes = preg_replace('/\s+data-argent-hls\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $attributes);
                $attributes = preg_replace('/\s+data-argent-fallback\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', (string) $attributes);
                $attributes = preg_replace('/\s+autoplay(?:\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+))?/i', '', (string) $attributes);
                $inner = preg_replace('~<source\b[^>]*>~i', '', (string) $matches[2]);
                $sources = '';

                foreach (array('webm', 'mp4') as $key) {
                    if (! empty($outputs[$key]['url']) && ! empty($outputs[$key]['mime'])) {
                        $sources .= sprintf(
                            '<source src="%s" type="%s">',
                            esc_url((string) $outputs[$key]['url']),
                            esc_attr((string) $outputs[$key]['mime'])
                        );
                    }
                }

                if (empty($outputs['mp4'])) {
                    $original = wp_get_attachment_url($attachment_id);
                    $original_mime = (string) get_post_mime_type($attachment_id);
                    if (is_string($original) && '' !== $original) {
                        $sources .= '<source src="' . esc_url($original) . '" type="' . esc_attr($original_mime ?: 'video/mp4') . '">';
                    }
                }

                $hls_attribute = ! empty($outputs['hls']['url'])
                    ? ' data-argent-hls="' . esc_url((string) $outputs['hls']['url']) . '"'
                    : '';
                $fallback = ! empty($outputs['mp4']['url'])
                    ? (string) $outputs['mp4']['url']
                    : wp_get_attachment_url($attachment_id);
                $fallback_attribute = is_string($fallback) && '' !== $fallback
                    ? ' data-argent-fallback="' . esc_url($fallback) . '"'
                    : '';

                return '<video' . $attributes . $hls_attribute . $fallback_attribute . ' preload="metadata">' . $sources . $inner . '</video>';
            },
            $html,
            1
        );
    }

    private function attachment_from_html(string $html): int
    {
        if (preg_match('/<video\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $html, $match)) {
            return attachment_url_to_postid(html_entity_decode($match[1], ENT_QUOTES));
        }

        return 0;
    }
}

// EOF: includes/Renderer.php
