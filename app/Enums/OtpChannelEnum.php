<?php

namespace App\Enums;

use App\Helpers\OpaqueMessageHelper;

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

    /**
     * Response message for an OTP send. During the cooldown no new code goes out, so tell the user to use
     * the code they already have instead of implying a fresh one is on its way.
     *
     * @param  array<string, mixed>  $payload  OtpService send payload
     */
    public static function sendMessageFor(array $payload): string
    {
        $channel = self::tryFrom((string) ($payload['otp_channel'] ?? '')) ?? self::EMAIL;

        if (($payload['new_code_sent'] ?? true) !== false) {
            return $channel->deliveryMessage();
        }

        $destination = $channel === self::SMS ? 'phone' : 'email';
        $ago = isset($payload['code_sent_seconds_ago'])
            ? ' '.OpaqueMessageHelper::humanizeSecondsRemaining((int) $payload['code_sent_seconds_ago']).' ago'
            : '';
        $validFor = isset($payload['expires_in'])
            ? ' It is still valid for '.OpaqueMessageHelper::humanizeSecondsRemaining((int) $payload['expires_in']).'.'
            : '';

        return "We already sent a verification code to your {$destination}{$ago}. Please use the code from that message.{$validFor}";
    }
}
