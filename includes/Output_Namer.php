<?php
/**
 * File: includes/Output_Namer.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Output_Namer
{
    public static function derivative(string $directory, string $label, string $extension): string
    {
        $safe_label = preg_replace('/[^a-z0-9-]+/i', '-', $label) ?: 'processed';
        $safe_extension = preg_replace('/[^a-z0-9]+/i', '', $extension) ?: 'bin';

        return rtrim(wp_normalize_path($directory), '/')
            . '/video-'
            . strtolower($safe_label)
            . '.'
            . strtolower($safe_extension);
    }

    public static function adaptive_directory(string $directory): string
    {
        return rtrim(wp_normalize_path($directory), '/') . '/hls';
    }

    public static function temporary(string $final_path): string
    {
        $directory = dirname($final_path);
        $filename = pathinfo($final_path, PATHINFO_FILENAME);
        $extension = pathinfo($final_path, PATHINFO_EXTENSION);
        $token = bin2hex(random_bytes(6));

        return $directory . DIRECTORY_SEPARATOR . $filename . '.tmp-' . $token . '.' . $extension;
    }

    public static function temporary_directory(string $final_directory): string
    {
        return $final_directory . '.tmp-' . bin2hex(random_bytes(6));
    }
}

// EOF: includes/Output_Namer.php
