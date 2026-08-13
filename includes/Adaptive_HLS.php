<?php
/**
 * File: includes/Adaptive_HLS.php
 */

declare(strict_types=1);

namespace ArgentVideo;

use RuntimeException;

final class Adaptive_HLS
{
    /**
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $source_probe
     * @return list<array<string, int|string>>
     */
    public static function renditions(array $settings, array $source_probe): array
    {
        [$source_width, $source_height] = Probe::display_dimensions($source_probe);
        if ($source_width < 2 || $source_height < 2) {
            throw new RuntimeException('Could not determine source dimensions for adaptive streaming.');
        }

        $definitions = array(
            array('label' => '360p', 'max_width' => 640, 'max_height' => 360, 'video_kbps' => (int) $settings['hls_360_video_kbps']),
            array('label' => '480p', 'max_width' => 854, 'max_height' => 480, 'video_kbps' => (int) $settings['hls_480_video_kbps']),
            array('label' => '720p', 'max_width' => 1280, 'max_height' => 720, 'video_kbps' => (int) $settings['hls_720_video_kbps']),
        );

        $result = array();
        $seen_dimensions = array();
        foreach ($definitions as $definition) {
            [$width, $height] = self::scaled_dimensions(
                $source_width,
                $source_height,
                (int) $definition['max_width'],
                (int) $definition['max_height']
            );
            $dimension_key = $width . 'x' . $height;
            if (isset($seen_dimensions[$dimension_key])) {
                continue;
            }
            $seen_dimensions[$dimension_key] = true;
            $result[] = array(
                'label'       => (string) $definition['label'],
                'max_width'   => (int) $definition['max_width'],
                'max_height'  => (int) $definition['max_height'],
                'width'       => $width,
                'height'      => $height,
                'video_kbps'  => (int) $definition['video_kbps'],
                'audio_kbps'  => (int) $settings['hls_audio_bitrate_kbps'],
            );
        }

        return $result;
    }

    /** @param list<array<string, int|string>> $renditions */
    public static function write_master(string $path, array $renditions): void
    {
        $lines = array('#EXTM3U', '#EXT-X-VERSION:7', '#EXT-X-INDEPENDENT-SEGMENTS');
        foreach ($renditions as $rendition) {
            $average = ((int) $rendition['video_kbps'] + (int) $rendition['audio_kbps']) * 1000;
            $peak = (int) ceil($average * 1.15);
            $codecs = (int) $rendition['audio_kbps'] > 0 ? 'avc1.640028,mp4a.40.2' : 'avc1.640028';
            $lines[] = sprintf(
                '#EXT-X-STREAM-INF:BANDWIDTH=%d,AVERAGE-BANDWIDTH=%d,RESOLUTION=%dx%d,CODECS="%s"',
                $peak,
                $average,
                (int) $rendition['width'],
                (int) $rendition['height'],
                $codecs
            );
            $lines[] = rawurlencode((string) $rendition['label']) . '/index.m3u8';
        }

        Storage::write_file($path, implode("\n", $lines) . "\n");
    }

    public static function validate_media_playlist(string $playlist): void
    {
        $playlist = Storage::assert_managed_path($playlist);
        $contents = is_file($playlist) ? file_get_contents($playlist) : false;
        if (false === $contents || ! str_contains($contents, '#EXTM3U')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception; escaped at every administrative display boundary.
            throw new RuntimeException('HLS media playlist was not created correctly: ' . $playlist);
        }
        if (! str_contains($contents, '#EXT-X-ENDLIST')) {
            // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Internal worker exception; escaped at every administrative display boundary.
            throw new RuntimeException('HLS media playlist is incomplete: ' . $playlist);
        }
        if (! str_contains($contents, '#EXT-X-MAP')) {
            throw new RuntimeException('HLS media playlist does not reference a fragmented MP4 initialization segment.');
        }
        if (! str_contains($contents, '.m4s')) {
            throw new RuntimeException('HLS media playlist does not contain media segments.');
        }
    }

    public static function directory_size(string $directory): int
    {
        $directory = Storage::assert_managed_path($directory);
        $size = 0;
        if (! is_dir($directory)) {
            return 0;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $size += $file->getSize();
            }
        }
        return $size;
    }

    /** @return array{0:int,1:int} */
    private static function scaled_dimensions(int $source_width, int $source_height, int $max_width, int $max_height): array
    {
        $ratio = min($max_width / $source_width, $max_height / $source_height, 1.0);
        $width = max(2, (int) floor(($source_width * $ratio) / 2) * 2);
        $height = max(2, (int) floor(($source_height * $ratio) / 2) * 2);
        return array($width, $height);
    }
}

// EOF: includes/Adaptive_HLS.php
