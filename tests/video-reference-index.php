<?php
/** Functional checks for bounded read-only video/post reference discovery. */
declare(strict_types=1);

namespace {
    $GLOBALS['awvp_reference_posts'] = array(
        10 => (object) array('ID'=>10,'post_type'=>'post','post_status'=>'publish','post_title'=>'AWVP block post','post_content'=>'AWVP_BLOCK'),
        11 => (object) array('ID'=>11,'post_type'=>'post','post_status'=>'publish','post_title'=>'Core video post','post_content'=>'CORE_VIDEO'),
        12 => (object) array('ID'=>12,'post_type'=>'page','post_status'=>'draft','post_title'=>'Shortcode post','post_content'=>'[video id="200"]'),
        13 => (object) array('ID'=>13,'post_type'=>'post','post_status'=>'publish','post_title'=>'Classic video post','post_content'=>'<video controls src="https://example.test/uploads/video.mp4"></video>'),
        14 => (object) array('ID'=>14,'post_type'=>'post','post_status'=>'publish','post_title'=>'Stored origin','post_content'=>''),
        15 => (object) array('ID'=>15,'post_type'=>'revision','post_status'=>'inherit','post_title'=>'Revision','post_content'=>'AWVP_BLOCK'),
    );

    function get_posts(array $args): array
    {
        $GLOBALS['awvp_reference_query_args'] = $args;
        return array_keys($GLOBALS['awvp_reference_posts']);
    }

    function get_post_types(array $args = array(), string $output = 'names'): array
    {
        unset($args, $output);
        return array('post','page','attachment','revision','argent_video','wp_block','shop_order');
    }

    function post_type_supports(string $post_type, string $feature): bool
    {
        return 'editor' === $feature && in_array($post_type, array('post','page','shop_order'), true);
    }

    function get_post(int $post_id): ?object
    {
        return $GLOBALS['awvp_reference_posts'][$post_id] ?? null;
    }

    function parse_blocks(string $content): array
    {
        return match ($content) {
            'AWVP_BLOCK' => array(array('blockName'=>'argentwolf-video-processor/video','attrs'=>array('videoId'=>100),'innerBlocks'=>array())),
            'CORE_VIDEO' => array(array('blockName'=>'core/video','attrs'=>array('id'=>200),'innerBlocks'=>array())),
            default => array(),
        };
    }

    function attachment_url_to_postid(string $url): int
    {
        return 'https://example.test/uploads/video.mp4' === $url ? 200 : 0;
    }

    function sanitize_text_field(string $value): string
    {
        return trim(strip_tags($value));
    }

    function sanitize_key(mixed $value): string
    {
        return preg_replace('/[^a-z0-9_\-]/', '', strtolower((string) $value)) ?? '';
    }

    function __(string $text, string $domain = ''): string
    {
        unset($domain);
        return $text;
    }
}

namespace ArgentVideo {
    final class Video_Post_Type { public const POST_TYPE = 'argent_video'; }
    final class Video_Block { public const NAME = 'argentwolf-video-processor/video'; }
    final class Video_Meta
    {
        public static function sanitize_positive_id(mixed $value): int
        {
            if (is_int($value)) return $value > 0 ? $value : 0;
            return is_string($value) && 1 === preg_match('/^[1-9][0-9]*$/D', $value) ? (int) $value : 0;
        }
    }
}

namespace {
    require_once dirname(__DIR__) . '/includes/Video_Reference_Index.php';

    $failures = array();
    $assert = static function (bool $condition, string $message) use (&$failures): void {
        if (! $condition) $failures[] = $message;
    };

    $index = new \ArgentVideo\Video_Reference_Index();
    $rows = $index->posts_for(100, 200, 14);
    $ids = array_map(static fn(array $row): int => (int) $row['id'], $rows);

    $assert(array(14,10,11,12,13) === $ids, 'Reference index did not merge origin/AWVP/core/shortcode/classic references deterministically.');
    $assert(true === ($rows[0]['is_origin'] ?? false), 'Stored origin post was not marked as origin.');
    $assert('draft' === ($rows[3]['status'] ?? ''), 'Reference post status was not preserved.');
    $assert(false === $index->truncated(), 'Small reference fixture was unexpectedly marked truncated.');
    $scan_types = $GLOBALS['awvp_reference_query_args']['post_type'] ?? array();
    $assert(is_array($scan_types) && ! in_array('attachment', $scan_types, true) && ! in_array('revision', $scan_types, true) && ! in_array('argent_video', $scan_types, true), 'Reference scan spent its safety limit on attachment/revision/AWVP post types.');
    $assert(in_array('wp_block', $scan_types, true), 'Reference scan excluded reusable block content that can retain a direct attachment reference.');

    $attachment_rows = $index->attachment_posts_for(200);
    $attachment_ids = array_map(static fn(array $row): int => (int) $row['id'], $attachment_rows);
    $assert(array(11,12,13) === $attachment_ids, 'Attachment-only reference discovery did not return core/shortcode/classic references.');
    $assert(! in_array(10, $attachment_ids, true), 'AWVP Video block was incorrectly treated as a direct attachment reference.');

    $source = (string) file_get_contents(dirname(__DIR__) . '/includes/Video_Reference_Index.php');
    foreach (array('update_post_meta','delete_post_meta','wp_update_post','wp_delete_post','wp_remote_') as $forbidden) {
        $assert(! str_contains($source, $forbidden), 'Reference index acquired write/network authority: ' . $forbidden);
    }

    if ([] !== $failures) {
        foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
        exit(1);
    }
    fwrite(STDOUT, "Video reference index tests passed.\n");
}
