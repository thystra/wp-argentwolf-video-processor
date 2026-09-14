<?php
/** File: includes/External_Video_Source.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Durable provider-neutral source record for an externally hosted AWVP Video. */
final class External_Video_Source
{
    public const VERSION = 1;

    /** @return array<string,mixed> */
    public static function create(array $identity, string $title = '', int $verified_at = 0): array
    {
        $identity = Video_Embed_Identity::sanitize($identity);
        if (array() === $identity || $verified_at < 0) {
            return array();
        }
        $title = sanitize_text_field($title);
        if (strlen($title) > 500) {
            $title = substr($title, 0, 500);
        }
        return array(
            'version' => self::VERSION,
            'identity' => $identity,
            'title' => $title,
            'verified_at' => $verified_at,
        );
    }

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value)
            || array('version', 'identity', 'title', 'verified_at') !== array_keys($value)
            || self::VERSION !== ($value['version'] ?? null)
            || ! is_string($value['title'] ?? null)
            || ! is_int($value['verified_at'] ?? null)
            || $value['verified_at'] < 0) {
            return array();
        }
        return self::create((array) $value['identity'], (string) $value['title'], (int) $value['verified_at']);
    }
}
// EOF
