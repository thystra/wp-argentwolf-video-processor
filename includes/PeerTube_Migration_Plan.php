<?php
/**
 * File: includes/PeerTube_Migration_Plan.php
 */

declare(strict_types=1);

namespace ArgentVideo;

/**
 * Inert per-video migration planning state.
 *
 * R46.7 deliberately stores this under a migration-only meta key. It is not a
 * live Video_Destination or PeerTube_Publication_Plan and therefore cannot wake
 * the R46.5 publication synchronizer/executor. R46.8 must explicitly promote a
 * reviewed plan before any remote mutation can occur.
 */
final class PeerTube_Migration_Plan
{
    public const VERSION = 1;
    public const STATUS_NEEDS_REVIEW = 'needs_review';
    public const STATUS_READY = 'ready';
    public const MAX_ISSUES = 32;
    public const MAX_SUGGESTED_TAGS = 20;
    public const MAX_ISSUE_LENGTH = 64;

    /** @return array<string,mixed> */
    public static function sanitize(mixed $value): array
    {
        if (! is_array($value) || self::VERSION !== self::positive_int($value['version'] ?? null)) {
            return array();
        }

        $video_id = self::positive_int($value['video_id'] ?? null);
        $attachment_id = self::positive_int($value['attachment_id'] ?? null);
        $anchor_post_id = self::positive_int($value['anchor_post_id'] ?? null);
        $backend_id = Backend_Identity::sanitize($value['backend_id'] ?? null);
        $channel_id = self::decimal_identifier($value['channel_id'] ?? null);
        $status = self::enum($value['status'] ?? null, array(self::STATUS_NEEDS_REVIEW, self::STATUS_READY));
        $planned_at = self::positive_int($value['planned_at'] ?? null);
        $updated_at = self::positive_int($value['updated_at'] ?? null);
        $issues = self::issues($value['issues'] ?? null);
        $suggested_tags = self::suggested_tags($value['suggested_tags'] ?? null);
        $publication_plan = is_array($value['publication_plan'] ?? null)
            ? PeerTube_Publication_Plan::sanitize($value['publication_plan'])
            : array();

        if (
            $video_id < 1 || $attachment_id < 1 || $anchor_post_id < 1
            || '' === $backend_id || Backend_Registry::LOCAL_ID === $backend_id
            || '' === $channel_id || '' === $status || $planned_at < 1 || $updated_at < $planned_at
            || null === $issues || null === $suggested_tags
        ) {
            return array();
        }

        if (array() !== $publication_plan) {
            if (
                $backend_id !== ($publication_plan['backend_id'] ?? null)
                || $channel_id !== ($publication_plan['channel_id'] ?? null)
                || $anchor_post_id !== ($publication_plan['anchor_post_id'] ?? null)
            ) {
                return array();
            }
        }

        if (self::STATUS_READY === $status) {
            if (array() !== $issues || array() === $publication_plan || ! PeerTube_Publication_Plan::ready_for_dispatch($publication_plan)) {
                return array();
            }
        } elseif (array() === $issues) {
            // Needs-review state must explain why it is not promotable.
            return array();
        }

        return array(
            'version'          => self::VERSION,
            'video_id'         => $video_id,
            'attachment_id'    => $attachment_id,
            'anchor_post_id'   => $anchor_post_id,
            'backend_id'       => $backend_id,
            'channel_id'       => $channel_id,
            'status'           => $status,
            'issues'           => $issues,
            'suggested_tags'   => $suggested_tags,
            'publication_plan' => array() === $publication_plan ? null : $publication_plan,
            'planned_at'       => $planned_at,
            'updated_at'       => $updated_at,
        );
    }

    /** @return list<string>|null */
    private static function issues(mixed $value): ?array
    {
        if (! is_array($value) || array_values($value) !== $value || count($value) > self::MAX_ISSUES) {
            return null;
        }
        $result = array();
        foreach ($value as $issue) {
            if (! is_string($issue) || 1 !== preg_match('/^[a-z0-9_]{1,' . self::MAX_ISSUE_LENGTH . '}$/D', $issue)) {
                return null;
            }
            if (! in_array($issue, $result, true)) {
                $result[] = $issue;
            }
        }
        sort($result, SORT_STRING);
        return $result;
    }

    /** @return list<string>|null */
    private static function suggested_tags(mixed $value): ?array
    {
        if (! is_array($value) || array_values($value) !== $value || count($value) > self::MAX_SUGGESTED_TAGS) {
            return null;
        }
        $result = array();
        $seen = array();
        foreach ($value as $tag) {
            if (! is_string($tag)) {
                return null;
            }
            $tag = trim(preg_replace('/\s+/u', ' ', $tag) ?? '');
            if ('' === $tag || strlen($tag) > 120 || 1 !== preg_match('//u', $tag)) {
                return null;
            }
            $key = function_exists('mb_strtolower') ? mb_strtolower($tag, 'UTF-8') : strtolower($tag);
            if (! isset($seen[$key])) {
                $seen[$key] = true;
                $result[] = $tag;
            }
        }
        return $result;
    }

    private static function positive_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }
        if (! is_string($value) || 1 !== preg_match('/^[1-9][0-9]*$/D', $value)) {
            return 0;
        }
        $max = (string) PHP_INT_MAX;
        if (strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0)) {
            return 0;
        }
        return (int) $value;
    }

    private static function decimal_identifier(mixed $value): string
    {
        if (is_int($value)) {
            return $value > 0 ? (string) $value : '';
        }
        return is_string($value) && 1 === preg_match('/^[1-9][0-9]*$/D', $value) ? $value : '';
    }

    /** @param list<string> $allowed */
    private static function enum(mixed $value, array $allowed): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : '';
    }
}

// EOF: includes/PeerTube_Migration_Plan.php
