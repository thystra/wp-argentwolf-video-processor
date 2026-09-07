<?php
/** Dependency-free confinement/identity regression for PeerTube publication thumbnails. */
declare(strict_types=1);

namespace {
    $root = sys_get_temp_dir() . '/awvp-publication-thumbnail-' . bin2hex(random_bytes(6));
    $uploads = $root . '/uploads';
    $outside_dir = $root . '/outside';
    mkdir($uploads . '/2026/09', 0700, true);
    mkdir($outside_dir, 0700, true);

    $GLOBALS['awvp_thumb_uploads'] = $uploads;
    $GLOBALS['awvp_thumb_files'] = array();
    $GLOBALS['awvp_thumb_mimes'] = array();
    $GLOBALS['awvp_thumb_images'] = array();

    function wp_upload_dir(): array
    {
        return array('basedir'=>$GLOBALS['awvp_thumb_uploads'],'baseurl'=>'https://example.test/uploads','error'=>false);
    }
    function wp_normalize_path(string $path): string { return str_replace('\\', '/', $path); }
    function wp_attachment_is_image(int $id): bool { return true === ($GLOBALS['awvp_thumb_images'][$id] ?? false); }
    function get_post_mime_type(int $id): string|false { return $GLOBALS['awvp_thumb_mimes'][$id] ?? false; }
    function get_attached_file(int $id, bool $unfiltered=false): string|false { unset($unfiltered); return $GLOBALS['awvp_thumb_files'][$id] ?? false; }
}

namespace ArgentVideo {
    require_once dirname(__DIR__) . '/includes/PeerTube_Publication_Thumbnail.php';

    $assert = static function (bool $ok, string $message): void {
        if (! $ok) {
            fwrite(STDERR, "FAIL: {$message}\n");
            exit(1);
        }
    };

    $inside = $GLOBALS['awvp_thumb_uploads'] . '/2026/09/thumb.jpg';
    $outside = dirname($GLOBALS['awvp_thumb_uploads']) . '/outside/thumb.jpg';
    $bytes = "\xff\xd8\xffAWVP-thumbnail-fixture\xff\xd9";
    file_put_contents($inside, $bytes);
    file_put_contents($outside, $bytes);

    $GLOBALS['awvp_thumb_files'][10] = $inside;
    $GLOBALS['awvp_thumb_mimes'][10] = 'image/jpeg';
    $GLOBALS['awvp_thumb_images'][10] = true;

    $identity = PeerTube_Publication_Thumbnail::capture(10);
    $assert(is_array($identity), 'Uploads-confined image attachment was not captured.');
    $assert('2026/09/thumb.jpg' === ($identity['relative_path'] ?? null), 'Thumbnail persisted an unexpected path identity.');
    $assert(! array_key_exists('path', $identity), 'Thumbnail capture leaked an absolute host path.');
    $assert(strlen($bytes) === ($identity['bytes'] ?? null), 'Thumbnail byte identity mismatch.');
    $assert(hash('sha256', $bytes) === ($identity['sha256'] ?? null), 'Thumbnail hash identity mismatch.');
    $assert($bytes === ($identity['content'] ?? null), 'Thumbnail outbound bytes did not come from the verified attachment descriptor.');

    // A WordPress attachment path outside wp_upload_dir()['basedir'] is never outbound PeerTube authority.
    $GLOBALS['awvp_thumb_files'][11] = $outside;
    $GLOBALS['awvp_thumb_mimes'][11] = 'image/jpeg';
    $GLOBALS['awvp_thumb_images'][11] = true;
    $assert(null === PeerTube_Publication_Thumbnail::capture(11), 'Thumbnail outside WordPress uploads was accepted.');

    // Unsupported image formats do not enter the reviewed multipart contract.
    $GLOBALS['awvp_thumb_files'][12] = $inside;
    $GLOBALS['awvp_thumb_mimes'][12] = 'image/gif';
    $GLOBALS['awvp_thumb_images'][12] = true;
    $assert(null === PeerTube_Publication_Thumbnail::capture(12), 'Unsupported thumbnail MIME was accepted.');

    // Symlink traversal beneath uploads is refused even when the resolved target is readable.
    $link = $GLOBALS['awvp_thumb_uploads'] . '/2026/09/link.jpg';
    if (@symlink($outside, $link)) {
        $GLOBALS['awvp_thumb_files'][13] = $link;
        $GLOBALS['awvp_thumb_mimes'][13] = 'image/jpeg';
        $GLOBALS['awvp_thumb_images'][13] = true;
        $assert(null === PeerTube_Publication_Thumbnail::capture(13), 'Symlinked thumbnail escaped the uploads boundary.');
        @unlink($link);
    }

    // A later capture sees replacement bytes; the publication manifest hash check then refuses the change.
    $changed = "\xff\xd8\xffAWVP-thumbnail-changed\xff\xd9";
    file_put_contents($inside, $changed);
    clearstatcache(true, $inside);
    $changed_capture = PeerTube_Publication_Thumbnail::capture(10);
    $assert(is_array($changed_capture), 'Changed thumbnail could not be recaptured.');
    $assert(
        ($identity['sha256'] ?? null) !== ($changed_capture['sha256'] ?? null)
        && $changed === ($changed_capture['content'] ?? null),
        'Changed thumbnail did not produce a distinct exact outbound-byte commitment.'
    );

    foreach (array($inside,$outside) as $path) {
        @unlink($path);
    }
    @rmdir($GLOBALS['awvp_thumb_uploads'] . '/2026/09');
    @rmdir($GLOBALS['awvp_thumb_uploads'] . '/2026');
    @rmdir($GLOBALS['awvp_thumb_uploads']);
    @rmdir(dirname($outside));
    @rmdir(dirname($GLOBALS['awvp_thumb_uploads']));

    fwrite(STDOUT, "PeerTube publication thumbnail confinement tests passed.\n");
}
