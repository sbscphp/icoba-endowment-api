<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
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

    public static function requestHasOverrideUser(Request $request): bool
    {
        if (! self::enabled()) {
            return false;
        }

        return self::isOverrideUser(self::resolveUserFromRequest($request));
    }
}
