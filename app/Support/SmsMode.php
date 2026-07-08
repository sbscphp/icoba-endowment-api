<?php

namespace App\Support;

final class SmsMode
{
    public const STUB = 'stub';

    public const LOG = 'log';

    public const LIVE = 'live';

    private static ?string $override = null;

    public static function current(): string
    {
        if (self::$override !== null) {
            return self::$override;
        }

        $mode = strtolower(trim((string) env('SMS_MODE', self::STUB)));

        if (in_array($mode, [self::STUB, self::LOG, self::LIVE], true)) {
            return $mode;
        }

        return self::STUB;
    }

    public static function setOverride(?string $mode): void
    {
        self::$override = $mode;
    }

    public static function isStub(): bool
    {
        return self::current() === self::STUB;
    }

    public static function invokesSmsService(): bool
    {
        return ! self::isStub();
    }

    public static function sendsViaProvider(): bool
    {
        return self::current() === self::LIVE;
    }

    public static function stubCode(): string
    {
        $stub = (string) config('security.otp_sms_stub_code', '123456');

        return preg_match('/^\d{6}$/', $stub) === 1 ? $stub : '123456';
    }
}
