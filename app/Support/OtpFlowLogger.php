<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Plain-text OTP flow logging for local debugging.
 * Enable with OTP_FLOW_DEBUG=true in .env — never log full tokens or OTP codes.
 */
final class OtpFlowLogger
{
    public static function enabled(): bool
    {
        return (bool) config('security.otp_flow_debug', false);
    }

    public static function tokenFingerprint(string $token): string
    {
        return substr(hash('sha256', str_replace(' ', '+', trim($token))), 0, 12);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    public static function log(string $flow, string $step, array $details = []): void
    {
        if (! self::enabled()) {
            return;
        }

        $parts = ["[OTP FLOW] {$flow} | {$step}"];

        foreach ($details as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (is_bool($value)) {
                $value = $value ? 'yes' : 'no';
            } elseif (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_SLASHES);
            } else {
                $value = (string) $value;
            }

            $parts[] = "{$key}={$value}";
        }

        Log::info(implode(' | ', $parts));
    }

    public static function tokenMeta(string $rawToken): array
    {
        $trimmed = trim($rawToken);

        return [
            'token_fp' => self::tokenFingerprint($rawToken),
            'token_len' => strlen($trimmed),
            'token_has_spaces' => str_contains($trimmed, ' ') ? 'yes' : 'no',
        ];
    }

    public static function otpMeta(string $otp): array
    {
        return [
            'otp_len' => strlen(trim($otp)),
            'otp_is_numeric' => ctype_digit(trim($otp)) ? 'yes' : 'no',
        ];
    }
}
