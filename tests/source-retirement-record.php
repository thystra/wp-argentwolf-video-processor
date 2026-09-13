<?php
/** Focused dependency-free RC13.8 source-retirement provenance tests. */
declare(strict_types=1);

namespace {
    $GLOBALS['awvp_retirement_posts'] = array(
        20 => (object) array('ID'=>20,'post_type'=>'attachment','post_title'=>'Large Video Test'),
    );

    function get_post(int $id): mixed { return $GLOBALS['awvp_retirement_posts'][$id] ?? null; }
    function get_post_mime_type(int $id): string|false { return isset($GLOBALS['awvp_retirement_posts'][$id]) ? 'video/mp4' : false; }
    function get_the_title(int $id): string { return isset($GLOBALS['awvp_retirement_posts'][$id]) ? 'Large Video Test' : ''; }
    function sanitize_text_field(mixed $value): string { return trim(strip_tags((string) $value)); }
    function sanitize_file_name(string $value): string { return preg_replace('/[^A-Za-z0-9._-]/', '-', $value) ?? ''; }
    function sanitize_mime_type(string $value): string { return preg_replace('/[^A-Za-z0-9.+\\/-]/', '', $value) ?? ''; }
    function wp_basename(string $path): string { return basename($path); }
}

namespace ArgentVideo {
    require_once dirname(__DIR__) . '/includes/WordPress_Source_File.php';
    require_once dirname(__DIR__) . '/includes/Source_Retirement_Record.php';

    $failures = array();
    $assert = static function (bool $condition, string $message) use (&$failures): void {
        if (! $condition) $failures[] = $message;
    };

    $identity = array(
        'relative_path'=>'2026/09/20260829_153928.mp4',
        'bytes'=>2723752754,
        'device'=>55,
        'inode'=>279272,
        'mtime'=>1789160723,
        'ctime'=>1789160724,
    );
    $record = Source_Retirement_Record::capture(20, $identity, 60, 1789261559);
    $assert(array() !== $record, 'Valid WordPress video attachment could not produce a source-retirement tombstone.');
    $assert(20 === ($record['former_attachment_id'] ?? 0), 'Tombstone lost former attachment ID.');
    $assert('20260829_153928.mp4' === ($record['filename'] ?? ''), 'Tombstone filename mismatch.');
    $assert('video/mp4' === ($record['mime_type'] ?? ''), 'Tombstone MIME mismatch.');
    $assert(2723752754 === ($record['bytes'] ?? 0), 'Tombstone byte size mismatch.');
    $assert(60 === ($record['retention_task_id'] ?? 0), 'Tombstone retention task mismatch.');
    $assert($record === Source_Retirement_Record::sanitize($record), 'Tombstone does not round-trip through strict sanitizer.');
    $prepared = Source_Retirement_Record::capture(20, $identity, 60, 0);
    $assert(array() !== $prepared && 0 === ($prepared['removed_at'] ?? -1), 'Prepared tombstone cannot preserve provenance before physical retirement is confirmed.');
    $retired = Source_Retirement_Record::with_removed_at($prepared, 1789261559);
    $assert(1789261559 === ($retired['removed_at'] ?? 0), 'Prepared tombstone could not be finalized with the confirmed removal time.');

    $bad = $record;
    $bad['source_identity']['relative_path'] = '../escape.mp4';
    $assert(array() === Source_Retirement_Record::sanitize($bad), 'Traversal source identity survived tombstone sanitization.');
    $bad = $record;
    $bad['bytes']++;
    $assert(array() === Source_Retirement_Record::sanitize($bad), 'Tombstone accepted byte count inconsistent with captured source identity.');
    $assert(array() === Source_Retirement_Record::capture(20, $identity, 0, 1789261559), 'Tombstone accepted missing retention task identity.');

    if ([] !== $failures) {
        foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
        exit(1);
    }
    fwrite(STDOUT, "RC13.8 source-retirement record tests passed.\n");
}
