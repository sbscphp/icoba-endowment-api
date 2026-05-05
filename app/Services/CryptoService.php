<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * AES-256-CBC encryption / decryption service.
 *
 * All failures throw \RuntimeException directly — no custom exception
 * wrapper needed since the only meaningful response is "crypto failed".
 */
final class CryptoService
{
    private const CIPHER = 'AES-256-CBC';

    private const KEY_BYTES = 32;   // 256 bits

    private const IV_BYTES = 16;   // 128 bits

    /**
     * @return array{0: string, 1: string} Base64-encoded key and IV suitable for storage.
     */
    public static function generateKeyMaterial(): array
    {
        return [
            base64_encode(random_bytes(self::KEY_BYTES)),
            base64_encode(random_bytes(self::IV_BYTES)),
        ];
    }

    /**
     * @throws RuntimeException
     */
    public static function encryptAes(string $plaintext, string $base64Key, string $base64Iv): string
    {
        [$key, $iv] = self::decodeAndValidate($base64Key, $base64Iv);

        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            throw new RuntimeException('AES encryption failed: '.(string) openssl_error_string());
        }

        return base64_encode($ciphertext);
    }

    /**
     * @throws RuntimeException
     */
    public static function decryptAes(string $base64Ciphertext, string $base64Key, string $base64Iv): string
    {
        [$key, $iv] = self::decodeAndValidate($base64Key, $base64Iv);

        $ciphertext = base64_decode($base64Ciphertext, strict: true);

        if ($ciphertext === false) {
            throw new RuntimeException('AES decryption failed: invalid base64 ciphertext.');
        }

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv);

        if ($plaintext === false) {
            throw new RuntimeException('AES decryption failed: '.(string) openssl_error_string());
        }

        return $plaintext;
    }

    /**
     * Decode base64 key + IV and validate byte lengths.
     *
     * @return array{0: string, 1: string} [raw key bytes, raw IV bytes]
     *
     * @throws RuntimeException
     */
    private static function decodeAndValidate(string $base64Key, string $base64Iv): array
    {
        $key = base64_decode($base64Key, strict: true);
        $iv = base64_decode($base64Iv, strict: true);

        if ($key === false) {
            throw new RuntimeException('Encryption key is not valid base64.');
        }
        if ($iv === false) {
            throw new RuntimeException('Encryption IV is not valid base64.');
        }
        if (strlen($key) !== self::KEY_BYTES) {
            throw new RuntimeException(
                sprintf('Key must be %d bytes; got %d.', self::KEY_BYTES, strlen($key))
            );
        }
        if (strlen($iv) !== self::IV_BYTES) {
            throw new RuntimeException(
                sprintf('IV must be %d bytes; got %d.', self::IV_BYTES, strlen($iv))
            );
        }

        return [$key, $iv];
    }
}
