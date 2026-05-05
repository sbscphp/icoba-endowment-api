<?php

namespace App\Helpers;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Opaque API messages and reset_flow_id mapping for password / OTP flows (customer, admin).
 * Maps a random token (not a user UUID) to the subject UUID in cache until TTL or explicit forget.
 */
final class OpaqueMessageHelper
{
    public const CONTEXT_CUSTOMER = 'customer';

    public const CONTEXT_ADMIN = 'admin';

    /**
     * Public API responses for password / OTP flows: do not reveal whether an identifier exists.
     */
    public const MESSAGE_GENERIC_OTP_DISPATCH_ACK = 'If an account matches what you entered, a verification code will be sent. Please check your email or phone.';

    public const MESSAGE_GENERIC_EMAIL_RESET_LINK_ACK = 'If an account exists for this email, you will receive reset instructions shortly.';

    public const MESSAGE_INVALID_RESET_SESSION = 'Invalid or expired reset session. Please start the process again.';

    /**
     * Registration OTP (customer): do not reveal whether the contact already has an account.
     */
    public const MESSAGE_GENERIC_PLAYER_REGISTRATION_OTP_ACK = 'If you have started registration with this contact, check your phone or email for a verification code. If a code was recently sent, wait until it expires before requesting another.';

    /**
     * Final signup: do not reveal whether email or phone is already registered.
     * The account holder may receive a separate security email with sign-in / forgot-password guidance.
     */
    public const MESSAGE_GENERIC_SIGNUP_UNAVAILABLE = 'We could not complete registration with the details provided. If you already have an account, sign in or use Forgot password. You may also receive an email with more information.';

    /**
     * Login: do not reveal whether the account exists, is locked, deactivated, or blocked.
     * Toggle via config security.login_opaque_errors (env LOGIN_OPAQUE_ERRORS),
     * or globally override with security.auth_opaque_errors (env AUTH_OPAQUE_ERRORS).
     */
    public const MESSAGE_GENERIC_LOGIN_FAILURE = 'Unable to sign in. Check your credentials and try again. If you already have an account, use Forgot password or contact support.';

    /**
     * Login OTP verify when opaque errors are enabled: do not distinguish expired session vs wrong code.
     */
    public const MESSAGE_GENERIC_LOGIN_OTP_VERIFY_FAILURE = 'Unable to verify your sign-in code. Request a new verification code and try again. If you still cannot sign in, use Forgot password or contact support.';

    /** Too soon to issue another OTP; use payload retry_after_* for UX (does not confirm identifier validity). */
    public const MESSAGE_OTP_COOLDOWN_ACTIVE = 'Please wait before requesting another verification code.';

    /**
     * Seconds from now until $expiresAt (0 if already expired).
     */
    public static function secondsUntilExpiry(\DateTimeInterface|string $expiresAt): int
    {
        $ts = Carbon::parse($expiresAt)->getTimestamp();

        return max(0, $ts - time());
    }

    /** e.g. "3 minutes 43 seconds" — for OTP cooldown responses. */
    public static function humanizeSecondsRemaining(int $seconds): string
    {
        $seconds = max(0, $seconds);
        if ($seconds < 60) {
            return $seconds === 1 ? '1 second' : "{$seconds} seconds";
        }

        $minutes = intdiv($seconds, 60);
        $rem = $seconds % 60;
        $mPart = $minutes === 1 ? '1 minute' : "{$minutes} minutes";
        if ($rem === 0) {
            return $mPart;
        }
        $sPart = $rem === 1 ? '1 second' : "{$rem} seconds";

        return "{$mPart} {$sPart}";
    }

    /**
     * Global-first resolver for auth opacity.
     */
    public static function authOpaqueEnabled(?string $flow = null): bool
    {
        $global = config('security.auth_opaque_errors');
        if (is_bool($global)) {
            return $global;
        }

        return match ($flow) {
            'login' => (bool) config('security.login_opaque_errors', true),
            'forgot_password' => (bool) config('security.forgot_password_opaque_errors', true),
            default => true,
        };
    }

    /**
     * Random token when no cache entry must exist — same length/entropy as a real reset_flow_id.
     */
    public static function decoyResetFlowId(): string
    {
        return Str::random(64);
    }

    /**
     * Mask strictly from user-supplied email or phone (normalized) so OTP-send responses stay uniform.
     */
    public static function maskedIdentifierFromUserInput(string $normalizedValue, bool $isEmail): string
    {
        if ($isEmail) {
            return GeneralHelper::maskEmail($normalizedValue);
        }

        return GeneralHelper::maskPhone($normalizedValue);
    }

    /**
     * Invite-link flows have no email in the body; we still return a masked "email-shaped" hint so the JSON
     * matches username-based sends. Domain is chosen from common public hosts (not a reserved/synthetic TLD)
     * so responses do not scream "placeholder" — it is not the user's real address, only display noise.
     */
    private static function syntheticMailboxDomainForInvite(string $namespace, string $inviteUuid): string
    {
        $domains = [
            'gmail.com',
            'yahoo.com',
            'outlook.com',
            'hotmail.com',
            'icloud.com',
            'live.com',
            'proton.me',
        ];
        $idx = hexdec(substr(hash('sha256', $namespace.':'.$inviteUuid), 0, 8)) % count($domains);

        return $domains[$idx];
    }

    /**
     * Admin invite GET send-code/{uuid}: same deterministic hint for that UUID whether or not the admin row exists.
     */
    public static function maskedIdentifierHintFromAdminInviteUuid(string $adminUuid): string
    {
        $hash = hash('sha256', 'admin_invite_fp:'.$adminUuid);
        $local = 'n'.substr($hash, 0, 12);
        $domain = self::syntheticMailboxDomainForInvite('admin_invite_fp', $adminUuid);

        return GeneralHelper::maskEmail($local.'@'.$domain);
    }

    public static function ttlSeconds(): int
    {
        $otpMinutes = max(1, (int) config('security.otp_minutes', env('OTP_EXPIRATION_MINUTES', 5)));

        return max(1800, $otpMinutes * 60 * 3 + 900);
    }

    /**
     * Cache key mapping subject UUID → current reset_flow token (for cooldown responses without re-issuing OTP).
     */
    protected static function subjectToFlowCacheKey(string $context, string $subjectUuid): string
    {
        return 'otp_reset_flow_subject:'.$context.':'.hash('sha256', $subjectUuid);
    }

    /**
     * JSON fields aligned with registration OTP ack (send-otp-phone / send-otp-email).
     *
     * @return array{minutes: int, cooldown_active: bool, retry_after_seconds?: int, expires_at?: string}
     */
    public static function forgotPasswordOtpMeta(bool $cooldownActive, ?int $retryAfterSeconds, mixed $expiresAt = null): array
    {
        $minutes = max(1, (int) config('security.otp_minutes', env('OTP_EXPIRATION_MINUTES', 5)));
        $meta = [
            'minutes' => $minutes,
            'cooldown_active' => $cooldownActive,
        ];
        if ($retryAfterSeconds !== null) {
            $meta['retry_after_seconds'] = $retryAfterSeconds;
        }
        if ($expiresAt !== null) {
            $meta['expires_at'] = Carbon::parse($expiresAt)->toIso8601String();
        }

        return $meta;
    }

    /**
     * Active reset_flow_id for a subject, if cache still has a valid token→subject mapping.
     */
    public static function activeFlowIdForSubject(string $context, string $subjectUuid): ?string
    {
        $plain = Cache::get(self::subjectToFlowCacheKey($context, $subjectUuid));
        if (! is_string($plain) || $plain === '') {
            return null;
        }
        if (self::resolve($context, $plain) !== $subjectUuid) {
            return null;
        }

        return $plain;
    }

    public static function issue(string $context, string $subjectUuid): string
    {
        $plain = Str::random(64);
        $ttl = self::ttlSeconds();
        Cache::put(self::cacheKey($context, $plain), $subjectUuid, $ttl);
        Cache::put(self::subjectToFlowCacheKey($context, $subjectUuid), $plain, $ttl);

        return $plain;
    }

    public static function resolve(string $context, ?string $plainToken): ?string
    {
        if ($plainToken === null || $plainToken === '') {
            return null;
        }

        $uuid = Cache::get(self::cacheKey($context, $plainToken));

        return is_string($uuid) ? $uuid : null;
    }

    public static function extendTtl(string $context, string $plainToken, string $expectedSubjectUuid): bool
    {
        $uuid = self::resolve($context, $plainToken);
        if ($uuid === null || $uuid !== $expectedSubjectUuid) {
            return false;
        }
        $ttl = self::ttlSeconds();
        Cache::put(self::cacheKey($context, $plainToken), $uuid, $ttl);
        Cache::put(self::subjectToFlowCacheKey($context, $expectedSubjectUuid), $plainToken, $ttl);

        return true;
    }

    public static function forget(string $context, string $plainToken): void
    {
        $uuid = Cache::get(self::cacheKey($context, $plainToken));
        Cache::forget(self::cacheKey($context, $plainToken));
        if (is_string($uuid) && $uuid !== '') {
            Cache::forget(self::subjectToFlowCacheKey($context, $uuid));
        }
    }

    protected static function cacheKey(string $context, string $plainToken): string
    {
        return 'otp_reset_flow:'.$context.':'.hash('sha256', $plainToken);
    }
}
