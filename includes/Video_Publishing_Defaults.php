<?php
/**
 * File: includes/Video_Publishing_Defaults.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Site/backend defaults used to prefill new PeerTube publication plans.
 *
 * These defaults are editorial convenience only. They never rewrite an
 * existing video's stored destination/publication plan and they never satisfy
 * the per-video review flags required by PeerTube_Publication_Plan.
 */
final class Video_Publishing_Defaults
{
    public const VERSION = 1;
    public const MAX_SUPPORT_PRESETS = 20;
    public const MAX_SUPPORT_LABEL_CHARACTERS = 80;

    /** @return array<string,mixed> */
    public static function defaults(): array
    {
        return array(
            'version'             => self::VERSION,
            'default_destination' => Video_Destination::local(),
            'site'                => array(
                'final_privacy_id'   => '1',
                'licence_id'         => '',
                'category_id'        => '',
                'language'           => '',
                'comments_policy'    => 'enabled',
                'download_enabled'   => true,
                'support_preset_id'  => '',
                'dispatch_policy'    => PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH,
                'moderation'         => array(
                    'sensitive'          => false,
                    'reason'             => '',
                    'violent'            => false,
                    'sexually_explicit'  => false,
                ),
            ),
            'backend_overrides'   => array(),
            'support_presets'     => array(),
        );
    }

    /**
     * @return array<string,mixed>
     */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value) || self::VERSION !== self::positive_int($value['version'] ?? null)) {
            return array();
        }

        $destination = Video_Destination::sanitize($value['default_destination'] ?? null);
        $site = self::site_defaults($value['site'] ?? null);
        $overrides = self::backend_overrides($value['backend_overrides'] ?? null);
        $presets = self::support_presets($value['support_presets'] ?? null);
        if (array() === $destination || null === $site || null === $overrides || null === $presets) {
            return array();
        }

        if ('' !== $site['support_preset_id'] && ! isset($presets[$site['support_preset_id']])) {
            return array();
        }

        return array(
            'version'             => self::VERSION,
            'default_destination' => $destination,
            'site'                => $site,
            'backend_overrides'   => $overrides,
            'support_presets'     => $presets,
        );
    }

    /**
     * Resolve effective defaults for one concrete PeerTube backend.
     *
     * Backend provider-vocabulary overrides use null to mean "inherit site";
     * an empty licence/category string is a deliberate "none" value.
     * Channel falls back to the backend descriptor's already-qualified default
     * destination when no publication-specific override is configured.
     *
     * @param array<string,mixed> $settings
     * @param array<string,mixed> $descriptor
     * @return array<string,mixed>
     */
    public static function effective_for_backend(array $settings, array $descriptor): array
    {
        $settings = self::sanitize($settings);
        if (array() === $settings) {
            return array();
        }

        $backend_id = Backend_Identity::sanitize($descriptor['id'] ?? null);
        if (
            '' === $backend_id
            || Backend_Registry::LOCAL_ID === $backend_id
            || Backend_Registry::PEERTUBE_TYPE !== ($descriptor['type'] ?? null)
            || 'active' !== ($descriptor['state'] ?? null)
        ) {
            return array();
        }

        $fallback_channel = PeerTube_Connection_Input::destination_id($descriptor['default_destination'] ?? null);
        if ('' === $fallback_channel) {
            return array();
        }

        $site = $settings['site'];
        $override = $settings['backend_overrides'][$backend_id] ?? array();
        $channel_id = is_string($override['channel_id'] ?? null) && '' !== $override['channel_id']
            ? $override['channel_id']
            : $fallback_channel;

        $privacy = self::inherit($override['final_privacy_id'] ?? null, $site['final_privacy_id']);
        $licence = self::inherit($override['licence_id'] ?? null, $site['licence_id']);
        $category = self::inherit($override['category_id'] ?? null, $site['category_id']);

        $support_id = $site['support_preset_id'];
        $support = array('mode' => 'none', 'preset_id' => '', 'markdown' => '');
        if ('' !== $support_id) {
            $preset = $settings['support_presets'][$support_id] ?? null;
            if (! is_array($preset)) {
                return array();
            }
            $support = array(
                'mode'      => 'preset',
                'preset_id' => $support_id,
                'markdown'  => $preset['markdown'],
            );
        }

        return array(
            'backend_id'         => $backend_id,
            'channel_id'         => $channel_id,
            'final_privacy_id'   => $privacy,
            'licence_id'         => $licence,
            'category_id'        => $category,
            'language'           => $site['language'],
            'comments_policy'    => $site['comments_policy'],
            'download_enabled'   => $site['download_enabled'],
            'support'            => $support,
            'dispatch_policy'    => $site['dispatch_policy'],
            // Deliberately no `reviewed` flag: this is only an editor prefill.
            'moderation'         => $site['moderation'],
        );
    }

    /** @return array<string,array{label:string,markdown:string}>|null */
    private static function support_presets(mixed $value): ?array
    {
        if (! is_array($value) || count($value) > self::MAX_SUPPORT_PRESETS) {
            return null;
        }

        $result = array();
        foreach ($value as $key => $preset) {
            $id = self::slug($key, 64);
            if ('' === $id || ! is_array($preset)) {
                return null;
            }
            $label = self::single_line($preset['label'] ?? null, self::MAX_SUPPORT_LABEL_CHARACTERS, false);
            $markdown = self::markdown($preset['markdown'] ?? null);
            if (null === $label || null === $markdown || '' === $markdown) {
                return null;
            }
            $result[$id] = array('label' => $label, 'markdown' => $markdown);
        }

        ksort($result, SORT_STRING);
        return $result;
    }

    /** @return array<string,mixed>|null */
    private static function site_defaults(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $privacy = self::privacy_id($value['final_privacy_id'] ?? null, false);
        $licence = self::provider_id($value['licence_id'] ?? null, true);
        $category = self::provider_id($value['category_id'] ?? null, true);
        $language = self::language($value['language'] ?? null);
        $comments = self::enum($value['comments_policy'] ?? null, array('enabled', 'disabled', 'approval_required'));
        $download = self::boolean($value['download_enabled'] ?? null);
        $support_id = self::slug_allow_empty($value['support_preset_id'] ?? null, 64);
        $dispatch = self::enum(
            $value['dispatch_policy'] ?? null,
            array(PeerTube_Publication_Plan::DISPATCH_SEND_NOW, PeerTube_Publication_Plan::DISPATCH_ON_SCHEDULE_OR_PUBLISH)
        );
        $moderation = self::moderation_prefill($value['moderation'] ?? null);

        if (
            null === $privacy || null === $licence || null === $category || null === $language
            || '' === $comments || null === $download || null === $support_id || '' === $dispatch
            || null === $moderation
        ) {
            return null;
        }

        return array(
            'final_privacy_id'  => $privacy,
            'licence_id'        => $licence,
            'category_id'       => $category,
            'language'          => $language,
            'comments_policy'   => $comments,
            'download_enabled'  => $download,
            'support_preset_id' => $support_id,
            'dispatch_policy'   => $dispatch,
            'moderation'        => $moderation,
        );
    }

    /** @return array<string,array<string,mixed>>|null */
    private static function backend_overrides(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }

        $result = array();
        foreach ($value as $backend_key => $override) {
            $backend_id = Backend_Identity::sanitize($backend_key);
            if ('' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id || ! is_array($override)) {
                return null;
            }

            $channel = self::optional_channel($override['channel_id'] ?? null);
            $privacy = self::privacy_id($override['final_privacy_id'] ?? null, true);
            $licence = self::optional_provider_override($override['licence_id'] ?? null);
            $category = self::optional_provider_override($override['category_id'] ?? null);
            if (null === $channel || null === $privacy || self::INVALID === $licence || self::INVALID === $category) {
                return null;
            }

            $result[$backend_id] = array(
                'channel_id'       => $channel,
                'final_privacy_id' => $privacy,
                'licence_id'       => $licence,
                'category_id'      => $category,
            );
        }

        ksort($result, SORT_STRING);
        return $result;
    }

    private const INVALID = '__awvp_invalid__';

    private static function inherit(mixed $override, string $site): string
    {
        return null === $override ? $site : (string) $override;
    }

    private static function optional_channel(mixed $value): ?string
    {
        if ('' === $value || null === $value) {
            return '';
        }
        return is_string($value) && '' !== PeerTube_Connection_Input::destination_id($value) ? $value : null;
    }

    private static function privacy_id(mixed $value, bool $allow_inherit): ?string
    {
        if ($allow_inherit && null === $value) {
            return null;
        }
        if (! is_string($value) || ! in_array($value, array('1', '2', '3', '4'), true)) {
            return null;
        }
        return $value;
    }

    private static function provider_id(mixed $value, bool $allow_empty): ?string
    {
        if ($allow_empty && '' === $value) {
            return '';
        }
        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return null;
        }
        return $value;
    }

    private static function optional_provider_override(mixed $value): string|null
    {
        if (null === $value) {
            return null;
        }
        if ('' === $value) {
            return '';
        }
        $id = self::provider_id($value, false);
        return null === $id ? self::INVALID : $id;
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

    /** @return array{sensitive:bool,reason:string,violent:bool,sexually_explicit:bool}|null */
    private static function moderation_prefill(mixed $value): ?array
    {
        if (! is_array($value)) {
            return null;
        }
        $sensitive = self::boolean($value['sensitive'] ?? null);
        $violent = self::boolean($value['violent'] ?? null);
        $sexual = self::boolean($value['sexually_explicit'] ?? null);
        $reason = self::single_line($value['reason'] ?? null, PeerTube_Publication_Plan::MAX_SENSITIVE_REASON_CHARACTERS, true);
        if (null === $sensitive || null === $violent || null === $sexual || null === $reason) {
            return null;
        }
        if (! $sensitive && ('' !== $reason || $violent || $sexual)) {
            return null;
        }
        return array(
            'sensitive'         => $sensitive,
            'reason'            => $reason,
            'violent'           => $violent,
            'sexually_explicit' => $sexual,
        );
    }

    private static function markdown(mixed $value): ?string
    {
        if (! is_string($value) || strlen($value) > PeerTube_Publication_Plan::MAX_MARKDOWN_BYTES || 1 !== preg_match('//u', $value)) {
            return null;
        }
        return 1 === preg_match('/[\x00\x0B\x0C\x0E-\x1F\x7F]/', $value) ? null : $value;
    }

    private static function single_line(mixed $value, int $max, bool $allow_empty): ?string
    {
        if (! is_string($value) || trim($value) !== $value || 1 !== preg_match('//u', $value)) {
            return null;
        }
        if (1 === preg_match('/[\x00-\x1F\x7F]/', $value) || self::length($value) > $max) {
            return null;
        }
        if (! $allow_empty && '' === $value) {
            return null;
        }
        return $value;
    }

    private static function slug(mixed $value, int $max): string
    {
        return is_string($value) && strlen($value) <= $max && 1 === preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $value)
            ? $value
            : '';
    }

    private static function slug_allow_empty(mixed $value, int $max): ?string
    {
        if ('' === $value) {
            return '';
        }
        $slug = self::slug($value, $max);
        return '' === $slug ? null : $slug;
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
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

    private static function length(string $value): int
    {
        return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
    }
}

// EOF: includes/Video_Publishing_Defaults.php
