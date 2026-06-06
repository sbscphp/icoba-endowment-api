<?php

namespace App\Services\GivingIdentity;

final class GivingIdentityNormalizer
{
    public static function email(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = strtolower(trim($email));

        return $normalized !== '' ? $normalized : null;
    }

    public static function text(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = preg_replace('/\s+/u', ' ', trim($value)) ?? '';

        return $normalized !== '' ? $normalized : null;
    }

    public static function compareText(?string $left, ?string $right): bool
    {
        $leftNormalized = self::text($left);
        $rightNormalized = self::text($right);

        if ($leftNormalized === null && $rightNormalized === null) {
            return true;
        }

        if ($leftNormalized === null || $rightNormalized === null) {
            return false;
        }

        return mb_strtolower($leftNormalized) === mb_strtolower($rightNormalized);
    }

    public static function compareUuid(?string $left, ?string $right): bool
    {
        if ($left === null && $right === null) {
            return true;
        }

        if ($left === null || $right === null) {
            return false;
        }

        return $left === $right;
    }
}
