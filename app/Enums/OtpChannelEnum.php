<?php

namespace App\Enums;

enum OtpChannelEnum: string
{
    case EMAIL = 'email';
    case SMS = 'sms';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function tryFromRequest(mixed $value, ?self $default = self::EMAIL): ?self
    {
        if (! is_string($value) || $value === '') {
            return $default;
        }

        return self::tryFrom(strtolower(trim($value))) ?? $default;
    }

    public function deliveryMessage(): string
    {
        return match ($this) {
            self::SMS => 'A verification code has been sent to your phone.',
            self::EMAIL => 'A verification code has been sent to your email.',
        };
    }
}
