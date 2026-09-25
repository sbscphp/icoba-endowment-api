<?php

namespace App\Enums;

/**
 * Wives of ICOBA chapter. Stored as slugs; use label() for display.
 */
enum WivesType: string
{
    case ICOBANA_WIVES = 'icobana_wives';
    case WIVES_OF_ICOBA_EUROPE = 'wives_of_icoba_europe';
    case WIVES_OF_ICOBA_INTERNATIONAL = 'wives_of_icoba_international';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::ICOBANA_WIVES => 'ICOBANA Wives',
            self::WIVES_OF_ICOBA_EUROPE => 'Wives of ICOBA, Europe',
            self::WIVES_OF_ICOBA_INTERNATIONAL => 'Wives of ICOBA, International',
        };
    }

    /**
     * Normalize a slug or display label (case-insensitive) to a slug, or null when unknown.
     */
    public static function normalize(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $input = trim($value);
        if ($input === '') {
            return null;
        }

        $slug = strtolower($input);
        if (self::tryFrom($slug) !== null) {
            return $slug;
        }

        foreach (self::cases() as $case) {
            if (strcasecmp($case->label(), $input) === 0) {
                return $case->value;
            }
        }

        return null;
    }

    /**
     * Dropdown-friendly list of {value, label}.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => ['value' => $type->value, 'label' => $type->label()],
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
        $type = $value !== null ? self::tryFrom($value) : null;

        return $type !== null ? ['value' => $type->value, 'label' => $type->label()] : null;
    }
}
