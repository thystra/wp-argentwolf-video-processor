<?php
/**
 * File: includes/Managed_Backend_Secret_Store.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Managed_Backend_Secret_Store implements Backend_Secret_Store
{
    public const OPTION = 'argentwolf_video_processor_backend_secrets';

    private const VERSION = 1;

    public function available(): bool
    {
        return Backend_Secret_Crypto::available()
            && function_exists('get_option')
            && function_exists('add_option')
            && function_exists('update_option')
            && function_exists('delete_option')
            && function_exists('wp_set_option_autoload')
            && function_exists('wp_load_alloptions');
    }

    public function create(string $backend_id, array $secret): string
    {
        $backend_id = Backend_Identity::sanitize($backend_id);
        $secret = self::sanitize_secret($secret);

        if ('' === $backend_id || [] === $secret || ! $this->available()) {
            return '';
        }

        if (! $this->ensure_manifest()) {
            return '';
        }

        for ($attempt = 0; $attempt < 4; $attempt++) {
            $secret_ref = 'managed_' . bin2hex(random_bytes(16));
            $generation = 1;

            try {
                $envelope = Backend_Secret_Crypto::encrypt(
                    $secret,
                    self::aad($secret_ref, $backend_id, $generation)
                );
            } catch (\Throwable) {
                return '';
            }

            $record = array(
                'version'    => self::VERSION,
                'backend_id' => $backend_id,
                'generation' => $generation,
                'envelope'   => $envelope,
            );
            $option = self::record_option($secret_ref);

            if (! add_option($option, $record, '', false)) {
                continue;
            }

            if (! $this->verify_nonautoloaded_value($option, $record)) {
                $this->delete_if_unchanged($option, $record);
                return '';
            }

            return $secret_ref;
        }

        return '';
    }

    public function read(string $secret_ref, string $backend_id): ?array
    {
        if (! $this->manifest_valid() || ! self::valid_ref($secret_ref)) {
            return null;
        }

        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id) {
            return null;
        }

        $record = $this->load_record($secret_ref);
        if (null === $record || $backend_id !== $record['backend_id']) {
            return null;
        }

        $secret = Backend_Secret_Crypto::decrypt(
            $record['envelope'],
            self::aad($secret_ref, $backend_id, $record['generation'])
        );

        if (! is_array($secret)) {
            return null;
        }

        $secret = self::sanitize_secret($secret);
        if ([] === $secret) {
            return null;
        }

        $secret['generation'] = $record['generation'];
        return $secret;
    }

    public function replace(
        string $secret_ref,
        string $backend_id,
        array $secret,
        int $expected_generation
    ): bool {
        if (
            ! $this->manifest_valid()
            || ! self::valid_ref($secret_ref)
            || $expected_generation < 1
        ) {
            return false;
        }

        $backend_id = Backend_Identity::sanitize($backend_id);
        $secret = self::sanitize_secret($secret);
        if ('' === $backend_id || [] === $secret || ! $this->available()) {
            return false;
        }

        $record = $this->load_record($secret_ref);
        if (
            null === $record
            || $backend_id !== $record['backend_id']
            || $record['generation'] !== $expected_generation
        ) {
            return false;
        }

        $generation = $expected_generation + 1;

        try {
            $envelope = Backend_Secret_Crypto::encrypt(
                $secret,
                self::aad($secret_ref, $backend_id, $generation)
            );
        } catch (\Throwable) {
            return false;
        }

        $replacement = array(
            'version'    => self::VERSION,
            'backend_id' => $backend_id,
            'generation' => $generation,
            'envelope'   => $envelope,
        );
        $option = self::record_option($secret_ref);

        update_option($option, $replacement, false);
        wp_set_option_autoload($option, false);

        return $this->verify_nonautoloaded_value($option, $replacement);
    }

    public function delete(string $secret_ref, string $backend_id): bool
    {
        if (! $this->manifest_valid() || ! self::valid_ref($secret_ref)) {
            return false;
        }

        $backend_id = Backend_Identity::sanitize($backend_id);
        if ('' === $backend_id || ! $this->available()) {
            return false;
        }

        $record = $this->load_record($secret_ref);
        if (null === $record || $backend_id !== $record['backend_id']) {
            return false;
        }

        return delete_option(self::record_option($secret_ref));
    }

    private function ensure_manifest(): bool
    {
        $manifest = array('version' => self::VERSION);
        $sentinel = new \stdClass();
        $stored = get_option(self::OPTION, $sentinel);

        if ($sentinel === $stored) {
            if (! add_option(self::OPTION, $manifest, '', false)) {
                $stored = get_option(self::OPTION, $sentinel);
                if ($manifest !== $stored) {
                    return false;
                }
            }
        } elseif ($manifest !== $stored) {
            return false;
        }

        wp_set_option_autoload(self::OPTION, false);

        return $this->verify_nonautoloaded_value(self::OPTION, $manifest);
    }

    private function manifest_valid(): bool
    {
        if (! $this->available()) {
            return false;
        }

        return array('version' => self::VERSION) === get_option(self::OPTION, null)
            && ! array_key_exists(self::OPTION, wp_load_alloptions(true));
    }

    /**
     * @return array{
     *   version:int,
     *   backend_id:string,
     *   generation:int,
     *   envelope:array<string,mixed>
     * }|null
     */
    private function load_record(string $secret_ref): ?array
    {
        $record = get_option(self::record_option($secret_ref), null);
        if (! is_array($record)) {
            return null;
        }

        $expected_keys = array('version', 'backend_id', 'generation', 'envelope');
        $actual_keys = array_keys($record);
        sort($expected_keys);
        sort($actual_keys);

        if ($expected_keys !== $actual_keys || self::VERSION !== ($record['version'] ?? null)) {
            return null;
        }

        $backend_id = Backend_Identity::sanitize($record['backend_id'] ?? null);
        $generation = self::positive_int($record['generation'] ?? null);
        $envelope = $record['envelope'] ?? null;

        if ('' === $backend_id || $generation < 1 || ! is_array($envelope)) {
            return null;
        }

        return array(
            'version'    => self::VERSION,
            'backend_id' => $backend_id,
            'generation' => $generation,
            'envelope'   => $envelope,
        );
    }

    /** @param array<string, mixed> $expected */
    private function verify_nonautoloaded_value(string $option, array $expected): bool
    {
        $autoloaded = wp_load_alloptions(true);
        if (array_key_exists($option, $autoloaded)) {
            return false;
        }

        return $expected === get_option($option, null);
    }

    /** @param array<string, mixed> $attempted */
    private function delete_if_unchanged(string $option, array $attempted): void
    {
        $sentinel = new \stdClass();
        $current = get_option($option, $sentinel);

        if ($current === $attempted) {
            delete_option($option);
        }
    }

    /**
     * @param array<string, mixed> $secret
     * @return array<string, mixed>
     */
    private static function sanitize_secret(array $secret): array
    {
        $allowed = array(
            'access_token',
            'refresh_token',
            'access_expires_at',
            'refresh_expires_at',
        );

        foreach (array_keys($secret) as $key) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                return array();
            }
        }

        $access_token = self::bounded_secret($secret['access_token'] ?? null);
        $refresh_token = self::bounded_secret($secret['refresh_token'] ?? null);
        $access_expires_at = self::positive_int($secret['access_expires_at'] ?? null);
        $refresh_expires_at = self::positive_int($secret['refresh_expires_at'] ?? null);

        if (
            '' === $access_token
            || $access_expires_at < 1
            || ('' !== $refresh_token && $refresh_expires_at < 1)
            || ('' === $refresh_token && $refresh_expires_at > 0)
        ) {
            return array();
        }

        return array(
            'access_token'       => $access_token,
            'refresh_token'      => $refresh_token,
            'access_expires_at'  => $access_expires_at,
            'refresh_expires_at' => $refresh_expires_at,
        );
    }

    private static function bounded_secret(mixed $value): string
    {
        return is_string($value) && '' !== $value && strlen($value) <= 16384
            ? $value
            : '';
    }

    private static function valid_ref(mixed $value): bool
    {
        return is_string($value)
            && strlen($value) <= 191
            && 1 === preg_match('/^managed_[a-f0-9]{32}$/', $value);
    }

    private static function positive_int(mixed $value): int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : 0;
        }

        if (is_string($value) && ctype_digit($value)) {
            $parsed = (int) $value;
            return $parsed > 0 ? $parsed : 0;
        }

        return 0;
    }

    private static function record_option(string $secret_ref): string
    {
        return self::OPTION . '_' . $secret_ref;
    }

    private static function aad(string $secret_ref, string $backend_id, int $generation): string
    {
        return 'awvp-secret|' . $secret_ref . '|' . $backend_id . '|' . $generation;
    }
}

// EOF: includes/Managed_Backend_Secret_Store.php
