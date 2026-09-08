<?php
/**
 * File: includes/PeerTube_Publication_Editor_Service.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * R46.3c editor-only PeerTube publication-plan application service.
 *
 * This service reads the R46.3a provider catalog and persists only reviewed
 * per-video editorial intent. It owns no credentials, PeerTube HTTP, task
 * dispatch, post-status hooks, remote visibility changes, or serving cutover.
 */
final class PeerTube_Publication_Editor_Service
{
    public const APPLIED = 'applied';
    public const PRESENT = 'present';
    public const REFUSED = 'refused';
    public const INDETERMINATE = 'indeterminate';

    public const PLAN_ABSENT = 'absent';
    public const PLAN_PRESENT = 'present';
    public const PLAN_INVALID = 'invalid';
    public const PLAN_BACKEND_MISMATCH = 'backend_mismatch';
    public const PLAN_CHANNEL_MISMATCH = 'channel_mismatch';

    public const CATALOG_CURRENT = 'current';
    public const CATALOG_STALE = 'stale';
    public const CATALOG_MISSING = 'missing';
    public const CATALOG_CONTEXT_MISMATCH = 'context_mismatch';

    public function __construct(
        private readonly Backend_Registry $registry,
        private readonly Video_Publishing_Defaults_Store $defaults,
        private readonly PeerTube_Publication_Catalog_Store $catalogs
    ) {
    }

    /** @return array<string,mixed>|null */
    public function editor_state(int $video_id): ?array
    {
        $video = $this->video_post($video_id);
        if (null === $video) {
            return null;
        }

        $destination = Video_Destination::sanitize(get_post_meta($video_id, Video_Meta::DESTINATION, true));
        if (array() === $destination || Video_Destination::is_local($destination)) {
            return null;
        }

        $backend_id = Backend_Identity::sanitize($destination['backend_id'] ?? null);
        $backend = $this->active_peertube($backend_id);
        if (null === $backend) {
            return null;
        }
        $origin = PeerTube_Origin::sanitize($backend['config']['origin'] ?? null);
        if ('' === $origin) {
            return null;
        }

        $catalog = $this->catalogs->get($backend_id);
        $catalog_status = self::CATALOG_MISSING;
        $catalog_usable = false;
        if (is_array($catalog)) {
            if ($origin !== ($catalog['origin'] ?? null)) {
                $catalog_status = self::CATALOG_CONTEXT_MISMATCH;
            } elseif (true === ($catalog['stale'] ?? false)) {
                $catalog_status = self::CATALOG_STALE;
                $catalog_usable = 'backend_context_changed' !== ($catalog['stale_reason'] ?? '');
            } else {
                $catalog_status = self::CATALOG_CURRENT;
                $catalog_usable = true;
            }
        }

        $stored_exists = metadata_exists('post', $video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN);
        $stored = $stored_exists
            ? PeerTube_Publication_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN, true))
            : array();
        $plan_status = self::PLAN_ABSENT;
        if ($stored_exists && array() === $stored) {
            $plan_status = self::PLAN_INVALID;
        } elseif (array() !== $stored) {
            if ($backend_id !== ($stored['backend_id'] ?? null)) {
                $plan_status = self::PLAN_BACKEND_MISMATCH;
            } elseif (($destination['channel_id'] ?? '') !== ($stored['channel_id'] ?? '')) {
                $plan_status = self::PLAN_CHANNEL_MISMATCH;
            } else {
                $plan_status = self::PLAN_PRESENT;
            }
        }

        $settings = $this->defaults->get();
        $effective = is_array($settings)
            ? Video_Publishing_Defaults::effective_for_backend($settings, $backend)
            : array();
        $prefill = $this->prefill($video_id, $destination, $effective, $catalog_usable ? $catalog : null);
        $draft = self::PLAN_PRESENT === $plan_status || self::PLAN_CHANNEL_MISMATCH === $plan_status
            ? $stored
            : $prefill;
        if (self::PLAN_CHANNEL_MISMATCH === $plan_status && array() !== $draft) {
            // Destination and publication channel disagree. Preserve the stored
            // channel as editable intent, but require the editor to review that
            // choice again before it can become dispatch-ready.
            $draft['review']['channel'] = false;
        }

        $ready = self::PLAN_PRESENT === $plan_status
            && PeerTube_Publication_Plan::ready_for_dispatch($stored);
        $missing = array() !== $stored
            ? PeerTube_Publication_Plan::missing_review($stored)
            : array('title', 'channel', 'tags', 'privacy', 'moderation');

        return array(
            'video_id' => $video_id,
            'backend' => array(
                'id'     => $backend_id,
                'label'  => $this->backend_label($backend),
                'origin' => $origin,
            ),
            'destination_channel_id' => (string) ($destination['channel_id'] ?? ''),
            'catalog' => $this->catalog_state($catalog_status, $catalog, $catalog_usable),
            'choices' => $this->choices($settings, $catalog_usable ? $catalog : null),
            'plan_status' => $plan_status,
            'plan' => array() === $stored ? null : $stored,
            'draft' => $draft,
            'ready_for_dispatch' => $ready,
            'missing_review' => $missing,
        );
    }

    /**
     * Persist one complete editor plan. Provider IDs are revalidated against the
     * cached catalog before storage, but this remains editorial state only; a
     * later consequential operation must revalidate/freeze them again.
     *
     * @param array<string,mixed> $input
     * @return array{status:string}
     */
    public function save(int $video_id, array $input, bool $replace_existing = false): array
    {
        $video = $this->video_post($video_id);
        if (null === $video) {
            return self::result(self::REFUSED);
        }

        $destination = Video_Destination::sanitize(get_post_meta($video_id, Video_Meta::DESTINATION, true));
        if (array() === $destination || Video_Destination::is_local($destination)) {
            return self::result(self::REFUSED);
        }

        $backend_id = Backend_Identity::sanitize($destination['backend_id'] ?? null);
        $backend = $this->active_peertube($backend_id);
        if (null === $backend) {
            return self::result(self::REFUSED);
        }
        $origin = PeerTube_Origin::sanitize($backend['config']['origin'] ?? null);
        if ('' === $origin) {
            return self::result(self::REFUSED);
        }

        $catalog = $this->catalogs->get($backend_id);
        if (! is_array($catalog)
            || $origin !== ($catalog['origin'] ?? null)
            || (true === ($catalog['stale'] ?? false) && 'backend_context_changed' === ($catalog['stale_reason'] ?? ''))
        ) {
            return self::result(self::REFUSED);
        }

        $stored_exists = metadata_exists('post', $video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN);
        $before = $stored_exists
            ? PeerTube_Publication_Plan::sanitize(get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN, true))
            : array();
        if ($stored_exists && array() === $before) {
            // Preserve malformed/future state instead of silently replacing it.
            return self::result(self::REFUSED);
        }
        if (array() !== $before && $backend_id !== ($before['backend_id'] ?? null) && ! $replace_existing) {
            return self::result(self::REFUSED);
        }

        $settings = $this->defaults->get();
        $candidate = $this->candidate($video_id, $backend_id, $input, is_array($settings) ? $settings : null);
        if (array() === $candidate || ! $this->provider_values_allowed($candidate, $catalog)) {
            return self::result(self::REFUSED);
        }

        $destination_candidate = array(
            'version'    => Video_Destination::VERSION,
            'backend_id' => $backend_id,
            'channel_id' => $candidate['channel_id'],
        );
        if (array() === Video_Destination::sanitize($destination_candidate)) {
            return self::result(self::REFUSED);
        }

        if (metadata_exists('post', $video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION)) {
            $migration_execution = PeerTube_Migration_Execution::sanitize(
                get_post_meta($video_id, Video_Meta::PEERTUBE_MIGRATION_EXECUTION, true)
            );
            if (array() === $migration_execution
                || (string)$migration_execution['backend_id'] !== $backend_id
                || (string)$migration_execution['channel_id'] !== (string)$destination_candidate['channel_id']
                || $destination_candidate !== $destination) {
                return self::result(self::REFUSED);
            }
        }

        $before_destination = $destination;
        if ($before === $candidate && $before_destination === $destination_candidate) {
            if (function_exists('do_action')) {
                // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook is already fully prefixed with argentwolf_video_processor_.
                do_action(
                    'argentwolf_video_processor_publication_plan_saved',
                    $video_id,
                    (int) $candidate['anchor_post_id']
                );
                // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            }
            return self::result(self::PRESENT);
        }

        update_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN, $candidate);
        update_post_meta($video_id, Video_Meta::DESTINATION, $destination_candidate);

        $after_plan = PeerTube_Publication_Plan::sanitize(
            get_post_meta($video_id, Video_Meta::PEERTUBE_PUBLICATION_PLAN, true)
        );
        $after_destination = Video_Destination::sanitize(
            get_post_meta($video_id, Video_Meta::DESTINATION, true)
        );

        if ($candidate === $after_plan && $destination_candidate === $after_destination) {
            if (function_exists('do_action')) {
                // phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Hook is already fully prefixed with argentwolf_video_processor_.
                do_action(
                    'argentwolf_video_processor_publication_plan_saved',
                    $video_id,
                    (int) $candidate['anchor_post_id']
                );
                // phpcs:enable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
            }
            return self::result(self::APPLIED);
        }

        return self::result(self::INDETERMINATE);
    }

    /** @return array<string,mixed> */
    private function prefill(int $video_id, array $destination, array $effective, ?array $catalog): array
    {
        $attachment_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ATTACHMENT_ID, true));
        $attachment = $attachment_id > 0 ? get_post($attachment_id) : null;
        $origin_post_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true));

        $title = '';
        $description = '';
        if (is_object($attachment)) {
            $candidate_title = isset($attachment->post_title) && is_string($attachment->post_title)
                ? trim($attachment->post_title)
                : '';
            if ($this->valid_title($candidate_title)) {
                $title = $candidate_title;
            }
            if (isset($attachment->post_content) && is_string($attachment->post_content)
                && strlen($attachment->post_content) <= PeerTube_Publication_Plan::MAX_MARKDOWN_BYTES
                && 1 === preg_match('//u', $attachment->post_content)
            ) {
                $description = $attachment->post_content;
            }
        }

        $channel_id = (string) ($effective['channel_id'] ?? ($destination['channel_id'] ?? ''));
        $privacy = (string) ($effective['final_privacy_id'] ?? '');
        $licence = (string) ($effective['licence_id'] ?? '');
        $category = (string) ($effective['category_id'] ?? '');
        $language = (string) ($effective['language'] ?? '');
        if (is_array($catalog)) {
            $channel_id = $this->channel_exists($channel_id, $catalog) ? $channel_id : '';
            $privacy = $this->privacy_supported($privacy, $catalog) ? $privacy : '';
            $licence = '' === $licence || isset($catalog['licences'][$licence]) ? $licence : '';
            $category = '' === $category || isset($catalog['categories'][$category]) ? $category : '';
            $language = '' === $language || $this->language_supported($language, $catalog) ? $language : '';
        }

        $support = array('mode'=>'none','preset_id'=>'','markdown'=>'');
        if ('preset' === ($effective['support']['mode'] ?? null)
            && is_string($effective['support']['preset_id'] ?? null)
            && '' !== $effective['support']['preset_id']
        ) {
            $support = array('mode'=>'preset','preset_id'=>$effective['support']['preset_id'],'markdown'=>'');
        }

        $moderation = is_array($effective['moderation'] ?? null) ? $effective['moderation'] : array();
        $sensitive = true === ($moderation['sensitive'] ?? false);
        return array(
            'version'                 => PeerTube_Publication_Plan::VERSION,
            'backend_id'              => (string) ($destination['backend_id'] ?? ''),
            'channel_id'              => $channel_id,
            'title'                   => $title,
            'description_markdown'    => $description,
            'tags'                    => array(),
            'support'                 => $support,
            'final_privacy_id'        => $privacy,
            'licence_id'              => $licence,
            'category_id'             => $category,
            'language'                => $language,
            'thumbnail_attachment_id' => 0,
            'download_enabled'        => true === ($effective['download_enabled'] ?? true),
            'originally_published_at' => '',
            'comments_policy'         => is_string($effective['comments_policy'] ?? null)
                ? $effective['comments_policy'] : 'enabled',
            'moderation'              => array(
                'reviewed'          => false,
                'sensitive'         => $sensitive,
                'reason'            => $sensitive && is_string($moderation['reason'] ?? null) ? $moderation['reason'] : '',
                'violent'           => $sensitive && true === ($moderation['violent'] ?? false),
                'sexually_explicit' => $sensitive && true === ($moderation['sexually_explicit'] ?? false),
            ),
            // PeerTube core currently has no reviewed per-video allowed-domain
            // field in the upload/update contract. Keep the frozen model explicit
            // and unrestricted rather than inventing unsupported provider state.
            'embed'                   => array('restricted'=>false,'domains'=>array()),
            'review'                  => array(
                'title'=>false,'channel'=>false,'tags'=>false,'privacy'=>false,'moderation'=>false,
            ),
            'dispatch_policy'         => is_string($effective['dispatch_policy'] ?? null)
                ? $effective['dispatch_policy'] : PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,
            'release_policy'          => PeerTube_Publication_Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,
            'anchor_post_id'          => $origin_post_id,
        );
    }

    /** @param array<string,mixed>|null $settings @return array<string,mixed> */
    private function candidate(int $video_id, string $backend_id, array $input, ?array $settings): array
    {
        $origin_post_id = Video_Meta::sanitize_positive_id(get_post_meta($video_id, Video_Meta::ORIGIN_POST_ID, true));
        if ($origin_post_id < 1 || null === get_post($origin_post_id)) {
            return array();
        }

        $support = $input['support'] ?? null;
        if (! is_array($support)) {
            return array();
        }
        $support_mode = $support['mode'] ?? null;
        if ('preset' === $support_mode) {
            $preset_id = is_string($support['preset_id'] ?? null) ? $support['preset_id'] : '';
            $presets = is_array($settings) && is_array($settings['support_presets'] ?? null)
                ? $settings['support_presets'] : array();
            if ('' === $preset_id || ! isset($presets[$preset_id])) {
                return array();
            }
            $support = array('mode'=>'preset','preset_id'=>$preset_id,'markdown'=>'');
        } elseif ('custom' === $support_mode) {
            $support = array(
                'mode'=>'custom',
                'preset_id'=>'',
                'markdown'=>is_string($support['markdown'] ?? null) ? $support['markdown'] : '',
            );
        } elseif ('none' === $support_mode) {
            $support = array('mode'=>'none','preset_id'=>'','markdown'=>'');
        } else {
            return array();
        }

        $review = $input['review'] ?? null;
        $moderation = $input['moderation'] ?? null;
        if (! is_array($review) || ! is_array($moderation)) {
            return array();
        }
        $moderation_reviewed = $this->strict_bool($review['moderation'] ?? null);
        if (null === $moderation_reviewed) {
            return array();
        }

        $thumbnail_id = Video_Meta::sanitize_positive_id($input['thumbnail_attachment_id'] ?? 0);
        if (0 !== $thumbnail_id && ! $this->valid_thumbnail($thumbnail_id)) {
            return array();
        }

        $candidate = array(
            'version'                 => PeerTube_Publication_Plan::VERSION,
            'backend_id'              => $backend_id,
            'channel_id'              => $input['channel_id'] ?? '',
            'title'                   => $input['title'] ?? '',
            'description_markdown'    => $input['description_markdown'] ?? '',
            'tags'                    => $input['tags'] ?? null,
            'support'                 => $support,
            'final_privacy_id'        => $input['final_privacy_id'] ?? '',
            'licence_id'              => $input['licence_id'] ?? '',
            'category_id'             => $input['category_id'] ?? '',
            'language'                => $input['language'] ?? '',
            'thumbnail_attachment_id' => $thumbnail_id,
            'download_enabled'        => $input['download_enabled'] ?? null,
            'originally_published_at' => $input['originally_published_at'] ?? '',
            'comments_policy'         => $input['comments_policy'] ?? '',
            'moderation'              => array(
                'reviewed'          => $moderation_reviewed,
                'sensitive'         => $moderation['sensitive'] ?? null,
                'reason'            => $moderation['reason'] ?? '',
                'violent'           => $moderation['violent'] ?? null,
                'sexually_explicit' => $moderation['sexually_explicit'] ?? null,
            ),
            'embed'                   => array('restricted'=>false,'domains'=>array()),
            'review'                  => array(
                'title'      => $review['title'] ?? null,
                'channel'    => $review['channel'] ?? null,
                'tags'       => $review['tags'] ?? null,
                'privacy'    => $review['privacy'] ?? null,
                'moderation' => $moderation_reviewed,
            ),
            'dispatch_policy'         => $input['dispatch_policy'] ?? '',
            'release_policy'          => PeerTube_Publication_Plan::RELEASE_WHEN_WORDPRESS_PUBLISHED,
            'anchor_post_id'          => $origin_post_id,
        );

        return PeerTube_Publication_Plan::sanitize($candidate);
    }

    /** @param array<string,mixed> $plan @param array<string,mixed> $catalog */
    private function provider_values_allowed(array $plan, array $catalog): bool
    {
        if (! $this->channel_exists((string) $plan['channel_id'], $catalog)
            || ! $this->privacy_supported((string) $plan['final_privacy_id'], $catalog)
        ) {
            return false;
        }
        if ('' !== $plan['licence_id'] && ! isset($catalog['licences'][$plan['licence_id']])) {
            return false;
        }
        if ('' !== $plan['category_id'] && ! isset($catalog['categories'][$plan['category_id']])) {
            return false;
        }
        if ('' !== $plan['language'] && ! $this->language_supported((string) $plan['language'], $catalog)) {
            return false;
        }

        $caps = is_array($catalog['capabilities'] ?? null) ? $catalog['capabilities'] : array();
        if (true === $plan['moderation']['sensitive'] && true !== ($caps['sensitive_content'] ?? false)) {
            return false;
        }
        if ((true === $plan['moderation']['violent'] || true === $plan['moderation']['sexually_explicit'])
            && true !== ($caps['sensitive_flags'] ?? false)
        ) {
            return false;
        }

        return true;
    }

    /** @param array<string,mixed>|null $settings @param array<string,mixed>|null $catalog @return array<string,mixed> */
    private function choices(?array $settings, ?array $catalog): array
    {
        $support = array();
        if (is_array($settings) && is_array($settings['support_presets'] ?? null)) {
            foreach ($settings['support_presets'] as $id => $preset) {
                if (! is_string($id) || ! is_array($preset)) {
                    continue;
                }
                $support[] = array(
                    'id'       => $id,
                    'label'    => (string) ($preset['label'] ?? $id),
                    'markdown' => (string) ($preset['markdown'] ?? ''),
                );
            }
        }

        $empty = array(
            'channels'=>array(),'privacies'=>array(),'unsupported_privacies'=>array(),
            'licences'=>array(),'categories'=>array(),'languages'=>array(),
        );
        if (! is_array($catalog)) {
            return $empty + array(
                'comments'=>self::comment_choices(),
                'support_presets'=>$support,
                'moderation'=>array('sensitive_content'=>false,'sensitive_flags'=>false),
            );
        }

        $channels = array_map(
            static fn (array $row): array => array(
                'id'=>(string) $row['id'],
                'label'=>(string) $row['display_name'] . ' (@' . (string) $row['name'] . ')',
            ),
            $catalog['channels']
        );
        $privacies = array();
        $unsupported_privacies = array();
        foreach ($catalog['privacies'] as $id => $label) {
            $row = array('id'=>(string) $id,'label'=>(string) $label);
            if (in_array((string) $id, array('1','2','3','4'), true)) {
                $privacies[] = $row;
            } else {
                $unsupported_privacies[] = $row;
            }
        }

        $languages = array();
        foreach ($catalog['languages'] as $id => $label) {
            if ('_unknown' === $id || ! $this->language_id_shape((string) $id)) {
                continue;
            }
            $languages[] = array('id'=>(string) $id,'label'=>(string) $label);
        }

        return array(
            'channels'=>$channels,
            'privacies'=>$privacies,
            'unsupported_privacies'=>$unsupported_privacies,
            'licences'=>$this->dictionary_choices($catalog['licences']),
            'categories'=>$this->dictionary_choices($catalog['categories']),
            'languages'=>$languages,
            'comments'=>self::comment_choices(),
            'support_presets'=>$support,
            'moderation'=>array(
                'sensitive_content'=>true === ($catalog['capabilities']['sensitive_content'] ?? false),
                'sensitive_flags'=>true === ($catalog['capabilities']['sensitive_flags'] ?? false),
            ),
        );
    }

    /** @return array<string,mixed> */
    private function catalog_state(string $status, ?array $catalog, bool $usable): array
    {
        return array(
            'status'        => $status,
            'usable'        => $usable,
            'stale'         => is_array($catalog) && true === ($catalog['stale'] ?? false),
            'stale_reason'  => is_array($catalog) ? (string) ($catalog['stale_reason'] ?? '') : '',
            'refreshed_at'  => is_array($catalog) ? (int) ($catalog['refreshed_at'] ?? 0) : 0,
            'server_version'=> is_array($catalog) ? (string) ($catalog['server_version'] ?? '') : '',
        );
    }

    /** @param array<string,string> $dictionary @return list<array{id:string,label:string}> */
    private function dictionary_choices(array $dictionary): array
    {
        $out = array();
        foreach ($dictionary as $id => $label) {
            $out[] = array('id'=>(string) $id,'label'=>(string) $label);
        }
        return $out;
    }

    /** @return list<array{id:string,label:string}> */
    private static function comment_choices(): array
    {
        return array(
            array('id'=>'enabled','label'=>__('Enabled', 'argentwolf-video-processor')),
            array('id'=>'disabled','label'=>__('Disabled', 'argentwolf-video-processor')),
            array('id'=>'approval_required','label'=>__('Require approval', 'argentwolf-video-processor')),
        );
    }

    /** @param array<string,mixed> $catalog */
    private function channel_exists(string $channel_id, array $catalog): bool
    {
        foreach ($catalog['channels'] ?? array() as $channel) {
            if (is_array($channel) && $channel_id === ($channel['id'] ?? null)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $catalog */
    private function privacy_supported(string $privacy_id, array $catalog): bool
    {
        return in_array($privacy_id, array('1','2','3','4'), true)
            && isset($catalog['privacies'][$privacy_id]);
    }

    /** @param array<string,mixed> $catalog */
    private function language_supported(string $language, array $catalog): bool
    {
        return $this->language_id_shape($language) && isset($catalog['languages'][$language]);
    }

    private function language_id_shape(string $language): bool
    {
        return '' !== $language
            && strlen($language) <= 35
            && 1 === preg_match('/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/D', $language);
    }

    private function valid_title(string $value): bool
    {
        if ('' === $value || trim($value) !== $value || 1 !== preg_match('//u', $value)
            || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)
        ) {
            return false;
        }
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        return $length >= PeerTube_Publication_Plan::MIN_TITLE_CHARACTERS
            && $length <= PeerTube_Publication_Plan::MAX_TITLE_CHARACTERS;
    }

    private function valid_thumbnail(int $attachment_id): bool
    {
        $post = get_post($attachment_id);
        return is_object($post)
            && 'attachment' === ($post->post_type ?? null)
            && is_string($post->post_mime_type ?? null)
            && str_starts_with($post->post_mime_type, 'image/');
    }

    /** @return array<string,mixed>|null */
    private function active_peertube(string $backend_id): ?array
    {
        if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id) {
            return null;
        }
        $descriptor = $this->registry->get($backend_id);
        return is_array($descriptor)
            && Backend_Registry::PEERTUBE_TYPE === ($descriptor['type'] ?? null)
            && 'active' === ($descriptor['state'] ?? null)
            ? $descriptor : null;
    }

    /** @param array<string,mixed> $backend */
    private function backend_label(array $backend): string
    {
        $label = $backend['label'] ?? null;
        return is_string($label) && '' !== trim($label) ? trim($label) : (string) ($backend['id'] ?? 'PeerTube');
    }

    private function video_post(int $video_id): ?object
    {
        if ($video_id < 1) {
            return null;
        }
        $post = get_post($video_id);
        return is_object($post) && Video_Post_Type::POST_TYPE === ($post->post_type ?? null) ? $post : null;
    }

    private function strict_bool(mixed $value): ?bool
    {
        return true === $value || false === $value ? $value : null;
    }

    /** @return array{status:string} */
    private static function result(string $status): array
    {
        return array('status'=>$status);
    }
}

// EOF: includes/PeerTube_Publication_Editor_Service.php
