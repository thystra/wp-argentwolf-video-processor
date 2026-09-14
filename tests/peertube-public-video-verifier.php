<?php
/** Focused tests for RC13.10 public PeerTube verification and alias convergence. */
declare(strict_types=1);

function sanitize_text_field(mixed $value): string
{
    return trim(preg_replace('/[\r\n\t ]+/', ' ', strip_tags((string) $value)) ?? '');
}
function wp_parse_url(string $url): array|false { return parse_url($url); }

require_once dirname(__DIR__) . '/includes/PeerTube_Origin.php';
require_once dirname(__DIR__) . '/includes/Video_Embed_Identity.php';
require_once dirname(__DIR__) . '/includes/PeerTube_Public_Video_Verifier.php';

use ArgentVideo\PeerTube_Public_Video_Verifier;
use ArgentVideo\Video_Embed_Identity;

$assert = static function (bool $ok, string $message): void {
    if (! $ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
};

$uuid = '1d7d52d3-6790-4da0-bc47-063298a7512a';
$short = '4DcZjJeFLKUg9wibvspbbE';
$recognized = Video_Embed_Identity::create(Video_Embed_Identity::PEERTUBE, 'https://video.example.com', $short);
$factory = static fn (string $origin): object => new class($origin, $uuid) {
    public function __construct(private string $origin, private string $uuid) {}
    public function get_public_video(string $candidate): array
    {
        return array(
            'ok' => true,
            'http_status' => 200,
            'headers' => array(),
            'body' => json_encode(array(
                'uuid' => $this->uuid,
                'shortUUID' => '4DcZjJeFLKUg9wibvspbbE',
                'id' => 6,
                'name' => 'Public test video',
                'privacy' => array('id' => 1, 'label' => 'Public'),
            ), JSON_UNESCAPED_SLASHES),
            'error' => null,
        );
    }
};
$verifier = new PeerTube_Public_Video_Verifier($factory);
$result = $verifier->verify((array) $recognized);
$assert(PeerTube_Public_Video_Verifier::VERIFIED === $result['status'], 'Public PeerTube video was not verified.');
$assert($uuid === ($result['identity']['video_id'] ?? ''), 'PeerTube short alias did not converge to authoritative UUID.');
$assert('https://video.example.com/videos/embed/' . $uuid === ($result['identity']['embed_url'] ?? ''), 'Verified embed URL is not UUID-canonical.');
$assert('Public test video' === $result['title'], 'Verified title was not retained.');

$private = new PeerTube_Public_Video_Verifier(static fn (string $origin): object => new class($uuid) {
    public function __construct(private string $uuid) {}
    public function get_public_video(string $candidate): array
    {
        return array('ok'=>true,'http_status'=>200,'headers'=>array(),'body'=>json_encode(array('uuid'=>$this->uuid,'name'=>'Private','privacy'=>array('id'=>3))),'error'=>null);
    }
});
$assert(PeerTube_Public_Video_Verifier::REFUSED === $private->verify((array) $recognized)['status'], 'Private PeerTube video was accepted as public external source.');

$missing = new PeerTube_Public_Video_Verifier(static fn (string $origin): object => new class {
    public function get_public_video(string $candidate): array { return array('ok'=>false,'http_status'=>404,'headers'=>array(),'body'=>'','error'=>array()); }
});
$assert(PeerTube_Public_Video_Verifier::REFUSED === $missing->verify((array) $recognized)['status'], 'Missing PeerTube video did not fail closed.');

$broken = new PeerTube_Public_Video_Verifier(static fn (string $origin): object => new class {
    public function get_public_video(string $candidate): array { return array('ok'=>true,'http_status'=>200,'headers'=>array(),'body'=>'{broken','error'=>null); }
});
$assert(PeerTube_Public_Video_Verifier::INDETERMINATE === $broken->verify((array) $recognized)['status'], 'Malformed PeerTube API response was not indeterminate.');

echo "PASS: PeerTube public verification converges aliases to authoritative public UUID identity.\n";
