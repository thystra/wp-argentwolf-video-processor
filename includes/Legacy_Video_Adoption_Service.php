<?php
/**
 * File: includes/Legacy_Video_Adoption_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Read-only discovery plus explicit adoption of completed AWVP 1.x attachments.
 *
 * Discovery never creates 2.0 model objects. Adoption is called only from an
 * administrator migration-planning action and establishes the minimum durable
 * local 2.0 identity needed for the existing migration planner.
 */
final class Legacy_Video_Adoption_Service
{
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';

    public const LEGACY_STATUS_META = '_argent_video_status';
    public const LEGACY_OUTPUTS_META = '_argent_video_outputs';
    public const ATTACHMENT_ASSET_META = '_argent_video_asset_id';

    private const LEGACY_COMPLETE = 'complete';
    private const SCAN_BATCH = 200;
    private const MAX_SCAN_ROWS = 5000;
    private const MAX_ANCHOR_POSTS = 10000;
    private const LOCK_PREFIX = 'argentwolf_video_processor_legacy_adopt_lock_';
    private const LOCK_TTL_SECONDS = 120;

    /** @var array<int,list<int>>|null */
    private ?array $anchor_index = null;

    /**
     * @return array{items:list<array<string,mixed>>,more:bool}
     */
    public function candidates(int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);
        $wanted = $offset + $limit + 1;
        $eligible = array();
        $db_offset = 0;
        $scanned = 0;
        $exhausted = false;

        while (count($eligible) < $wanted && $scanned < self::MAX_SCAN_ROWS) {
            $batch = min(self::SCAN_BATCH, self::MAX_SCAN_ROWS - $scanned);
            $ids = $this->legacy_attachment_ids($batch, $db_offset);
            if (array() === $ids) {
                $exhausted = true;
                break;
            }
            $count = count($ids);
            $db_offset += $count;
            $scanned += $count;
            foreach ($ids as $attachment_id) {
                $inspection = $this->inspect($attachment_id);
                if ('eligible' !== ($inspection['status'] ?? null)) {
                    continue;
                }
                $eligible[] = $this->candidate_row($inspection);
                if (count($eligible) >= $wanted) {
                    break;
                }
            }
            if ($count < $batch) {
                $exhausted = true;
                break;
            }
        }

        return array(
            'items' => array_slice($eligible, $offset, $limit),
            'more'  => count($eligible) > $offset + $limit || ! $exhausted,
        );
    }

    /**
     * Return bounded operator-facing legacy discovery counts. No state changes.
     *
     * @return array{completed:int,eligible:int,bound:int,incomplete:int,source_missing:int,anchor_missing:int,anchor_ambiguous:int,invalid:int,more:bool}
     */
    public function census(): array
    {
        $counts = array(
            'completed'        => 0,
            'eligible'         => 0,
            'bound'            => 0,
            'incomplete'       => 0,
            'source_missing'   => 0,
            'anchor_missing'   => 0,
            'anchor_ambiguous' => 0,
            'invalid'          => 0,
            'more'             => false,
        );
        $db_offset = 0;
        $scanned = 0;
        while ($scanned < self::MAX_SCAN_ROWS) {
            $batch = min(self::SCAN_BATCH, self::MAX_SCAN_ROWS - $scanned);
            $ids = $this->legacy_attachment_ids($batch, $db_offset);
            if (array() === $ids) {
                break;
            }
            $count = count($ids);
            $db_offset += $count;
            $scanned += $count;
            foreach ($ids as $attachment_id) {
                ++$counts['completed'];
                $status = (string) ($this->inspect($attachment_id)['status'] ?? 'invalid');
                if (array_key_exists($status, $counts) && 'completed' !== $status && 'more' !== $status) {
                    ++$counts[$status];
                } else {
                    ++$counts['invalid'];
                }
            }
            if ($count < $batch) {
                return $counts;
            }
        }
        if ($scanned >= self::MAX_SCAN_ROWS) {
            $counts['more'] = true;
        }
        return $counts;
    }

    /**
     * Inspect one completed legacy attachment without mutating it.
     *
     * @return array{status:string,attachment_id:int,anchor_post_id:int,video_id:int,title:string,post_status:string,reason:string}
     */
    public function inspect(int $attachment_id): array
    {
        $result = array(
            'status'         => 'invalid',
            'attachment_id'  => max(0, $attachment_id),
            'anchor_post_id' => 0,
            'video_id'       => 0,
            'title'          => '',
            'post_status'    => '',
            'reason'         => 'invalid_attachment',
        );
        if ($attachment_id < 1 || ! wp_attachment_is('video', $attachment_id)) {
            return $result;
        }
        $attachment = get_post($attachment_id);
        if (! is_object($attachment) || 'attachment' !== ($attachment->post_type ?? null) || 'trash' === ($attachment->post_status ?? null)) {
            return $result;
        }
        $result['title'] = $this->title($attachment);

        if (metadata_exists('post', $attachment_id, self::ATTACHMENT_ASSET_META)) {
            $video_id = Video_Meta::sanitize_positive_id(
                get_post_meta($attachment_id, self::ATTACHMENT_ASSET_META, true)
            );
            if ($video_id > 0 && $this->valid_existing_binding($video_id, $attachment_id)) {
                $result['status'] = 'bound';
                $result['video_id'] = $video_id;
                $result['reason'] = 'already_adopted';
            } else {
                $result['reason'] = 'invalid_reverse_binding';
            }
            return $result;
        }

        if (self::LEGACY_COMPLETE !== (string) get_post_meta($attachment_id, self::LEGACY_STATUS_META, true)) {
            $result['status'] = 'incomplete';
            $result['reason'] = 'legacy_processing_not_complete';
            return $result;
        }
        $outputs = get_post_meta($attachment_id, self::LEGACY_OUTPUTS_META, true);
        if (! is_array($outputs) || array() === $outputs) {
            $result['status'] = 'incomplete';
            $result['reason'] = 'legacy_outputs_missing';
            return $result;
        }
        $source = get_attached_file($attachment_id, true);
        if (! is_string($source) || '' === $source || ! is_file($source)) {
            $result['status'] = 'source_missing';
            $result['reason'] = 'wordpress_source_missing';
            return $result;
        }

        $anchors = $this->anchor_post_ids($attachment_id);
        if (array() === $anchors) {
            $result['status'] = 'anchor_missing';
            $result['reason'] = 'publication_anchor_not_found';
            return $result;
        }
        if (1 !== count($anchors)) {
            $result['status'] = 'anchor_ambiguous';
            $result['reason'] = 'multiple_publication_anchors';
            return $result;
        }

        $anchor = get_post($anchors[0]);
        if (! is_object($anchor) || 'trash' === ($anchor->post_status ?? null)) {
            $result['status'] = 'anchor_missing';
            $result['reason'] = 'publication_anchor_invalid';
            return $result;
        }

        $result['status'] = 'eligible';
        $result['anchor_post_id'] = $anchors[0];
        $result['post_status'] = is_string($anchor->post_status ?? null) ? $anchor->post_status : '';
        $result['reason'] = '';
        return $result;
    }

    /**
     * Explicitly adopt one already-inspected 1.x attachment into the 2.0 model.
     * This method never queues FFmpeg/PeerTube work and never edits post_content.
     *
     * @return array{status:string,video_id:int,reason:string}
     */
    public function adopt(int $attachment_id, ?int $now = null): array
    {
        if ($attachment_id < 1) {
            return self::adoption_result(self::REFUSED, 0, 'invalid_attachment');
        }
        $now = null === $now ? time() : $now;
        if ($now < 1) {
            return self::adoption_result(self::REFUSED, 0, 'invalid_time');
        }

        $lock = $this->acquire_lock($attachment_id, $now);
        if (null === $lock) {
            return self::adoption_result(self::REFUSED, 0, 'adoption_busy');
        }
        try {
            $inspection = $this->inspect($attachment_id);
            if ('bound' === $inspection['status'] && $inspection['video_id'] > 0) {
                return self::adoption_result(self::PRESENT, $inspection['video_id'], 'already_adopted');
            }
            if ('eligible' !== $inspection['status']) {
                return self::adoption_result(self::REFUSED, 0, (string) $inspection['reason']);
            }

            $anchor = get_post((int) $inspection['anchor_post_id']);
            $attachment = get_post($attachment_id);
            if (! is_object($anchor) || ! is_object($attachment)) {
                return self::adoption_result(self::REFUSED, 0, 'adoption_context_missing');
            }
            $author_id = Video_Meta::sanitize_positive_id($anchor->post_author ?? null);
            if ($author_id < 1) {
                $author_id = Video_Meta::sanitize_positive_id($attachment->post_author ?? null);
            }

            $candidate = wp_insert_post(
                array(
                    'post_type'    => Video_Post_Type::POST_TYPE,
                    'post_status'  => 'publish',
                    'post_title'   => (string) $inspection['title'],
                    'post_content' => '',
                    'post_author'  => $author_id,
                ),
                true
            );
            if (is_wp_error($candidate) || ! is_int($candidate) || $candidate < 1) {
                return self::adoption_result(self::INDETERMINATE, 0, 'video_create_failed');
            }

            if (! $this->initialize_candidate($candidate, $attachment_id, (int) $inspection['anchor_post_id'])) {
                $this->discard_candidate($candidate);
                return self::adoption_result(self::INDETERMINATE, 0, 'video_initialize_failed');
            }

            if (add_post_meta($attachment_id, self::ATTACHMENT_ASSET_META, $candidate, true)) {
                $claimed = Video_Meta::sanitize_positive_id(
                    get_post_meta($attachment_id, self::ATTACHMENT_ASSET_META, true)
                );
                return $candidate === $claimed
                    ? self::adoption_result(self::APPLIED, $candidate, '')
                    : self::adoption_result(self::INDETERMINATE, $candidate, 'reverse_binding_unconfirmed');
            }

            $winner = Video_Meta::sanitize_positive_id(
                get_post_meta($attachment_id, self::ATTACHMENT_ASSET_META, true)
            );
            $discarded = $this->discard_candidate($candidate);
            if ($discarded && $winner > 0 && $this->valid_existing_binding($winner, $attachment_id)) {
                return self::adoption_result(self::PRESENT, $winner, 'already_adopted');
            }
            return self::adoption_result(self::INDETERMINATE, 0, 'reverse_binding_conflict');
        } finally {
            $this->release_lock($attachment_id, $lock);
        }
    }

    /** @param array<string,mixed> $inspection @return array<string,mixed> */
    private function candidate_row(array $inspection): array
    {
        $attachment_id = (int) $inspection['attachment_id'];
        return array(
            'candidate_key' => 'legacy:' . $attachment_id,
            'legacy'        => true,
            'video_id'      => 0,
            'attachment_id' => $attachment_id,
            'anchor_post_id'=> (int) $inspection['anchor_post_id'],
            'title'         => (string) $inspection['title'],
            'post_status'   => (string) $inspection['post_status'],
            'plan_status'   => 'legacy_ready_for_adoption',
            'issues'        => array(),
            'backend_id'    => '',
            'channel_id'    => '',
        );
    }

    /** @return list<int> */
    private function legacy_attachment_ids(int $limit, int $offset): array
    {
        // phpcs:disable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Administrator-only bounded legacy discovery intentionally filters 1.x completion metadata.
        $ids = get_posts(array(
            'post_type'      => 'attachment',
            'post_status'    => 'any',
            'post_mime_type' => 'video',
            'fields'         => 'ids',
            'posts_per_page' => $limit,
            'offset'         => $offset,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => self::LEGACY_STATUS_META,
            'meta_value'     => self::LEGACY_COMPLETE,
            'no_found_rows'  => true,
        ));
        // phpcs:enable WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
        if (! is_array($ids)) {
            return array();
        }
        $out = array();
        foreach ($ids as $id) {
            $attachment_id = Video_Meta::sanitize_positive_id($id);
            if ($attachment_id > 0) {
                $out[] = $attachment_id;
            }
        }
        return $out;
    }

    /** @return list<int> */
    private function anchor_post_ids(int $attachment_id): array
    {
        if (null === $this->anchor_index) {
            $this->anchor_index = $this->build_anchor_index();
        }
        return $this->anchor_index[$attachment_id] ?? array();
    }

    /** @return array<int,list<int>> */
    private function build_anchor_index(): array
    {
        $ids = get_posts(array(
            'post_type'      => 'any',
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => self::MAX_ANCHOR_POSTS,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'no_found_rows'  => true,
        ));
        if (! is_array($ids)) {
            return array();
        }
        $index = array();
        foreach ($ids as $raw_id) {
            $post_id = Video_Meta::sanitize_positive_id($raw_id);
            $post = $post_id > 0 ? get_post($post_id) : null;
            if (! is_object($post)
                || in_array((string) ($post->post_type ?? ''), array('attachment', 'revision', Video_Post_Type::POST_TYPE), true)
                || 'trash' === ($post->post_status ?? null)
            ) {
                continue;
            }
            $content = is_string($post->post_content ?? null) ? $post->post_content : '';
            if ('' === $content) {
                continue;
            }
            foreach ($this->content_attachment_ids($content) as $attachment_id) {
                if (! isset($index[$attachment_id])) {
                    $index[$attachment_id] = array();
                }
                if (! in_array($post_id, $index[$attachment_id], true)) {
                    $index[$attachment_id][] = $post_id;
                }
            }
        }
        return $index;
    }

    /** @return list<int> */
    private function content_attachment_ids(string $content): array
    {
        $ids = array();
        if (function_exists('parse_blocks')) {
            $blocks = parse_blocks($content);
            if (is_array($blocks)) {
                $this->collect_block_attachment_ids($blocks, $ids);
            }
        }

        if (preg_match_all('/\[video\b([^\]]*)\]/i', $content, $matches)) {
            foreach ($matches[1] as $raw_atts) {
                if (! is_string($raw_atts)) {
                    continue;
                }
                if (preg_match('/\bid\s*=\s*["\']?([1-9][0-9]*)/i', $raw_atts, $id_match)) {
                    $id = Video_Meta::sanitize_positive_id($id_match[1]);
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
                if (preg_match('/\b(?:src|mp4|webm)\s*=\s*["\']([^"\']+)["\']/i', $raw_atts, $url_match)) {
                    $id = attachment_url_to_postid(html_entity_decode($url_match[1], ENT_QUOTES));
                    if ($id > 0) {
                        $ids[] = $id;
                    }
                }
            }
        }

        $ids = array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
        sort($ids, SORT_NUMERIC);
        return $ids;
    }

    /** @param list<array<string,mixed>> $blocks @param list<int> $ids */
    private function collect_block_attachment_ids(array $blocks, array &$ids): void
    {
        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }
            if ('core/video' === ($block['blockName'] ?? null)) {
                $id = Video_Meta::sanitize_positive_id($block['attrs']['id'] ?? null);
                if ($id > 0) {
                    $ids[] = $id;
                }
            }
            if (is_array($block['innerBlocks'] ?? null) && array() !== $block['innerBlocks']) {
                $this->collect_block_attachment_ids($block['innerBlocks'], $ids);
            }
        }
    }

    private function valid_existing_binding(int $video_id, int $attachment_id): bool
    {
        $video = get_post($video_id);
        return is_object($video)
            && Video_Post_Type::POST_TYPE === ($video->post_type ?? null)
            && 'trash' !== ($video->post_status ?? null)
            && $attachment_id === Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true));
    }

    private function initialize_candidate(int $video_id, int $attachment_id, int $anchor_post_id): bool
    {
        update_post_meta($video_id, Video_Meta::ATTACHMENT_ID, $attachment_id);
        update_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, $anchor_post_id);
        update_post_meta($video_id, Video_Meta::INGEST_KIND, 'wordpress_attachment');
        update_post_meta($video_id, Video_Meta::MASTER_AUTHORITY, 'wordpress_source');
        update_post_meta($video_id, Video_Meta::SOURCE_STATE, 'present');
        update_post_meta($video_id, Video_Meta::DESTINATION, Video_Destination::local());

        return $attachment_id === Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true))
            && $anchor_post_id === Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true))
            && 'wordpress_attachment' === Video_Meta::sanitize_ingest_kind(get_post_meta($video_id, Video_Meta::INGEST_KIND, true))
            && 'wordpress_source' === Video_Meta::sanitize_master_authority(get_post_meta($video_id, Video_Meta::MASTER_AUTHORITY, true))
            && 'present' === Video_Meta::sanitize_source_state(get_post_meta($video_id, Video_Meta::SOURCE_STATE, true))
            && Video_Destination::local() === Video_Destination::sanitize(get_post_meta($video_id, Video_Meta::DESTINATION, true));
    }

    private function discard_candidate(int $video_id): bool
    {
        $deleted = wp_delete_post($video_id, true);
        return is_object($deleted) || false === get_post($video_id);
    }

    private function title(object $attachment): string
    {
        $title = sanitize_text_field((string) ($attachment->post_title ?? ''));
        return '' !== $title ? $title : __('Video', 'argentwolf-video-processor');
    }

    /** @return array{token:string,expires_at:int}|null */
    private function acquire_lock(int $attachment_id, int $now): ?array
    {
        try {
            $token = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            return null;
        }
        $value = array('token' => $token, 'expires_at' => $now + self::LOCK_TTL_SECONDS);
        $name = self::LOCK_PREFIX . $attachment_id;
        if (add_option($name, $value, '', 'no')) {
            return $value;
        }
        $before = get_option($name, null);
        if (is_array($before) && is_int($before['expires_at'] ?? null) && $before['expires_at'] < $now) {
            delete_option($name);
            if (add_option($name, $value, '', 'no')) {
                return $value;
            }
        }
        return null;
    }

    /** @param array{token:string,expires_at:int} $lock */
    private function release_lock(int $attachment_id, array $lock): void
    {
        $name = self::LOCK_PREFIX . $attachment_id;
        $current = get_option($name, null);
        if (is_array($current) && isset($current['token']) && hash_equals((string) $lock['token'], (string) $current['token'])) {
            delete_option($name);
        }
    }

    /** @return array{status:string,video_id:int,reason:string} */
    private static function adoption_result(string $status, int $video_id, string $reason): array
    {
        return array('status' => $status, 'video_id' => max(0, $video_id), 'reason' => $reason);
    }
}

// EOF: includes/Legacy_Video_Adoption_Service.php
