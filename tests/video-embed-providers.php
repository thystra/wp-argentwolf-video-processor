<?php
/** Focused dependency-free tests for the RC13.10 provider-neutral embed foundation. */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/Video_Embed_Provider.php';
require_once dirname(__DIR__) . '/includes/Video_Embed_Identity.php';
require_once dirname(__DIR__) . '/includes/YouTube_Embed_Provider.php';
require_once dirname(__DIR__) . '/includes/Vimeo_Embed_Provider.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Embed_Provider.php';
require_once dirname(__DIR__) . '/includes/Video_Embed_Resolver.php';

use ArgentVideo\Video_Embed_Identity;
use ArgentVideo\Video_Embed_Resolver;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
};

$resolver = new Video_Embed_Resolver();

$youtube_forms = array(
    'https://www.youtube.com/watch?v=dQw4w9WgXcQ',
    'https://youtu.be/dQw4w9WgXcQ?t=43',
    'https://www.youtube.com/embed/dQw4w9WgXcQ?autoplay=1',
    'https://www.youtube.com/shorts/dQw4w9WgXcQ',
    'https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ',
);
$youtube = array_map(static fn(string $url): ?array => $resolver->recognize($url), $youtube_forms);
foreach ($youtube as $identity) {
    $assert(is_array($identity), 'Supported YouTube URL was not recognized.');
    $assert(Video_Embed_Identity::YOUTUBE === ($identity['provider'] ?? ''), 'YouTube provider identity mismatch.');
    $assert('dQw4w9WgXcQ' === ($identity['video_id'] ?? ''), 'YouTube video ID mismatch.');
    $assert('https://www.youtube-nocookie.com/embed/dQw4w9WgXcQ' === ($identity['embed_url'] ?? ''), 'YouTube did not use the privacy-enhanced embed origin.');
    $assert(! str_contains(strtolower((string) ($identity['embed_url'] ?? '')), 'autoplay'), 'YouTube canonical embed unexpectedly enables autoplay.');
}
$assert(count(array_unique(array_column($youtube, 'canonical_key'))) === 1, 'Equivalent YouTube URL forms did not deduplicate to one canonical identity.');

$vimeo_forms = array(
    'https://vimeo.com/76979871',
    'https://player.vimeo.com/video/76979871?autoplay=1',
    'https://vimeo.com/channels/staffpicks/76979871',
    'https://vimeo.com/groups/example/videos/76979871',
);
$vimeo = array_map(static fn(string $url): ?array => $resolver->recognize($url), $vimeo_forms);
foreach ($vimeo as $identity) {
    $assert(is_array($identity), 'Supported Vimeo URL was not recognized.');
    $assert(Video_Embed_Identity::VIMEO === ($identity['provider'] ?? ''), 'Vimeo provider identity mismatch.');
    $assert('76979871' === ($identity['video_id'] ?? ''), 'Vimeo video ID mismatch.');
    $assert('https://player.vimeo.com/video/76979871' === ($identity['embed_url'] ?? ''), 'Vimeo canonical embed URL mismatch.');
    $assert(! str_contains(strtolower((string) ($identity['embed_url'] ?? '')), 'autoplay'), 'Vimeo canonical embed unexpectedly enables autoplay.');
}
$assert(count(array_unique(array_column($vimeo, 'canonical_key'))) === 1, 'Equivalent Vimeo URL forms did not deduplicate to one canonical identity.');

$peertube_id = '4DcZjJeFLKUg9wibvspbbE';
$peertube_forms = array(
    'https://video.example.org/videos/watch/' . $peertube_id,
    'https://video.example.org/videos/embed/' . $peertube_id . '?autoplay=1',
    'https://video.example.org/w/' . $peertube_id,
);
$peertube = array_map(static fn(string $url): ?array => $resolver->recognize($url), $peertube_forms);
foreach ($peertube as $identity) {
    $assert(is_array($identity), 'Supported PeerTube URL was not recognized.');
    $assert(Video_Embed_Identity::PEERTUBE === ($identity['provider'] ?? ''), 'PeerTube provider identity mismatch.');
    $assert('https://video.example.org' === ($identity['provider_origin'] ?? ''), 'PeerTube instance origin mismatch.');
    $assert($peertube_id === ($identity['video_id'] ?? ''), 'PeerTube video ID mismatch.');
    $assert('https://video.example.org/videos/embed/' . $peertube_id === ($identity['embed_url'] ?? ''), 'PeerTube canonical embed URL mismatch.');
    $assert(! str_contains(strtolower((string) ($identity['embed_url'] ?? '')), 'autoplay'), 'PeerTube canonical embed unexpectedly enables autoplay.');
}
$assert(count(array_unique(array_column($peertube, 'canonical_key'))) === 1, 'Equivalent PeerTube URL forms did not deduplicate to one canonical identity.');

$uuid_identity = $resolver->recognize('https://tube.example.net/videos/watch/1D7D52D3-6790-4DA0-BC47-063298A7512A');
$assert(is_array($uuid_identity), 'PeerTube UUID URL was not recognized.');
$assert('1d7d52d3-6790-4da0-bc47-063298a7512a' === ($uuid_identity['video_id'] ?? ''), 'PeerTube UUID was not canonicalized to lowercase.');
$numeric_identity = $resolver->recognize('https://tube.example.net/videos/embed/42');
$assert(is_array($numeric_identity) && '42' === ($numeric_identity['video_id'] ?? ''), 'PeerTube numeric video ID was not recognized.');

$assert(null === $resolver->recognize('https://www.youtube.com/watch?v=too-short'), 'Malformed YouTube ID was accepted.');
$assert(null === $resolver->recognize('https://www.youtube.com:4443/watch?v=dQw4w9WgXcQ'), 'Non-standard YouTube port was accepted.');
$assert(null === $resolver->recognize('https://vimeo.com/not-a-video'), 'Malformed Vimeo URL was accepted.');
$assert(null === $resolver->recognize('https://vimeo.com:4443/76979871'), 'Non-standard Vimeo port was accepted.');
$assert(null === $resolver->recognize('https://player.vimeo.com/video/76979871?h=secret'), 'Vimeo unlisted-link token was accepted as a public embed identity.');
$assert(null === $resolver->recognize('https://localhost/videos/watch/' . $peertube_id), 'Localhost PeerTube origin was accepted.');
$assert(null === $resolver->recognize('http://video.example.org/videos/watch/' . $peertube_id), 'Public plaintext PeerTube origin was accepted.');
$assert(null === $resolver->recognize('https://user:pass@video.example.org/videos/watch/' . $peertube_id), 'Credential-bearing PeerTube URL was accepted.');
$assert(null === $resolver->recognize('https://example.org/video/' . $peertube_id), 'Unsupported provider URL was accepted.');

$record = Video_Embed_Identity::sanitize($youtube[0]);
$assert($record === $youtube[0], 'Canonical embed identity did not survive storage sanitization.');
$mutated = $youtube[0];
$mutated['embed_url'] = 'https://evil.example/embed';
$assert(Video_Embed_Identity::sanitize($mutated) === $youtube[0], 'Stored derived URLs were trusted instead of being rebuilt from canonical identity.');
$future = $youtube[0];
$future['version'] = 99;
$assert(array() === Video_Embed_Identity::sanitize($future), 'Future embed identity version was accepted.');

echo "PASS: provider-neutral external embed recognition canonicalizes PeerTube, YouTube, and Vimeo without autoplay.\n";
