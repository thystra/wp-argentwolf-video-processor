<?php
declare(strict_types=1);

require __DIR__ . '/lib.php';

awvp_rc_assert_candidate_common();

$fixture = awvp_rc_create_new_awvp_block_fixture();
$attachment_id = $fixture['attachment_id'];
$origin_post_id = $fixture['origin_post_id'];
$video_id = $fixture['video_id'];

$post = get_post($origin_post_id);
awvp_release_assert(is_object($post), 'New AWVP block origin post is missing.');
$stored = (string) $post->post_content;
$expected = sprintf(
    '<!-- wp:argentwolf-video-processor/video {"videoId":%d} /-->',
    $video_id
);
awvp_release_assert($expected === $stored, 'Stored AWVP block markup differs from the explicit fixture.');

$parsed = parse_blocks($stored);
awvp_release_assert(1 === count($parsed), 'AWVP block fixture does not parse as exactly one block.');
awvp_release_assert(
    \ArgentVideo\Video_Block::NAME === ($parsed[0]['blockName'] ?? ''),
    'AWVP block fixture has the wrong block name.'
);
awvp_release_assert(
    $video_id === (int) ($parsed[0]['attrs']['videoId'] ?? 0),
    'AWVP block fixture lost its stable videoId.'
);

awvp_release_assert(
    $attachment_id === \ArgentVideo\Video_Meta::sanitize_positive_id(
        get_post_meta($video_id, \ArgentVideo\Video_Meta::ATTACHMENT_ID, true)
    ),
    'AWVP Video lost its attachment binding.'
);
awvp_release_assert(
    $origin_post_id === \ArgentVideo\Video_Meta::sanitize_positive_id(
        get_post_meta($video_id, \ArgentVideo\Video_Meta::ORIGIN_POST_ID, true)
    ),
    'AWVP Video lost its origin-post binding.'
);
$destination = get_post_meta($video_id, \ArgentVideo\Video_Meta::DESTINATION, true);
awvp_release_assert(
    is_array($destination) && 'local' === (string) ($destination['backend_id'] ?? ''),
    'New AWVP block fixture did not resolve to the local backend by default.'
);

$attachment_url = wp_get_attachment_url($attachment_id);
awvp_release_assert(is_string($attachment_url) && '' !== $attachment_url, 'AWVP block attachment URL is missing.');
$rendered = do_blocks($stored);
awvp_release_assert(
    str_contains($rendered, 'data-awvp-video-id="' . $video_id . '"'),
    'AWVP block render is missing its stable video identity.'
);
awvp_release_assert(
    str_contains($rendered, esc_url($attachment_url)),
    'AWVP block did not render the local WordPress video source.'
);
$post_after = get_post($origin_post_id);
awvp_release_assert(
    is_object($post_after) && $stored === (string) $post_after->post_content,
    'Rendering the AWVP block mutated stored block markup.'
);

echo "AWVP_RC_NEW_AWVP_BLOCK_COMPATIBILITY_PASS video_id={$video_id}\n";
