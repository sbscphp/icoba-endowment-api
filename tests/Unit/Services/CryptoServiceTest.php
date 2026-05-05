<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CryptoService;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CryptoServiceTest extends TestCase
{
    // 32 raw bytes (256-bit key) and 16 raw bytes (128-bit IV), base64-encoded
    private const KEY = 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA='; // 32 zero bytes

    private const IV = 'AAAAAAAAAAAAAAAAAAAAAA==';                      // 16 zero bytes

    public function test_encrypt_then_decrypt_roundtrip(): void
    {
        $original = 'Hello, ScholarEnabler!';
        $encrypted = CryptoService::encryptAes($original, self::KEY, self::IV);
        $decrypted = CryptoService::decryptAes($encrypted, self::KEY, self::IV);

        $this->assertSame($original, $decrypted);
    }

    public function test_encrypt_produces_base64_output(): void
    {
        $encrypted = CryptoService::encryptAes('test', self::KEY, self::IV);

        $this->assertNotFalse(base64_decode($encrypted, strict: true));
    }

    public function test_encrypt_produces_different_output_for_different_plaintext(): void
    {
        $a = CryptoService::encryptAes('hello', self::KEY, self::IV);
        $b = CryptoService::encryptAes('world', self::KEY, self::IV);

        $this->assertNotSame($a, $b);
    }

    public function test_decrypt_throws_on_invalid_ciphertext(): void
    {
        $this->expectException(RuntimeException::class);

        CryptoService::decryptAes('not-valid-base64!!!', self::KEY, self::IV);
    }

    public function test_encrypt_throws_on_short_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Key must be 32 bytes/');

        CryptoService::encryptAes('test', base64_encode('short'), self::IV);
    }

    public function test_encrypt_throws_on_short_iv(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/IV must be 16 bytes/');

        CryptoService::encryptAes('test', self::KEY, base64_encode('short'));
    }

    public function test_encrypt_throws_on_invalid_base64_key(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/not valid base64/');

        CryptoService::encryptAes('test', '!!!not-base64!!!', self::IV);
    }

    public function test_encrypt_and_decrypt_handles_empty_string(): void
    {
        $encrypted = CryptoService::encryptAes('', self::KEY, self::IV);
        $decrypted = CryptoService::decryptAes($encrypted, self::KEY, self::IV);

        $this->assertSame('', $decrypted);
    }

    public function test_encrypt_and_decrypt_handles_unicode(): void
    {
        $original = '{"name":"Àdébáyọ̀","amount":1000,"currency":"₦"}';
        $encrypted = CryptoService::encryptAes($original, self::KEY, self::IV);
        $decrypted = CryptoService::decryptAes($encrypted, self::KEY, self::IV);

        $this->assertSame($original, $decrypted);
    }
}
