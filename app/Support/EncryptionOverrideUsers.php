<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use JsonException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Dev/support accounts that use plaintext API payloads when OVERRIDE_USERS is enabled.
 */
final class EncryptionOverrideUsers
{
    public static function enabled(): bool
    {
        return (bool) config('security.override_users.enabled', false);
    }

    public static function isOverrideEmail(?string $email): bool
    {
        if ($email === null || $email === '') {
            return false;
        }

        $normalised = strtolower($email);

        /** @var list<string> $allowed */
        $allowed = array_map(
            static fn (string $value): string => strtolower($value),
            array_values(config('security.override_users.emails', [])),
        );

        return in_array($normalised, $allowed, true);
    }

    public static function isOverrideUser(mixed $user): bool
    {
        if (! is_object($user)) {
            return false;
        }

        $email = $user->email ?? null;

        return is_string($email) && self::isOverrideEmail($email);
    }

    /**
     * Resolve the authenticated user before route middleware runs (Bearer token lookup).
     */
    public static function resolveUserFromRequest(Request $request): mixed
    {
        $user = $request->user();

        if ($user !== null) {
            return $user;
        }

        if (! self::enabled()) {
            return null;
        }

        $token = $request->bearerToken();

        if ($token === null || $token === '') {
            return null;
        }

        $accessToken = PersonalAccessToken::findToken($token);

        return $accessToken?->tokenable;
    }

    /**
     * Email supplied on auth routes (login, etc.) before a Bearer token exists.
     */
    public static function emailFromRequest(Request $request): ?string
    {
        $email = $request->input('email');

        if (is_string($email) && $email !== '') {
            return self::normaliseEmail($email);
        }

        $raw = $request->getContent();

        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }

        try {
            $data = json_decode($raw, associative: true, flags: JSON_THROW_ON_ERROR);

            if (is_array($data) && isset($data['email']) && is_string($data['email'])) {
                return self::normaliseEmail($data['email']);
            }
        } catch (JsonException) {
            // Encrypted envelope or non-JSON body (e.g. multipart form).
        }

        return null;
    }

    public static function requestHasOverrideUser(Request $request): bool
    {
        if (! self::enabled()) {
            return false;
        }

        if (self::isOverrideUser(self::resolveUserFromRequest($request))) {
            return true;
        }

        return self::isOverrideEmail(self::emailFromRequest($request));
    }

    private static function normaliseEmail(string $email): string
    {
        return trim($email, " \t\n\r\0\x0B\"'");
    }
}
