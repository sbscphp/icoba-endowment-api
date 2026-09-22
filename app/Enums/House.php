<?php

namespace App\Enums;

/**
 * Igbobi College houses. Stored as lowercase slugs; use label() for display.
 */
enum House: string
{
    case PARKER = 'parker';
    case TOWNSEND = 'townsend';
    case OLUWOLE = 'oluwole';
    case AGGREY = 'aggrey';
    case FREEMAN = 'freeman';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::PARKER => 'Parker',
            self::TOWNSEND => 'Townsend',
            self::OLUWOLE => 'Oluwole',
            self::AGGREY => 'Aggrey',
            self::FREEMAN => 'Freeman',
        };
    }

    /**
     * Normalize free-form input (e.g. "Parker") to a slug, or null when unknown.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $slug = strtolower(trim($value));

        return self::tryFrom($slug)?->value;
    }

    /**
     * Dropdown-friendly list of {value, label}.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $house) => ['value' => $house->value, 'label' => $house->label()],
            self::cases(),
        );
    }

    /**
     * Display payload for API resources.
     *
     * @return array{value: string, label: string}|null
     */
    public static function payload(?string $value): ?array
    {
        $house = $value !== null ? self::tryFrom($value) : null;

        return $house !== null ? ['value' => $house->value, 'label' => $house->label()] : null;
    }
}
