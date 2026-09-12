<?php
/**
 * File: includes/PeerTube_Publication_Plan.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Editable per-video PeerTube publication intent.
 *
 * This is not yet an upload-operation manifest. R46 later resolves backend/site
 * defaults and freezes the effective values into an immutable operation before
 * remote mutation. WordPress post taxonomy is intentionally not authoritative
 * for PeerTube tags: the plan records explicit tag review even when zero tags
 * are selected.
 */
final class PeerTube_Publication_Plan
{
    public const VERSION = 1;
    public const MAX_TAGS = 5;
    public const MIN_TAG_CHARACTERS = 2;
    public const MAX_TAG_CHARACTERS = 30;
    public const MIN_TITLE_CHARACTERS = 3;
    public const MAX_TITLE_CHARACTERS = 120;
    public const MAX_MARKDOWN_BYTES = 100000;
    public const MAX_SENSITIVE_REASON_CHARACTERS = 500;
    public const MAX_EMBED_DOMAINS = 100;

    public const DISPATCH_SEND_NOW = 'send_now';
    public const DISPATCH_ON_SCHEDULE_OR_PUBLISH = 'send_on_schedule_or_publish';
    public const RELEASE_WHEN_WORDPRESS_PUBLISHED = 'when_wordpress_published';
    public const PRE_PUBLISH_PRIVATE = '3';
    public const PRE_PUBLISH_UNLISTED = '2';

    /** @var list<string> */
    private const REQUIRED_REVIEW = array('title', 'channel', 'tags', 'privacy', 'moderation');

    /**
     * Strictly validate one already-normalized plan. Invalid structures fail
     * closed rather than silently dropping required publication decisions.
     *
     * Provider vocabulary IDs (privacy/licence/category) remain opaque decimal
     * strings until R46 backend-default/capability discovery binds them to a
     * concrete PeerTube instance.
     *
     * @return array<string,mixed>
     */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value) || self::VERSION !== self::positive_int($value['version'] ?? null)) {
            return array();
        }

        $backend_id = Backend_Identity::sanitize($value['backend_id'] ?? null);
        $channel_id = self::decimal_identifier($value['channel_id'] ?? null);
        $title = self::single_line($value['title'] ?? null, self::MAX_TITLE_CHARACTERS, false);
        $description = self::markdown($value['description_markdown'] ?? '');
        $tags = self::tags($value['tags'] ?? null);
        $support = self::support($value['support'] ?? null);
        $has_pre_publish_privacy = array_key_exists('pre_publish_privacy_id', $value);
        $pre_publish_privacy_id = $has_pre_publish_privacy
            ? self::enum($value['pre_publish_privacy_id'] ?? '', array(self::PRE_PUBLISH_PRIVATE, self::PRE_PUBLISH_UNLISTED))
            : self::PRE_PUBLISH_PRIVATE;
        $final_privacy_id = self::decimal_identifier($value['final_privacy_id'] ?? null);
        $licence_id = self::optional_decimal_identifier($value['licence_id'] ?? '');
        $category_id = self::optional_decimal_identifier($value['category_id'] ?? '');
        $language = self::language($value['language'] ?? '');
        $thumbnail_attachment_id = self::nonnegative_int($value['thumbnail_attachment_id'] ?? 0);
        $download_enabled = self::boolean($value['download_enabled'] ?? null);
        $originally_published_at = self::optional_utc_datetime($value['originally_published_at'] ?? '');
        $comments_policy = self::enum(
            $value['comments_policy'] ?? '',
            array('enabled', 'disabled', 'approval_required')
        );
        $moderation = self::moderation($value['moderation'] ?? null);
        $embed = self::embed_policy($value['embed'] ?? null);
        $review = self::review($value['review'] ?? null);
        $dispatch = self::enum(
            $value['dispatch_policy'] ?? '',
            array(self::DISPATCH_SEND_NOW, self::DISPATCH_ON_SCHEDULE_OR_PUBLISH)
        );
        $release = self::enum(
            $value['release_policy'] ?? '',
            array(self::RELEASE_WHEN_WORDPRESS_PUBLISHED)
        );
        $anchor_post_id = self::positive_int($value['anchor_post_id'] ?? null);

        if (
            '' === $backend_id
            || Backend_Registry::LOCAL_ID === $backend_id
            || '' === $channel_id
            || null === $title
            || '' === $title
            || self::length($title) < self::MIN_TITLE_CHARACTERS
            || null === $description
            || null === $tags
            || null === $support
            || '' === $pre_publish_privacy_id
            || '' === $final_privacy_id
            || null === $licence_id
            || null === $category_id
            || null === $language
            || null === $thumbnail_attachment_id
            || null === $download_enabled
            || null === $originally_published_at
            || '' === $comments_policy
            || null === $moderation
            || null === $embed
            || null === $review
            || '' === $dispatch
            || '' === $release
            || $anchor_post_id < 1
        ) {
            return array();
        }

        $result = array(
            'version'                 => self::VERSION,
            'backend_id'              => $backend_id,
            'channel_id'              => $channel_id,
            'title'                   => $title,
            'description_markdown'    => $description,
            'tags'                    => $tags,
            'support'                 => $support,
        );
        // Preserve the exact hash of older version-1 plans that predate the
        // explicit pre-publication visibility field. New/edited plans carry it.
        if ($has_pre_publish_privacy) {
            $result['pre_publish_privacy_id'] = $pre_publish_privacy_id;
        }
        $result += array(
            'final_privacy_id'        => $final_privacy_id,
            'licence_id'              => $licence_id,
            'category_id'             => $category_id,
            'language'                => $language,
            'thumbnail_attachment_id' => $thumbnail_attachment_id,
            'download_enabled'        => $download_enabled,
            'originally_published_at' => $originally_published_at,
            'comments_policy'         => $comments_policy,
            'moderation'              => $moderation,
            'embed'                   => $embed,
            'review'                  => $review,
            'dispatch_policy'         => $dispatch,
            'release_policy'          => $release,
            'anchor_post_id'          => $anchor_post_id,
        );
        return $result;
    }

    /** @param array<string,mixed> $plan */
    public static function pre_publish_privacy_id(array $plan): string
    {
        $value = $plan['pre_publish_privacy_id'] ?? self::PRE_PUBLISH_PRIVATE;
        return is_string($value) && in_array($value, array(self::PRE_PUBLISH_PRIVATE, self::PRE_PUBLISH_UNLISTED), true)
            ? $value
            : self::PRE_PUBLISH_PRIVATE;
    }

    /** @param array<string,mixed> $plan */
    public static function ready_for_dispatch(array $plan): bool
    {
        $plan = self::sanitize($plan);
        if (array() === $plan) {
            return false;
        }

        foreach (self::REQUIRED_REVIEW as $field) {
            if (true !== ($plan['review'][$field] ?? false)) {
                return false;
            }
        }

        return true === ($plan['moderation']['reviewed'] ?? false);
    }

    /** @param array<string,mixed> $plan @return list<string> */
    public static function missing_review(array $plan): array
    {
        $plan = self::sanitize($plan);
        if (array() === $plan) {
            return array('invalid_plan');
        }

        $missing = array();
        foreach (self::REQUIRED_REVIEW as $field) {
            if (true !== ($plan['review'][$field] ?? false)) {
                $missing[] = $field;
            }
        }

        if (true !== ($plan['moderation']['reviewed'] ?? false) && ! in_array('moderation', $missing, true)) {
            $missing[] = 'moderation';
        }

        return $missing;
    }

    /** @return list<string>|null */
    private static function tags(mixed $value): ?array
    {
        if (! is_array($value) || count($value) > self::MAX_TAGS || array_values($value) !== $value) {
            return null;
        }

        $result = array();
        $seen = array();
        foreach ($value as $tag) {
            $tag = self::single_line($tag, self::MAX_TAG_CHARACTERS, false);
            if (null === $tag || '' === $tag || self::length($tag) < self::MIN_TAG_CHARACTERS) {
                return null;
            }

            $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            $result[] = $tag;
        }

        return $result;
    }

    /** @return array{mode:string,preset_id:string,markdown:string}|null */
    private static function support(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $mode = self::enum($value['mode'] ?? '', array('none', 'preset', 'custom'));
        $preset_id = self::slug($value['preset_id'] ?? '', 64, true);
        $markdown = self::markdown($value['markdown'] ?? '');
        if ('' === $mode || null === $markdown) {
            return null;
        }

        if ('none' === $mode && ('' !== $preset_id || '' !== $markdown)) {
            return null;
        }
        if ('preset' === $mode && ('' === $preset_id || '' !== $markdown)) {
            return null;
        }
        if ('custom' === $mode && ('' !== $preset_id || '' === $markdown)) {
            return null;
        }

        return array(
            'mode'      => $mode,
            'preset_id' => $preset_id,
            'markdown'  => $markdown,
        );
    }

    /** @return array{reviewed:bool,sensitive:bool,reason:string,violent:bool,sexually_explicit:bool}|null */
    private static function moderation(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $reviewed = self::boolean($value['reviewed'] ?? null);
        $sensitive = self::boolean($value['sensitive'] ?? null);
        $violent = self::boolean($value['violent'] ?? null);
        $sexual = self::boolean($value['sexually_explicit'] ?? null);
        $reason = self::single_line($value['reason'] ?? '', self::MAX_SENSITIVE_REASON_CHARACTERS, true);
        if (null === $reviewed || null === $sensitive || null === $violent || null === $sexual || null === $reason) {
            return null;
        }

        if (! $sensitive && ('' !== $reason || $violent || $sexual)) {
            return null;
        }

        return array(
            'reviewed'           => $reviewed,
            'sensitive'          => $sensitive,
            'reason'             => $reason,
            'violent'            => $violent,
            'sexually_explicit'  => $sexual,
        );
    }

    /** @return array{restricted:bool,domains:list<string>}|null */
    private static function embed_policy(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $restricted = self::boolean($value['restricted'] ?? null);
        $domains = $value['domains'] ?? null;
        if (null === $restricted || ! is_array($domains) || array_values($domains) !== $domains || count($domains) > self::MAX_EMBED_DOMAINS) {
            return null;
        }

        $result = array();
        $seen = array();
        foreach ($domains as $domain) {
            if (! is_string($domain) || trim($domain) !== $domain || strlen($domain) > 253) {
                return null;
            }
            if ('' === $domain || false !== strpos($domain, '://') || str_contains($domain, '/') || str_contains($domain, ':')) {
                return null;
            }
            if (false === filter_var($domain, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
                return null;
            }
            $key = strtolower($domain);
            if (isset($seen[$key])) {
                return null;
            }
            $seen[$key] = true;
            $result[] = $domain;
        }

        if (! $restricted && array() !== $result) {
            return null;
        }
        if ($restricted && array() === $result) {
            return null;
        }

        return array('restricted' => $restricted, 'domains' => $result);
    }

    /** @return array<string,bool>|null */
    private static function review(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $result = array();
        foreach (self::REQUIRED_REVIEW as $field) {
            $flag = self::boolean($value[$field] ?? null);
            if (null === $flag) {
                return null;
            }
            $result[$field] = $flag;
        }
        return $result;
    }

    private static function markdown(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > self::MAX_MARKDOWN_BYTES || 1 !== preg_match('//u', $value)) {
            return null;
        }

        return 1 === preg_match('/[\x00\x0B\x0C\x0E-\x1F\x7F]/', $value) ? null : $value;
    }

    private static function single_line(mixed $value, int $max_characters, bool $allow_empty): ?string
    {
        if (! is_string($value) || trim($value) !== $value || 1 !== preg_match('//u', $value)) {
            return null;
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $value) || self::length($value) > $max_characters) {
            return null;
        }
        if (! $allow_empty && '' === $value) {
            return null;
        }
        return $value;
    }

    private static function optional_utc_datetime(mixed $value): ?string
    {
        if ('' === $value) {
            return '';
        }
        if (! is_string($value) || 1 !== preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $value)) {
            return null;
        }

        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d\\TH:i:s\\Z', $value, new \DateTimeZone('UTC'));
        $errors = \DateTimeImmutable::getLastErrors();
        if (false === $parsed || (is_array($errors) && (0 !== $errors['warning_count'] || 0 !== $errors['error_count']))) {
            return null;
        }
        return $parsed->format('Y-m-d\\TH:i:s\\Z') === $value ? $value : null;
    }

    private static function language(mixed $value): ?string
    {
        if ('' === $value) {
            return '';
        }
        if (! is_string($value) || strlen($value) > 35 || 1 !== preg_match('/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+)*$/D', $value)) {
            return null;
        }
        return $value;
    }

    private static function slug(mixed $value, int $max_length, bool $allow_empty): string
    {
        if ('' === $value && $allow_empty) {
            return '';
        }
        if (! is_string($value) || strlen($value) > $max_length || 1 !== preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $value)) {
            return '';
        }
        return $value;
    }

    private static function decimal_identifier(mixed $value): string
    {
        return self::optional_decimal_identifier($value) ?? '';
    }

    private static function optional_decimal_identifier(mixed $value): ?string
    {
        if ('' === $value) {
            return '';
        }
        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        return false !== $parsed && (string) $parsed === $value ? $value : null;
    }

    private static function positive_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return 0;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 1)));
        return false !== $parsed && (string) $parsed === $value ? (int) $parsed : 0;
    }

    private static function nonnegative_int(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value) || 1 !== preg_match('/^(?:0|[1-9][0-9]*)$/D', $value)) {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, array('options' => array('min_range' => 0)));
        return false !== $parsed && (string) $parsed === $value ? (int) $parsed : null;
    }

    private static function boolean(mixed $value): ?bool
    {
        if (true === $value || 1 === $value || '1' === $value || 'true' === $value) {
            return true;
        }
        if (false === $value || 0 === $value || '0' === $value || 'false' === $value) {
            return false;
        }
        return null;
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

// EOF: includes/PeerTube_Publication_Plan.php
