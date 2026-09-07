<?php
/**
 * File: includes/Editorial_Publish_Validator.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * R46.4 local-only editorial publication validation.
 *
 * This class intentionally knows nothing about PeerTube HTTP, provider-catalog
 * freshness, remote task state, transcoding, or serving readiness. It answers
 * only whether AWVP blocks anchored to one WordPress post have complete,
 * coherent, explicitly reviewed local publication intent.
 */
final class Editorial_Publish_Validator
{
    public const BLOCK_NAME = 'argentwolf-video-processor/video';

    private const MAX_BLOCK_DEPTH = 32;
    private const MAX_REFERENCED_BLOCKS = 128;

    /**
     * @return array{ready:bool,video_ids:list<int>,issues:list<array{video_id:int,code:string,label:string}>}
     */
    public function validate(int $post_id, string $content): array
    {
        if ($post_id < 1 || 1 !== preg_match('//u', $content)) {
            return $this->result(false, array(), array(
                $this->issue(0, 'post', __('AWVP could not validate this post for publication.', 'argentwolf-video-processor')),
            ));
        }

        $references = array();
        $unbound = 0;
        $scan_incomplete = false;
        $visited = array();
        $this->collect_blocks($content, 0, $visited, $references, $unbound, $scan_incomplete);

        $issues = array();
        if ($scan_incomplete) {
            $issues[] = $this->issue(
                0,
                'block_structure',
                __('AWVP could not fully validate the nested or reusable block structure for publication.', 'argentwolf-video-processor')
            );
        }
        if ($unbound > 0) {
            $issues[] = $this->issue(
                0,
                'video_binding',
                __('Choose a WordPress video for every ArgentWolf Video block before publishing.', 'argentwolf-video-processor')
            );
        }

        $video_ids = array_keys($references);
        sort($video_ids, SORT_NUMERIC);
        foreach ($video_ids as $video_id) {
            $this->validate_video($post_id, $video_id, $issues);
        }

        $issues = $this->dedupe_issues($issues);
        return $this->result(array() === $issues, $video_ids, $issues);
    }

    /** @param array<string,bool> $visited @param array<int,bool> $references */
    private function collect_blocks(
        string $content,
        int $depth,
        array &$visited,
        array &$references,
        int &$unbound,
        bool &$scan_incomplete
    ): void {
        if ($depth > self::MAX_BLOCK_DEPTH || ! function_exists('parse_blocks')) {
            $scan_incomplete = true;
            return;
        }

        $blocks = parse_blocks($content);
        if (! is_array($blocks)) {
            $scan_incomplete = true;
            return;
        }
        $this->walk_blocks($blocks, $depth, $visited, $references, $unbound, $scan_incomplete);
    }

    /** @param array<int,mixed> $blocks @param array<string,bool> $visited @param array<int,bool> $references */
    private function walk_blocks(
        array $blocks,
        int $depth,
        array &$visited,
        array &$references,
        int &$unbound,
        bool &$scan_incomplete
    ): void {
        if ($depth > self::MAX_BLOCK_DEPTH) {
            $scan_incomplete = true;
            return;
        }

        foreach ($blocks as $block) {
            if (! is_array($block)) {
                continue;
            }

            if (self::BLOCK_NAME === ($block['blockName'] ?? null)) {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
                $video_id = Video_Meta::sanitize_positive_id($attrs['videoId'] ?? 0);
                if ($video_id < 1) {
                    ++$unbound;
                } else {
                    $references[$video_id] = true;
                }
            }

            // Expand a bounded classic reusable/synced block reference so an
            // AWVP Video cannot bypass validation merely by being nested inside
            // a wp_block post. Cycles remain bounded; exhausting the reviewed
            // reference budget marks the scan incomplete so publication fails
            // closed rather than hiding whatever may exist beyond the boundary.
            if ('core/block' === ($block['blockName'] ?? null)) {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : array();
                $ref = Video_Meta::sanitize_positive_id($attrs['ref'] ?? 0);
                $key = (string) $ref;
                if ($ref > 0 && ! isset($visited[$key])) {
                    if (count($visited) >= self::MAX_REFERENCED_BLOCKS) {
                        $scan_incomplete = true;
                    } else {
                        $visited[$key] = true;
                        $referenced = get_post($ref);
                        if (is_object($referenced)
                            && 'wp_block' === ($referenced->post_type ?? null)
                            && is_string($referenced->post_content ?? null)
                        ) {
                            $this->collect_blocks(
                                $referenced->post_content,
                                $depth + 1,
                                $visited,
                                $references,
                                $unbound,
                                $scan_incomplete
                            );
                        }
                    }
                }
            }

            $inner = $block['innerBlocks'] ?? null;
            if (is_array($inner) && array() !== $inner) {
                $this->walk_blocks($inner, $depth + 1, $visited, $references, $unbound, $scan_incomplete);
            }
        }
    }

    /** @param list<array{video_id:int,code:string,label:string}> $issues */
    private function validate_video(int $post_id, int $video_id, array &$issues): void
    {
        $video = get_post($video_id);
        if (! is_object($video) || Video_Post_Type::POST_TYPE !== ($video->post_type ?? null)) {
            $issues[] = $this->issue(
                $video_id,
                'video_binding',
                __('An ArgentWolf Video block references an unavailable AWVP Video.', 'argentwolf-video-processor')
            );
            return;
        }

        $origin_post_id = Video_Meta::sanitize_positive_id(
            get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true)
        );
        if ($origin_post_id < 1) {
            $issues[] = $this->issue(
                $video_id,
                'anchor',
                __('The AWVP Video has no valid WordPress publication anchor.', 'argentwolf-video-processor')
            );
            return;
        }

        // A reused block is display-only with respect to this post. The first
        // origin post remains the sole WordPress-authoritative publication
        // anchor, so another post must not inherit or re-authorize its plan.
        if ($origin_post_id !== $post_id) {
            return;
        }

        $destination_exists = metadata_exists('post', $video_id, Video_Meta::DESTINATION);
        $destination = Video_Destination::resolve(
            $destination_exists ? get_post_meta($video_id, Video_Meta::DESTINATION, true) : null,
            $destination_exists
        );
        if (array() === $destination) {
            $issues[] = $this->issue(
                $video_id,
                'destination',
                __('Choose or repair this video’s final destination before publishing.', 'argentwolf-video-processor')
            );
            return;
        }
        if (Video_Destination::is_local($destination)) {
            return;
        }

        $plan_exists = metadata_exists('post', $video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN);
        if (! $plan_exists) {
            $this->append_required_review_issues($video_id, array('title', 'channel', 'tags', 'privacy', 'moderation'), $issues);
            return;
        }

        $plan = PeerTube_Publication_Plan::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN, true)
        );
        if (array() === $plan) {
            $issues[] = $this->issue(
                $video_id,
                'invalid_plan',
                __('Repair the stored PeerTube publication plan before publishing.', 'argentwolf-video-processor')
            );
            return;
        }

        if (($destination['backend_id'] ?? null) !== ($plan['backend_id'] ?? null)) {
            $issues[] = $this->issue(
                $video_id,
                'backend',
                __('Review the PeerTube destination because the stored publication plan targets a different backend.', 'argentwolf-video-processor')
            );
        }
        if ($post_id !== ($plan['anchor_post_id'] ?? null)) {
            $issues[] = $this->issue(
                $video_id,
                'anchor',
                __('Review the PeerTube publication plan because its WordPress publication anchor does not match this post.', 'argentwolf-video-processor')
            );
        }
        if (($destination['channel_id'] ?? '') !== ($plan['channel_id'] ?? '')) {
            $issues[] = $this->issue(
                $video_id,
                'channel',
                __('Review the PeerTube channel because the destination and publication plan do not match.', 'argentwolf-video-processor')
            );
        }

        $this->append_required_review_issues(
            $video_id,
            PeerTube_Publication_Plan::missing_review($plan),
            $issues
        );
    }

    /** @param list<string> $missing @param list<array{video_id:int,code:string,label:string}> $issues */
    private function append_required_review_issues(int $video_id, array $missing, array &$issues): void
    {
        $labels = array(
            'title' => __('Review the PeerTube title.', 'argentwolf-video-processor'),
            'channel' => __('Review the PeerTube channel.', 'argentwolf-video-processor'),
            'tags' => __('Review the independent PeerTube tags, including an explicit zero-tag choice when appropriate.', 'argentwolf-video-processor'),
            'privacy' => __('Review the final PeerTube privacy.', 'argentwolf-video-processor'),
            'moderation' => __('Review the sensitive-content declaration.', 'argentwolf-video-processor'),
            'invalid_plan' => __('Repair the stored PeerTube publication plan.', 'argentwolf-video-processor'),
        );

        foreach ($missing as $code) {
            if (! is_string($code) || ! isset($labels[$code])) {
                continue;
            }
            $issues[] = $this->issue($video_id, $code, $labels[$code]);
        }
    }

    /** @param list<array{video_id:int,code:string,label:string}> $issues @return list<array{video_id:int,code:string,label:string}> */
    private function dedupe_issues(array $issues): array
    {
        $out = array();
        $seen = array();
        foreach ($issues as $issue) {
            $key = $issue['video_id'] . ':' . $issue['code'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $issue;
        }
        return $out;
    }

    /** @return array{video_id:int,code:string,label:string} */
    private function issue(int $video_id, string $code, string $label): array
    {
        return array(
            'video_id' => max(0, $video_id),
            'code'     => $code,
            'label'    => $label,
        );
    }

    /** @param list<int> $video_ids @param list<array{video_id:int,code:string,label:string}> $issues */
    private function result(bool $ready, array $video_ids, array $issues): array
    {
        return array(
            'ready'     => $ready,
            'video_ids' => $video_ids,
            'issues'    => $issues,
        );
    }
}

// EOF: includes/Editorial_Publish_Validator.php
