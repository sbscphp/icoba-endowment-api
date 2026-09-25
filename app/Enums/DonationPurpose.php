<?php

namespace App\Enums;

/**
 * Purpose a donation or pledge is given towards. Stored as slugs for the known
 * cases; clients may also submit a free-form purpose, which is stored verbatim.
 */
enum DonationPurpose: string
{
    case GENERAL = 'general';
    case STUDENT_WELFARE = 'student_welfare';
    case INFRASTRUCTURE = 'infrastructure';

    public const MAX_LENGTH = 120;

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::GENERAL => 'General',
            self::STUDENT_WELFARE => 'Student Welfare',
            self::INFRASTRUCTURE => 'Infrastructure',
        };
    }

    /**
     * Normalize client input to a storable purpose.
     *
     * A known slug or label (case-insensitive, spaces/hyphens tolerated) maps to the slug;
     * any other non-empty text is kept as a custom purpose. Blank input yields null.
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

        $slug = strtolower(preg_replace('/[\s\-]+/', '_', $input) ?? $input);
        if (self::tryFrom($slug) !== null) {
            return $slug;
        }

        foreach (self::cases() as $case) {
            if (strcasecmp($case->label(), $input) === 0) {
                return $case->value;
            }
        }

        return $input;
    }

    /**
     * Dropdown-friendly list of {value, label}.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $purpose) => ['value' => $purpose->value, 'label' => $purpose->label()],
            self::cases(),
        );
    }

    /**
     * Display payload for API resources. Custom purposes are echoed as their own label.
     *
     * @return array{value: string, label: string, is_custom: bool}|null
     */
    public static function payload(?string $value): ?array
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $purpose = self::tryFrom($value);

        return $purpose !== null
            ? ['value' => $purpose->value, 'label' => $purpose->label(), 'is_custom' => false]
            : ['value' => $value, 'label' => $value, 'is_custom' => true];
    }
}
