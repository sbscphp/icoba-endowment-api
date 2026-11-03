<?php

namespace App\Enums;

enum AdImageOrientation: string
{
    case LANDSCAPE = 'landscape';
    case PORTRAIT = 'portrait';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Request payload key that carries this orientation's image list (e.g. `landscape_images`).
     */
    public function payloadKey(): string
    {
        return $this->value.'_images';
    }

    public static function tryFromRequest(mixed $value): ?self
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return self::tryFrom(strtolower(trim($value)));
    }
}
