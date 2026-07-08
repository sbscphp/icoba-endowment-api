<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class Country extends Model
{
    use HasUuid;

    public const DEFAULT_ISO2 = 'NG';

    protected $guarded = ['id', 'uuid'];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'id',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public static function defaultDialCode(): string
    {
        return static::query()
            ->active()
            ->where('iso2', self::DEFAULT_ISO2)
            ->value('dial_code') ?? '+234';
    }

    public static function dialCodeForUuid(?string $uuid): ?string
    {
        if ($uuid === null || $uuid === '') {
            return null;
        }

        return static::query()
            ->active()
            ->where('uuid', $uuid)
            ->value('dial_code');
    }

    public static function defaultCountry(): self
    {
        return static::query()->active()->where('iso2', self::DEFAULT_ISO2)->firstOrFail();
    }

    /**
     * Resolve the country used for phone parsing during registration.
     * When no country UUID is provided, Nigeria is used.
     */
    public static function forRegistration(?string $countryUuid): self
    {
        if ($countryUuid === null || $countryUuid === '') {
            return static::defaultCountry();
        }

        $country = static::query()->active()->where('uuid', $countryUuid)->first();

        return $country ?? static::defaultCountry();
    }

    public static function findActiveByDialCode(?string $dialCode): ?self
    {
        $normalized = static::normalizeDialCodeString($dialCode);

        if ($normalized === null) {
            return null;
        }

        return static::query()->active()->where('dial_code', $normalized)->first();
    }

    public static function normalizeDialCodeString(?string $dialCode): ?string
    {
        $trimmed = trim((string) $dialCode);
        if ($trimmed === '') {
            return null;
        }

        if (! str_starts_with($trimmed, '+')) {
            $trimmed = '+'.$trimmed;
        }

        return $trimmed;
    }
}
