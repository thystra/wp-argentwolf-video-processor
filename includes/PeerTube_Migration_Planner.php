<?php
/**
 * File: includes/PeerTube_Migration_Planner.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * R46.7 planner for local-to-PeerTube migration.
 *
 * This service is intentionally inert: it writes only PEERTUBE_MIGRATION_PLAN.
 * It never edits the live destination/publication plan, enqueues a task, performs
 * provider HTTP, or changes serving authority.
 */
final class PeerTube_Migration_Planner
{
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';
    public const MAX_SELECT_ALL = 500;
    private const SCAN_BATCH = 200;
    private const MAX_SCAN_ROWS = 5000;

    public function __construct(
        private readonly Backend_Registry $registry,
        private readonly Video_Publishing_Defaults_Store $defaults,
        private readonly PeerTube_Publication_Catalog_Store $catalogs
    ) {
    }

    /**
     * @return array{items:list<array<string,mixed>>,more:bool}
     */
    public function candidates(int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(self::MAX_SELECT_ALL, $limit));
        $offset = max(0, $offset);
        $wanted = $offset + $limit + 1;
        $eligible = array();
        $db_offset = 0;
        $scanned = 0;
        $exhausted = false;
        while (count($eligible) < $wanted && $scanned < self::MAX_SCAN_ROWS) {
            $batch = min(self::SCAN_BATCH, self::MAX_SCAN_ROWS - $scanned);
            $ids = get_posts(array(
                'post_type'      => Video_Post_Type::POST_TYPE,
                'post_status'    => 'any',
                'fields'         => 'ids',
                'posts_per_page' => $batch,
                'offset'         => $db_offset,
                'orderby'        => 'ID',
                'order'          => 'ASC',
                'no_found_rows'  => true,
            ));
            if (! is_array($ids) || array() === $ids) {
                $exhausted = true;
                break;
            }
            $count = count($ids);
            $db_offset += $count;
            $scanned += $count;
            foreach ($ids as $id) {
                $video_id = Video_Meta::sanitize_positive_id($id);
                if ($video_id < 1) {
                    continue;
                }
                $row = $this->candidate($video_id);
                if (null !== $row) {
                    $eligible[] = $row;
                    if (count($eligible) >= $wanted) {
                        break;
                    }
                }
            }
            if ($count < $batch) {
                $exhausted = true;
                break;
            }
        }

        $items = array_slice($eligible, $offset, $limit);
        $more = count($eligible) > $offset + $limit || ! $exhausted;
        return array('items'=>$items,'more'=>$more);
    }

    /** @return array<string,mixed>|null */
    public function candidate(int $video_id): ?array
    {
        if (metadata_exists('post', $video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION)) {
            return null;
        }
        $video = $this->video_post($video_id);
        if (null === $video || ! $this->is_local_destination($video_id)) {
            return null;
        }
        if (metadata_exists('post', $video_id, Video_Meta::PEERTUBE_PUBLICATION_EXECUTION)
            || metadata_exists('post', $video_id, Video_Meta::SERVING_AUTHORITY)
        ) {
            return null;
        }

        $attachment_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true));
        $anchor_post_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true));
        $attachment = $attachment_id > 0 ? get_post($attachment_id) : null;
        $anchor = $anchor_post_id > 0 ? get_post($anchor_post_id) : null;
        if (! is_object($attachment) || ! is_object($anchor) || ! wp_attachment_is('video', $attachment_id)) {
            return null;
        }

        $source_state = (string) get_post_meta($video_id, Video_Meta::SOURCE_STATE, true);
        if ('present' !== $source_state && '' !== $source_state) {
            return null;
        }

        $stored_exists = metadata_exists('post', $video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN);
        $stored = $stored_exists
            ? PeerTube_Migration_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN, true))
            : array();

        return array(
            'video_id'      => $video_id,
            'attachment_id' => $attachment_id,
            'anchor_post_id'=> $anchor_post_id,
            'title'         => $this->title_for($attachment, $anchor),
            'post_status'   => is_string($anchor->post_status ?? null) ? $anchor->post_status : '',
            'plan_status'   => $stored_exists ? (array() === $stored ? 'invalid' : (string) $stored['status']) : 'none',
            'issues'        => array() === $stored ? array() : $stored['issues'],
            'backend_id'    => array() === $stored ? '' : (string) $stored['backend_id'],
            'channel_id'    => array() === $stored ? '' : (string) $stored['channel_id'],
        );
    }

    /**
     * Plan selected videos. No live publication/destination state is changed.
     *
     * @param list<int> $video_ids
     * @return array{applied:list<int>,present:list<int>,refused:list<int>,indeterminate:list<int>}
     */
    public function plan(array $video_ids, string $backend_id, string $channel_id, int $now): array
    {
        $result = array('applied'=>array(),'present'=>array(),'refused'=>array(),'indeterminate'=>array());
        if ($now < 1 || count($video_ids) > self::MAX_SELECT_ALL) {
            return $result;
        }
        $context = $this->context($backend_id, $channel_id);
        if (null === $context) {
            $result['refused'] = array_values(array_unique(array_filter($video_ids, static fn ($id): bool => is_int($id) && $id > 0)));
            return $result;
        }

        $seen = array();
        foreach ($video_ids as $video_id) {
            if (! is_int($video_id) || $video_id < 1 || isset($seen[$video_id])) {
                continue;
            }
            $seen[$video_id] = true;
            $status = $this->plan_one($video_id, $context, $now);
            if (isset($result[$status])) {
                $result[$status][] = $video_id;
            }
        }
        return $result;
    }

    /**
     * Save an explicitly reviewed migration publication plan.
     *
     * @param array<string,mixed> $publication_plan
     * @return array{status:string,issues:list<string>}
     */
    public function review(int $video_id, array $publication_plan, int $now): array
    {
        if (metadata_exists('post', $video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION)) {
            return array('status'=>self::REFUSED,'issues'=>array('migration_execution_started'));
        }
        $candidate = $this->candidate($video_id);
        if (null === $candidate || $now < 1) {
            return array('status'=>self::REFUSED,'issues'=>array('video_ineligible'));
        }
        if (! metadata_exists('post', $video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN)) {
            return array('status'=>self::REFUSED,'issues'=>array('migration_not_planned'));
        }
        $before = PeerTube_Migration_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN, true));
        if (array() === $before) {
            return array('status'=>self::REFUSED,'issues'=>array('invalid_migration_plan'));
        }

        $plan = PeerTube_Publication_Plan::sanitize($publication_plan);
        if (array() === $plan
            || $video_id !== (int) $before['video_id']
            || (int) $before['attachment_id'] !== (int) $candidate['attachment_id']
            || (int) $before['anchor_post_id'] !== (int) $candidate['anchor_post_id']
            || (int) $before['anchor_post_id'] !== (int) ($plan['anchor_post_id'] ?? 0)
            || (string) $before['backend_id'] !== (string) ($plan['backend_id'] ?? '')
            || (string) $before['channel_id'] !== (string) ($plan['channel_id'] ?? '')
        ) {
            return array('status'=>self::REFUSED,'issues'=>array('plan_context_mismatch'));
        }

        $context = $this->context((string) $before['backend_id'], (string) $before['channel_id']);
        if (null === $context || ! $this->provider_values_allowed($plan, $context['catalog'])) {
            return array('status'=>self::REFUSED,'issues'=>array('provider_choice_unavailable'));
        }

        $issues = $this->review_issues($plan);
        $next = $before;
        $next['publication_plan'] = $plan;
        $next['issues'] = $issues;
        $next['status'] = array() === $issues ? PeerTube_Migration_Plan::STATUS_READY : PeerTube_Migration_Plan::STATUS_NEEDS_REVIEW;
        $next['updated_at'] = max($now, (int) $before['planned_at']);
        $next = PeerTube_Migration_Plan::sanitize($next);
        if (array() === $next) {
            return array('status'=>self::REFUSED,'issues'=>array('invalid_migration_plan'));
        }
        if ($next === $before) {
            return array('status'=>self::PRESENT,'issues'=>$issues);
        }

        update_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN, $next);
        $after = PeerTube_Migration_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN, true));
        return $after === $next
            ? array('status'=>self::APPLIED,'issues'=>$issues)
            : array('status'=>self::INDETERMINATE,'issues'=>$issues);
    }

    /** @return list<array<string,mixed>> */
    public function planned(int $limit = 200): array
    {
        $limit = max(1, min(self::MAX_SELECT_ALL, $limit));
        $ids = get_posts(array(
            'post_type'      => Video_Post_Type::POST_TYPE,
            'post_status'    => 'any',
            'fields'         => 'ids',
            'posts_per_page' => $limit,
            'orderby'        => 'ID',
            'order'          => 'ASC',
            'meta_key'       => Video_Meta::PEERTUBE_MIGRATION_PLAN,
            'no_found_rows'  => true,
        ));
        if (! is_array($ids)) {
            return array();
        }
        $out = array();
        foreach ($ids as $id) {
            $video_id = Video_Meta::sanitize_positive_id($id);
            if ($video_id < 1 || ! metadata_exists('post', $video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN)) {
                continue;
            }
            $plan = PeerTube_Migration_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN, true));
            if (array() === $plan) {
                $out[] = array('video_id'=>$video_id,'status'=>'invalid','issues'=>array('invalid_migration_plan'));
                continue;
            }
            $out[] = $plan;
        }
        return $out;
    }

    /** @param array<string,mixed> $context */
    private function plan_one(int $video_id, array $context, int $now): string
    {
        $candidate = $this->candidate($video_id);
        if (null === $candidate) {
            return self::REFUSED;
        }
        $stored_exists = metadata_exists('post', $video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN);
        $before = $stored_exists
            ? PeerTube_Migration_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN, true))
            : array();
        if ($stored_exists && array() === $before) {
            return self::REFUSED;
        }
        if (array() !== $before
            && (int) $before['attachment_id'] === (int) $candidate['attachment_id']
            && (int) $before['anchor_post_id'] === (int) $candidate['anchor_post_id']
            && (string) $before['backend_id'] === (string) $context['backend_id']
            && (string) $before['channel_id'] === (string) $context['channel_id']
        ) {
            // Re-running select-all for the same source/target must preserve any
            // completed per-video review rather than resetting it to suggestions.
            return self::PRESENT;
        }

        $plan = $this->initial_publication_plan($candidate, $context);
        $suggested_tags = $this->tag_suggestions((int) $candidate['anchor_post_id']);
        $issues = $this->initial_issues($plan, $suggested_tags, $context['catalog']);
        $record = array(
            'version'          => PeerTube_Migration_Plan::VERSION,
            'video_id'         => $video_id,
            'attachment_id'    => (int) $candidate['attachment_id'],
            'anchor_post_id'   => (int) $candidate['anchor_post_id'],
            'backend_id'       => (string) $context['backend_id'],
            'channel_id'       => (string) $context['channel_id'],
            'status'           => PeerTube_Migration_Plan::STATUS_NEEDS_REVIEW,
            'issues'           => $issues,
            'suggested_tags'   => $suggested_tags,
            'publication_plan' => array() === $plan ? null : $plan,
            'planned_at'       => array() === $before ? $now : (int) $before['planned_at'],
            'updated_at'       => $now,
        );
        $record = PeerTube_Migration_Plan::sanitize($record);
        if (array() === $record) {
            return self::REFUSED;
        }
        if ($record === $before) {
            return self::PRESENT;
        }
        update_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN, $record);
        $after = PeerTube_Migration_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_PLAN, true));
        return $after === $record ? self::APPLIED : self::INDETERMINATE;
    }

    /** @return array<string,mixed>|null */
    private function context(string $backend_id, string $channel_id): ?array
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        $channel_id = $this->decimal_id($channel_id);
        $descriptor = '' !== $backend_id ? $this->registry->get($backend_id) : null;
        if (! is_array($descriptor)
            || Backend_Registry::PEERTUBE_TYPE !== ($descriptor['type'] ?? null)
            || 'active' !== ($descriptor['state'] ?? null)
            || '' === $channel_id
        ) {
            return null;
        }
        $catalog = $this->catalogs->get($backend_id);
        if (! is_array($catalog) || $backend_id !== ($catalog['backend_id'] ?? null)) {
            return null;
        }
        $origin = PeerTube_Origin::sanitize($descriptor['config']['origin'] ?? null);
        if ('' === $origin || $origin !== ($catalog['origin'] ?? null)
            || (true === ($catalog['stale'] ?? false) && 'backend_context_changed' === ($catalog['stale_reason'] ?? ''))
            || ! $this->channel_exists($channel_id, $catalog)
        ) {
            return null;
        }
        $settings = $this->defaults->get();
        $effective = is_array($settings) ? Video_Publishing_Defaults::effective_for_backend($settings, $descriptor) : array();
        return array(
            'backend_id'=>$backend_id,
            'channel_id'=>$channel_id,
            'descriptor'=>$descriptor,
            'catalog'=>$catalog,
            'effective'=>$effective,
        );
    }

    /** @param array<string,mixed> $candidate @param array<string,mixed> $context @return array<string,mixed> */
    private function initial_publication_plan(array $candidate, array $context): array
    {
        $effective = is_array($context['effective'] ?? null) ? $context['effective'] : array();
        $catalog = $context['catalog'];
        $title = is_string($candidate['title'] ?? null) ? trim($candidate['title']) : '';
        if (! $this->valid_title($title)) {
            return array();
        }
        $privacy = is_string($effective['final_privacy_id'] ?? null) ? $effective['final_privacy_id'] : '';
        if (! isset($catalog['privacies'][$privacy]) || '5' === $privacy) {
            return array();
        }
        $licence = is_string($effective['licence_id'] ?? null) ? $effective['licence_id'] : '';
        $category = is_string($effective['category_id'] ?? null) ? $effective['category_id'] : '';
        $language = is_string($effective['language'] ?? null) ? $effective['language'] : '';
        if ('' !== $licence && ! isset($catalog['licences'][$licence])) {
            $licence = '';
        }
        if ('' !== $category && ! isset($catalog['categories'][$category])) {
            $category = '';
        }
        if ('' !== $language && ! isset($catalog['languages'][$language])) {
            $language = '';
        }

        $tags = $this->valid_suggested_tags($this->tag_suggestions((int) $candidate['anchor_post_id']));
        $support = array('mode'=>'none','preset_id'=>'','markdown'=>'');
        if (is_array($effective['support'] ?? null) && 'preset' === ($effective['support']['mode'] ?? null)) {
            $preset_id = is_string($effective['support']['preset_id'] ?? null) ? $effective['support']['preset_id'] : '';
            if ('' !== $preset_id) {
                $support = array('mode'=>'preset','preset_id'=>$preset_id,'markdown'=>'');
            }
        }
        $moderation = is_array($effective['moderation'] ?? null) ? $effective['moderation'] : array();
        $sensitive = true === ($moderation['sensitive'] ?? false);
        $plan = array(
            'version'                 => PeerTube_Publication_Plan::VERSION,
            'backend_id'              => (string) $context['backend_id'],
            'channel_id'              => (string) $context['channel_id'],
            'title'                   => $title,
            'description_markdown'    => '',
            'tags'                    => $tags,
            'support'                 => $support,
            'final_privacy_id'        => $privacy,
            'licence_id'              => $licence,
            'category_id'             => $category,
            'language'                => $language,
            'thumbnail_attachment_id' => 0,
            'download_enabled'        => true === ($effective['download_enabled'] ?? true),
            'originally_published_at' => '',
            'comments_policy'         => is_string($effective['comments_policy'] ?? null) ? $effective['comments_policy'] : 'enabled',
            'moderation'              => array(
                'reviewed'=>false,
                'sensitive'=>$sensitive,
                'reason'=>$sensitive && is_string($moderation['reason'] ?? null) ? $moderation['reason'] : '',
                'violent'=>$sensitive && true === ($moderation['violent'] ?? false),
                'sexually_explicit'=>$sensitive && true === ($moderation['sexually_explicit'] ?? false),
            ),
            'embed'                   => array('restricted'=>false,'domains'=>array()),
            'review'                  => array('title'=>false,'channel'=>false,'tags'=>false,'privacy'=>false,'moderation'=>false),
            'dispatch_policy'         => is_string($effective['dispatch_policy'] ?? null)
                ? $effective['dispatch_policy'] : PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,
            'release_policy'          => PeerTube_Publication_Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,
            'anchor_post_id'          => (int) $candidate['anchor_post_id'],
        );
        return PeerTube_Publication_Plan::sanitize($plan);
    }

    /** @param array<string,mixed> $plan @param list<string> $suggested_tags @param array<string,mixed> $catalog @return list<string> */
    private function initial_issues(array $plan, array $suggested_tags, array $catalog): array
    {
        $issues = array('review_channel','review_moderation','review_privacy','review_tags','review_title');
        if (array() === $plan) {
            $issues[] = 'publication_prefill_incomplete';
        }
        if (count($suggested_tags) > PeerTube_Publication_Plan::MAX_TAGS) {
            $issues[] = 'tag_selection_required';
        }
        foreach ($suggested_tags as $tag) {
            if (! $this->valid_peer_tag($tag)) {
                $issues[] = 'invalid_tag_suggestion';
                break;
            }
        }
        if (true === ($catalog['stale'] ?? false)) {
            $issues[] = 'catalog_stale';
        }
        $issues = array_values(array_unique($issues));
        sort($issues, SORT_STRING);
        return $issues;
    }

    /** @param array<string,mixed> $plan @return list<string> */
    private function review_issues(array $plan): array
    {
        $issues = array();
        foreach (PeerTube_Publication_Plan::missing_review($plan) as $field) {
            $issues[] = 'review_' . $field;
        }
        return array_values(array_unique($issues));
    }

    /** @return list<string> */
    private function tag_suggestions(int $anchor_post_id): array
    {
        if ($anchor_post_id < 1 || ! function_exists('wp_get_post_tags')) {
            return array();
        }
        $tags = wp_get_post_tags($anchor_post_id, array('fields'=>'names'));
        if (! is_array($tags)) {
            return array();
        }
        $out = array();
        $seen = array();
        foreach ($tags as $tag) {
            if (! is_string($tag)) {
                continue;
            }
            $tag = trim(preg_replace('/\s+/u', ' ', $tag) ?? '');
            if ('' === $tag) {
                continue;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $out[] = $tag;
                if (count($out) >= PeerTube_Migration_Plan::MAX_SUGGESTED_TAGS) {
                    break;
                }
            }
        }
        return $out;
    }

    /** @param list<string> $suggestions @return list<string> */
    private function valid_suggested_tags(array $suggestions): array
    {
        if (count($suggestions) > PeerTube_Publication_Plan::MAX_TAGS) {
            return array();
        }
        foreach ($suggestions as $tag) {
            if (! $this->valid_peer_tag($tag)) {
                return array();
            }
        }
        return $suggestions;
    }

    private function valid_peer_tag(string $tag): bool
    {
        if (1 !== preg_match('//u', $tag)) {
            return false;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($tag, 'UTF-8') : strlen($tag);
        return $length >= PeerTube_Publication_Plan::MIN_TAG_CHARACTERS
            && $length <= PeerTube_Publication_Plan::MAX_TAG_CHARACTERS
            && trim($tag) === $tag;
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $catalog */
    private function provider_values_allowed(array $plan, array $catalog): bool
    {
        if (! $this->channel_exists((string) $plan['channel_id'], $catalog)) {
            return false;
        }
        $privacy = (string) $plan['final_privacy_id'];
        if ('5' === $privacy || ! isset($catalog['privacies'][$privacy])) {
            return false;
        }
        $licence = (string) $plan['licence_id'];
        $category = (string) $plan['category_id'];
        $language = (string) $plan['language'];
        return ('' === $licence || isset($catalog['licences'][$licence]))
            && ('' === $category || isset($catalog['categories'][$category]))
            && ('' === $language || isset($catalog['languages'][$language]));
    }

    /** @param array<string,mixed> $catalog */
    private function channel_exists(string $channel_id, array $catalog): bool
    {
        foreach ($catalog['channels'] ?? array() as $channel) {
            if (is_array($channel) && $channel_id === ($channel['id'] ?? null) && 'owned' === ($channel['authority'] ?? null)) {
                return true;
            }
        }
        return false;
    }

    private function is_local_destination(int $video_id): bool
    {
        $exists = metadata_exists('post', $video_id, Video_Meta::DESTINATION);
        $destination = Video_Destination::resolve(get_post_meta($video_id, Video_Meta::DESTINATION, true), $exists);
        return array() !== $destination && Video_Destination::is_local($destination);
    }

    private function video_post(int $video_id): ?object
    {
        if ($video_id < 1) {
            return null;
        }
        $post = get_post($video_id);
        return is_object($post) && Video_Post_Type::POST_TYPE === ($post->post_type ?? null) ? $post : null;
    }

    private function title_for(object $attachment, object $anchor): string
    {
        foreach (array($attachment->post_title ?? null, $anchor->post_title ?? null) as $title) {
            if (is_string($title) && $this->valid_title(trim($title))) {
                return trim($title);
            }
        }
        return '';
    }

    private function valid_title(string $title): bool
    {
        if ('' === $title || trim($title) !== $title || 1 !== preg_match('//u', $title)) {
            return false;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
        return $length >= PeerTube_Publication_Plan::MIN_TITLE_CHARACTERS
            && $length <= PeerTube_Publication_Plan::MAX_TITLE_CHARACTERS;
    }

    private function decimal_id(string $value): string
    {
        return 1 === preg_match('/^[1-9][0-9]*$/D', $value) ? $value : '';
    }
}

// EOF: includes/PeerTube_Migration_Planner.php
