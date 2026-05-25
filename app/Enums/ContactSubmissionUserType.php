<?php

namespace App\Enums;

enum ContactSubmissionUserType: string
{
    case ALUMNI = 'alumni';
    case CORPORATE = 'corporate';
    case INDIVIDUAL = 'individual';
    case OTHER = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::ALUMNI => 'Alumni',
            self::CORPORATE => 'Corporate',
            self::INDIVIDUAL => 'Individual',
            self::OTHER => 'Other',
        };
    }
}
