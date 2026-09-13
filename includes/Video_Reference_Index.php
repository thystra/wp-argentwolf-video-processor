<?php
/**
 * File: includes/Video_Reference_Index.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Read-only, bounded index of WordPress posts that reference AWVP/local videos.
 *
 * The index understands the canonical AWVP Video block plus the legacy
 * core/video and [video] forms supported by the runtime serving bridge. It is
 * deliberately an administrator-side reporting helper and never rewrites post
 * content or binding metadata.
 */
final class Video_Reference_Index
{
    public const MAX_POSTS = 5000;

    /** @var array<int,list<int>>|null */
    private ?array $by_video = null;

    /** @var array<int,list<int>>|null */
    private ?array $by_attachment = null;

    private bool $truncated = false;

    /**
     * @return list<array{id:int,title:string,status:string,post_type:string,is_origin:bool}>
     */
    public function posts_for(int $video_id, int $attachment_id, int $origin_post_id = 0): array
    {
        $this->build();

        $ids = array();
        if ($origin_post_id > 0) {
            $ids[$origin_post_id] = true;
        }
        if ($video_id > 0) {
            foreach ($this->by_video[$video_id] ?? array() as $post_id) {
                $ids[$post_id] = true;
            }
        }
        if ($attachment_id > 0) {
            foreach ($this->by_attachment[$attachment_id] ?? array() as $post_id) {
                $ids[$post_id] = true;
            }
        }

        $post_ids = array_keys($ids);
        usort(
            $post_ids,
            static function (int $a, int $b) use ($origin_post_id): int {
                if ($a === $origin_post_id) {
                    return -1;
                }
                if ($b === $origin_post_id) {
                    return 1;
                }
                return $a <=> $b;
            }
        );

        return $this->post_rows($post_ids, $origin_post_id);
    }


    /**
     * Return posts that directly reference one WordPress attachment through a
     * core Video block, legacy [video] shortcode, or literal video/source URL.
     * AWVP Video blocks are intentionally excluded because they remain valid
     * after the local attachment is retired.
     *
     * @return list<array{id:int,title:string,status:string,post_type:string,is_origin:bool}>
     */
    public function attachment_posts_for(int $attachment_id): array
    {
        $this->build();
        if ($attachment_id < 1) {
            return array();
        }
        return $this->post_rows($this->by_attachment[$attachment_id] ?? array(), 0);
    }

    public function truncated(): bool
    {
        $this->build();
        return $this->truncated;
    }


    /** @param list<int> $post_ids @return list<array{id:int,title:string,status:string,post_type:string,is_origin:bool}> */
    private function post_rows(array $post_ids, int $origin_post_id): array
    {
        $out = array();
        foreach ($post_ids as $post_id) {
            $post = get_post($post_id);
            if (! is_object($post)
                || in_array((string) ($post->post_type ?? ''), array('attachment', 'revision', Video_Post_Type::POST_TYPE), true)
                || 'trash' === ($post->post_status ?? null)
            ) {
                continue;
            }
            $title = sanitize_text_field((string) ($post->post_title ?? ''));
            if ('' === $title) {
                $title = sprintf(
                    /* translators: %d: WordPress post ID. */
                    __('Post #%d', 'argentwolf-video-processor'),
                    $post_id
                );
            }
            $out[] = array(
                'id'        => $post_id,
                'title'     => $title,
                'status'    => sanitize_key((string) ($post->post_status ?? '')),
                'post_type' => sanitize_key((string) ($post->post_type ?? '')),
                'is_origin' => $post_id === $origin_post_id,
            );
        }
        return $out;
    }

    private function build(): void
    {
        if (null !== $this->by_video && null !== $this->by_attachment) {
            return;
        }

        $this->by_video = array();
        $this->by_attachment = array();

        $ids = get_posts(array(
            'post_type'      => self::content_post_types(),
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => self::MAX_POSTS + 1,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ));
        if (! is_array($ids)) {
            return;
        }

        if (count($ids) > self::MAX_POSTS) {
            $this->truncated = true;
            $ids = array_slice($ids, 0, self::MAX_POSTS);
        }

        foreach ($ids as $raw_post_id) {
            $post_id = Video_Meta::sanitize_positive_id($raw_post_id);
            $post = $post_id > 0 ? get_post($post_id) : null;
            if (! is_object($post)
                || in_array((string) ($post->post_type ?? ''), array('attachment', 'revision', Video_Post_Type::POST_TYPE), true)
                || 'trash' === ($post->post_status ?? null)
            ) {
                continue;
            }
            $content = is_string($post->post_content ?? null) ? (string) $post->post_content : '';
            if ('' === $content) {
                continue;
            }

            if (function_exists('parse_blocks')) {
                $blocks = parse_blocks($content);
                if (is_array($blocks)) {
                    $this->collect_blocks($blocks, $post_id);
                }
            }
            $this->collect_legacy_markup($content, $post_id);
        }
    }


    /**
     * Scan only post types that can reasonably contain authored block/classic
     * content. In particular, do not spend the safety limit on Media Library
     * attachments; large libraries otherwise make destructive reference checks
     * fail closed before AWVP has examined the site's actual content posts.
     *
     * @return array<int,string>|string
     */
    private static function content_post_types(): array|string
    {
        if (! function_exists('get_post_types')) {
            return 'any';
        }
        $registered = get_post_types(array(), 'names');
        if (! is_array($registered)) {
            return 'any';
        }

        $always_content = array('wp_block', 'wp_template', 'wp_template_part');
        $excluded = array('attachment', 'revision', Video_Post_Type::POST_TYPE, 'nav_menu_item', 'custom_css', 'customize_changeset', 'oembed_cache', 'user_request');
        $types = array();
        foreach ($registered as $raw_type) {
            $type = is_string($raw_type) ? $raw_type : '';
            if ('' === $type || in_array($type, $excluded, true)) {
                continue;
            }
            if (in_array($type, $always_content, true)
                || ! function_exists('post_type_supports')
                || post_type_supports($type, 'editor')
            ) {
                $types[] = $type;
            }
        }
        $types = array_values(array_unique($types));
        return array() !== $types ? $types : 'any';
    }

    /** @param list<array<string,mixed>> $blocks */
    private function collect_blocks(array $blocks, int $post_id): void
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            $name = is_string($block['blockName'] ?? null) ? (string) $block['blockName'] : '';
            if (Video_Block::NAME === $name) {
                $video_id = Video_Meta::sanitize_positive_id($block['attrs']['videoId'] ?? null);
                if ($video_id > 0) {
                    $this->remember($this->by_video, $video_id, $post_id);
                }
            } elseif ('core/video' === $name) {
                $attachment_id = Video_Meta::sanitize_positive_id($block['attrs']['id'] ?? null);
                if ($attachment_id > 0) {
                    $this->remember($this->by_attachment, $attachment_id, $post_id);
                }
            }
            if (is_array($block['innerBlocks'] ?? null) && array() !== $block['innerBlocks']) {
                $this->collect_blocks($block['innerBlocks'], $post_id);
            }
        }
    }

    private function collect_legacy_markup(string $content, int $post_id): void
    {
        if (preg_match_all('/\[video\b([^\]]*)\]/i', $content, $matches)) {
            foreach ($matches[1] as $raw_atts) {
                if (! is_string($raw_atts)) {
                    continue;
                }
                if (preg_match('/\bid\s*=\s*["\']?([1-9][0-9]*)/i', $raw_atts, $id_match)) {
                    $attachment_id = Video_Meta::sanitize_positive_id($id_match[1]);
                    if ($attachment_id > 0) {
                        $this->remember($this->by_attachment, $attachment_id, $post_id);
                    }
                }
                if (preg_match_all('/\b(?:src|mp4|webm)\s*=\s*["\']([^"\']+)["\']/i', $raw_atts, $url_matches)) {
                    foreach ($url_matches[1] as $url) {
                        $this->remember_url_attachment($url, $post_id);
                    }
                }
            }
        }

        if (preg_match_all('/<(?:video|source)\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']/i', $content, $matches)) {
            foreach ($matches[1] as $url) {
                $this->remember_url_attachment($url, $post_id);
            }
        }
    }

    private function remember_url_attachment(mixed $url, int $post_id): void
    {
        if (! is_string($url) || ! function_exists('attachment_url_to_postid')) {
            return;
        }
        $attachment_id = Video_Meta::sanitize_positive_id(
            attachment_url_to_postid(html_entity_decode($url, ENT_QUOTES))
        );
        if ($attachment_id > 0) {
            $this->remember($this->by_attachment, $attachment_id, $post_id);
        }
    }

    /** @param array<int,list<int>> $index */
    private function remember(array &$index, int $key, int $post_id): void
    {
        if ($key < 1 || $post_id < 1) {
            return;
        }
        if (! isset($index[$key])) {
            $index[$key] = array();
        }
        if (! in_array($post_id, $index[$key], true)) {
            $index[$key][] = $post_id;
        }
    }
}

// EOF: includes/Video_Reference_Index.php
