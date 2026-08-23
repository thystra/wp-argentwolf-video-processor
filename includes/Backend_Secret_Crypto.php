<?php
/**
 * File: includes/Backend_Secret_Crypto.php
 */

declare(strict_types=1);

namespace ArgentVideo;

final class Backend_Secret_Crypto
{
    private const VERSION = 1;
    private const CONTEXT = 'argentwolf-video-processor/backend-secrets/v1';

    public static function available(): bool
    {
        return null !== self::provider() && null !== self::derive_key();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public static function encrypt(array $payload, string $aad): array
    {
        $provider = self::provider();
        $key = self::derive_key();

        if (null === $provider || null === $key) {
            throw new \RuntimeException('AWVP managed backend secret encryption is unavailable.');
        }

        $plaintext = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if ('xchacha20poly1305-ietf' === $provider) {
            $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $aad,
                $nonce,
                $key
            );

            return array(
                'version'    => self::VERSION,
                'cipher'     => $provider,
                'nonce'      => base64_encode($nonce),
                'ciphertext' => base64_encode($ciphertext),
            );
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            16
        );

        if (! is_string($ciphertext) || 16 !== strlen($tag)) {
            throw new \RuntimeException('AWVP managed backend secret encryption failed.');
        }

        return array(
            'version'    => self::VERSION,
            'cipher'     => 'aes-256-gcm',
            'nonce'      => base64_encode($nonce),
            'tag'        => base64_encode($tag),
            'ciphertext' => base64_encode($ciphertext),
        );
    }

    /**
     * @param array<string, mixed> $envelope
     * @return array<string, mixed>|null
     */
    public static function decrypt(array $envelope, string $aad): ?array
    {
        if (self::VERSION !== ($envelope['version'] ?? null)) {
            return null;
        }

        $key = self::derive_key();
        if (null === $key) {
            return null;
        }

        $cipher = $envelope['cipher'] ?? null;
        $nonce = self::base64_field($envelope['nonce'] ?? null);
        $ciphertext = self::base64_field($envelope['ciphertext'] ?? null);

        if (! is_string($cipher) || null === $nonce || null === $ciphertext) {
            return null;
        }

        try {
            if ('xchacha20poly1305-ietf' === $cipher) {
                if (
                    ! function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
                    || ! defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES')
                    || SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES !== strlen($nonce)
                ) {
                    return null;
                }

                $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                    $ciphertext,
                    $aad,
                    $nonce,
                    $key
                );

                return is_string($plaintext) ? self::decode_payload($plaintext) : null;
            }

            if ('aes-256-gcm' === $cipher) {
                $tag = self::base64_field($envelope['tag'] ?? null);
                if (
                    null === $tag
                    || 12 !== strlen($nonce)
                    || 16 !== strlen($tag)
                    || ! function_exists('openssl_decrypt')
                ) {
                    return null;
                }

                $plaintext = openssl_decrypt(
                    $ciphertext,
                    'aes-256-gcm',
                    $key,
                    OPENSSL_RAW_DATA,
                    $nonce,
                    $tag,
                    $aad
                );

                return is_string($plaintext) ? self::decode_payload($plaintext) : null;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    private static function provider(): ?string
    {
        if (
            function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt')
            && function_exists('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt')
            && defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES')
            && defined('SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES')
        ) {
            return 'xchacha20poly1305-ietf';
        }

        if (
            function_exists('openssl_encrypt')
            && function_exists('openssl_decrypt')
            && function_exists('openssl_get_cipher_methods')
            && in_array('aes-256-gcm', openssl_get_cipher_methods(), true)
        ) {
            return 'aes-256-gcm';
        }

        return null;
    }

    private static function derive_key(): ?string
    {
        $names = array(
            'AUTH_KEY',
            'SECURE_AUTH_KEY',
            'LOGGED_IN_KEY',
            'NONCE_KEY',
            'AUTH_SALT',
            'SECURE_AUTH_SALT',
            'LOGGED_IN_SALT',
            'NONCE_SALT',
        );

        $parts = array();
        foreach ($names as $name) {
            if (! defined($name)) {
                continue;
            }

            $value = constant($name);
            if (
                ! is_string($value)
                || '' === $value
                || str_contains($value, 'put your unique phrase here')
            ) {
                continue;
            }

            $parts[] = $name . '=' . $value;
        }

        if ([] === $parts) {
            return null;
        }

        return hash_hkdf('sha256', implode("\n", $parts), 32, self::CONTEXT);
    }

    private static function base64_field(mixed $value): ?string
    {
        if (! is_string($value) || '' === $value) {
            return null;
        }

        $decoded = base64_decode($value, true);
        return is_string($decoded) ? $decoded : null;
    }

    /** @return array<string, mixed>|null */
    private static function decode_payload(string $plaintext): ?array
    {
        try {
            $decoded = json_decode($plaintext, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}

// EOF: includes/Backend_Secret_Crypto.php
