<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Plain-text OTP flow logging for local debugging.
 * Enable with OTP_FLOW_DEBUG=true in .env — never log full tokens, OTP codes, or passwords.
 */
final class OtpFlowLogger
{
    /** @var list<string> */
    private const REDACTED_REQUEST_KEYS = [
        'password',
        'password_confirmation',
        'otp',
        'refresh_token',
    ];

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

    /**
     * @param  list<string>  $redact
     * @return array<string, mixed>
     */
    public static function requestMeta(Request $request, array $redact = []): array
    {
        $meta = [
            'path' => $request->path(),
            'method' => $request->method(),
        ];

        foreach ($request->except(array_merge(self::REDACTED_REQUEST_KEYS, $redact)) as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (in_array($key, ['challenge_token', 'reset_token'], true)) {
                $meta = array_merge($meta, self::tokenMeta((string) $value));

                continue;
            }

            if (is_scalar($value)) {
                $meta[$key] = $value;
            }
        }

        if ($request->filled('otp')) {
            $meta = array_merge($meta, self::otpMeta((string) $request->input('otp')));
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function authPayloadMeta(array $payload): array
    {
        $meta = [];

        foreach (['otp_channel', 'otp_purpose', 'registration_step', 'cooldown_active', 'expires_in', 'retry_after_seconds'] as $key) {
            if (! array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
                continue;
            }

            $meta[$key] = is_bool($payload[$key]) ? ($payload[$key] ? 'yes' : 'no') : $payload[$key];
        }

        if (isset($payload['access_token'])) {
            $meta['has_access_token'] = 'yes';
        }

        if (isset($payload['challenge_token'])) {
            $meta = array_merge($meta, self::tokenMeta((string) $payload['challenge_token']));
        }

        if (isset($payload['reset_token'])) {
            $meta = array_merge($meta, self::tokenMeta((string) $payload['reset_token']));
        }

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function logAndReturn(string $flow, string $step, array $context, JsonResponse $response): JsonResponse
    {
        self::log($flow, $step, array_merge($context, ['status' => $response->getStatusCode()]));

        return $response;
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
