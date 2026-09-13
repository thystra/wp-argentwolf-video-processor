<?php
/** File: includes/Source_Retirement_Record.php */
declare(strict_types=1);
namespace ArgentVideo;

/** Durable provenance retained after a WordPress source attachment is retired. */
final class Source_Retirement_Record
{
    public const VERSION = 1;

    /** @return array<string,mixed> */
    public static function capture(int $attachment_id, array $source_identity, int $task_id, int $removed_at = 0): array
    {
        $source_identity = WordPress_Source_File::sanitize_identity($source_identity);
        $attachment = $attachment_id > 0 ? get_post($attachment_id) : null;
        if ($attachment_id < 1 || $task_id < 1 || $removed_at < 0 || array() === $source_identity
            || ! is_object($attachment) || 'attachment' !== ($attachment->post_type ?? null)) {
            return array();
        }
        $mime = sanitize_mime_type((string) get_post_mime_type($attachment_id));
        if (! str_starts_with($mime, 'video/')) {
            return array();
        }
        $title = sanitize_text_field((string) get_the_title($attachment_id));
        $relative = (string) $source_identity['relative_path'];
        $filename = sanitize_file_name(wp_basename($relative));
        if ('' === $filename) {
            return array();
        }
        return self::sanitize(array(
            'version'               => self::VERSION,
            'former_attachment_id'  => $attachment_id,
            'attachment_title'      => $title,
            'filename'              => $filename,
            'mime_type'             => $mime,
            'relative_path'         => $relative,
            'bytes'                 => (int) $source_identity['bytes'],
            'source_identity'       => $source_identity,
            'retention_task_id'     => $task_id,
            'removed_at'            => $removed_at,
        ));
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    public static function with_removed_at(array $record, int $removed_at): array
    {
        $record = self::sanitize($record);
        if (array() === $record || $removed_at < 1) {
            return array();
        }
        $record['removed_at'] = $removed_at;
        return self::sanitize($record);
    }

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value) || array(
            'version',
            'former_attachment_id',
            'attachment_title',
            'filename',
            'mime_type',
            'relative_path',
            'bytes',
            'source_identity',
            'retention_task_id',
            'removed_at',
        ) !== array_keys($value)) {
            return array();
        }
        if (self::VERSION !== ($value['version'] ?? null)) {
            return array();
        }
        foreach (array('former_attachment_id', 'retention_task_id') as $key) {
            if (! is_int($value[$key]) || $value[$key] < 1) {
                return array();
            }
        }
        if (! is_int($value['removed_at']) || $value['removed_at'] < 0) {
            return array();
        }
        if (! is_int($value['bytes']) || $value['bytes'] < 0) {
            return array();
        }
        $source = WordPress_Source_File::sanitize_identity($value['source_identity'] ?? null);
        if (array() === $source || $value['bytes'] !== (int) $source['bytes']) {
            return array();
        }
        $title = sanitize_text_field((string) ($value['attachment_title'] ?? ''));
        if ($title !== (string) $value['attachment_title'] || strlen($title) > 500) {
            return array();
        }
        $filename = sanitize_file_name((string) ($value['filename'] ?? ''));
        if ('' === $filename || $filename !== (string) $value['filename']) {
            return array();
        }
        $mime = sanitize_mime_type((string) ($value['mime_type'] ?? ''));
        if ($mime !== (string) $value['mime_type'] || ! str_starts_with($mime, 'video/')) {
            return array();
        }
        $relative = (string) ($value['relative_path'] ?? '');
        if ($relative !== (string) $source['relative_path'] || '' === $relative) {
            return array();
        }
        $value['source_identity'] = $source;
        return $value;
    }
}

// EOF: includes/Source_Retirement_Record.php
