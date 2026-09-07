<?php
/**
 * File: includes/PeerTube_Publication_Catalog.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/** Bounded non-secret projection of one PeerTube backend's publication choices. */
final class PeerTube_Publication_Catalog
{
    public const VERSION = 1;
    public const MAX_CHANNELS = 500;
    public const MAX_VOCABULARY_ITEMS = 512;
    public const MAX_LABEL_CHARACTERS = 240;

    private const STALE_REASONS = array(
        'remote_failed',
        'authentication_required',
        'backend_context_changed',
        'cache_failed',
        'refresh_refused',
    );

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        $keys = array(
            'version','backend_id','origin','secret_generation','server_version','refreshed_at',
            'stale','stale_since','stale_reason','channels','privacies','licences','categories',
            'languages','capabilities',
        );
        if (! is_array($value) || $keys !== array_keys($value) || self::VERSION !== ($value['version'] ?? null)) {
            return array();
        }

        $backend_id = Backend_Identity::sanitize($value['backend_id'] ?? null);
        $origin = PeerTube_Origin::sanitize($value['origin'] ?? null);
        $secret_generation = self::positive_int($value['secret_generation'] ?? null);
        $server_version = self::server_version($value['server_version'] ?? null);
        $refreshed_at = self::positive_int($value['refreshed_at'] ?? null);
        $stale = $value['stale'] ?? null;
        $stale_since = self::nullable_positive_int($value['stale_since'] ?? null);
        $stale_reason = self::stale_reason($value['stale_reason'] ?? null);
        $channels = self::channels($value['channels'] ?? null);
        $privacies = self::numeric_vocabulary($value['privacies'] ?? null, 255);
        $licences = self::numeric_vocabulary($value['licences'] ?? null, 65535);
        $categories = self::numeric_vocabulary($value['categories'] ?? null, 65535);
        $languages = self::language_vocabulary($value['languages'] ?? null);
        $capabilities = self::capabilities($value['capabilities'] ?? null, $privacies);

        if (
            '' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id
            || ! is_string($value['backend_id']) || $backend_id !== $value['backend_id']
            || '' === $origin || ! is_string($value['origin']) || $origin !== $value['origin']
            || $secret_generation < 1 || '' === $server_version || $refreshed_at < 1
            || ! is_bool($stale)
            || (false === $stale && (null !== $stale_since || '' !== $stale_reason))
            || (true === $stale && (null === $stale_since || $stale_since < $refreshed_at || '' === $stale_reason))
            || null === $channels || null === $privacies || null === $licences
            || null === $categories || null === $languages || null === $capabilities
        ) {
            return array();
        }

        return array(
            'version'           => self::VERSION,
            'backend_id'        => $backend_id,
            'origin'            => $origin,
            'secret_generation' => $secret_generation,
            'server_version'    => $server_version,
            'refreshed_at'      => $refreshed_at,
            'stale'             => $stale,
            'stale_since'       => $stale_since,
            'stale_reason'      => $stale_reason,
            'channels'          => $channels,
            'privacies'         => $privacies,
            'licences'          => $licences,
            'categories'        => $categories,
            'languages'         => $languages,
            'capabilities'      => $capabilities,
        );
    }

    /** @return list<array{id:string,name:string,display_name:string,authority:string}>|null */
    private static function channels(mixed $value): ?array
    {
        if (! is_array($value) || array_values($value) !== $value || count($value) > self::MAX_CHANNELS) {
            return null;
        }
        $out = array();
        $seen = array();
        foreach ($value as $row) {
            if (! is_array($row) || array_values($row) === $row) {
                return null;
            }
            $id = self::decimal_id($row['id'] ?? null, PHP_INT_MAX);
            $name = self::text($row['name'] ?? null, 191);
            $display = self::text($row['display_name'] ?? null, self::MAX_LABEL_CHARACTERS);
            if ('' === $id || '' === $name || '' === $display || 'owned' !== ($row['authority'] ?? null) || isset($seen[$id])) {
                return null;
            }
            $seen[$id] = true;
            $out[] = array('id'=>$id,'name'=>$name,'display_name'=>$display,'authority'=>'owned');
        }
        return $out;
    }

    /** @return array<string,string>|null */
    private static function numeric_vocabulary(mixed $value, int $maximum_id): ?array
    {
        if (! is_array($value) || array_values($value) === $value || count($value) > self::MAX_VOCABULARY_ITEMS) {
            return null;
        }
        $out = array();
        foreach ($value as $key => $label) {
            $id = self::decimal_id($key, $maximum_id);
            $text = self::text($label, self::MAX_LABEL_CHARACTERS);
            if ('' === $id || '' === $text || isset($out[$id])) {
                return null;
            }
            $out[$id] = $text;
        }
        ksort($out, SORT_NATURAL);
        return $out;
    }

    /** @return array<string,string>|null */
    private static function language_vocabulary(mixed $value): ?array
    {
        if (! is_array($value) || array_values($value) === $value || count($value) > self::MAX_VOCABULARY_ITEMS) {
            return null;
        }
        $out = array();
        foreach ($value as $key => $label) {
            if (! is_string($key) || 1 !== preg_match('/^(?:_[a-z0-9_-]{1,34}|[A-Za-z0-9][A-Za-z0-9_-]{0,34})$/D', $key)) {
                return null;
            }
            $text = self::text($label, self::MAX_LABEL_CHARACTERS);
            if ('' === $text || isset($out[$key])) {
                return null;
            }
            $out[$key] = $text;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /** @param array<string,string> $privacies @return array<string,mixed>|null */
    private static function capabilities(mixed $value, array $privacies): ?array
    {
        if (! is_array($value) || array_values($value) === $value) {
            return null;
        }
        $keys = array('sensitive_content','sensitive_flags','password_privacy');
        if ($keys !== array_keys($value) || ! is_bool($value['sensitive_content']) || ! is_bool($value['password_privacy'])) {
            return null;
        }
        if (! is_bool($value['sensitive_flags']) && null !== $value['sensitive_flags']) {
            return null;
        }
        if ($value['password_privacy'] !== array_key_exists('5', $privacies)) {
            return null;
        }
        return array(
            'sensitive_content' => $value['sensitive_content'],
            'sensitive_flags'   => $value['sensitive_flags'],
            'password_privacy' => $value['password_privacy'],
        );
    }

    private static function decimal_id(mixed $value, int $maximum): string
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= $maximum ? (string) $value : '';
        }
        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return '';
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, array('options'=>array('min_range'=>1,'max_range'=>$maximum)));
        return false === $number ? '' : (string) $number;
    }

    private static function positive_int(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
    }

    private static function nullable_positive_int(mixed $value): ?int
    {
        return null === $value ? null : (is_int($value) && $value > 0 ? $value : 0);
    }

    private static function stale_reason(mixed $value): string
    {
        return is_string($value) && in_array($value, self::STALE_REASONS, true) ? $value : '';
    }

    private static function server_version(mixed $value): string
    {
        return is_string($value) && strlen($value) <= 64
            && 1 === preg_match('/^[0-9]+\.[0-9]+\.[0-9]+(?:[-+][0-9A-Za-z.-]+)?$/D', $value) ? $value : '';
    }

    private static function text(mixed $value, int $max_chars): string
    {
        if (! is_string($value) || '' === $value || strlen($value) > 2048 || trim($value) !== $value
            || 1 !== preg_match('//u', $value) || 1 === preg_match('/[\x00-\x1F\x7F]/', $value)) {
            return '';
        }
        $m = array();
        $length = preg_match_all('/./us', $value, $m);
        return is_int($length) && $length <= $max_chars ? $value : '';
    }
}

// EOF: includes/PeerTube_Publication_Catalog.php
