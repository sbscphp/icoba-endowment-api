<?php

namespace App\Enums;

enum DonorTypeSlug: string
{
    case ICOBA_ALUMNI = 'icoba_alumni';
    case CORPORATE_DONOR = 'corporate_donor';
    case FRIENDS_OF_ICOBA = 'friends_of_icoba';
    case RELATIVES_OF_ICOBA = 'relatives_of_icoba';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::ICOBA_ALUMNI => 'ICOBA Alumni',
            self::CORPORATE_DONOR => 'Corporate Donor',
            self::FRIENDS_OF_ICOBA => 'Friends of ICOBA',
            self::RELATIVES_OF_ICOBA => 'Relatives of ICOBA',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ICOBA_ALUMNI => 'Donate as an old boy of Igbobi College, Lagos',
            self::CORPORATE_DONOR => 'Donate to Igbobi College as an organization, company or foundation.',
            self::FRIENDS_OF_ICOBA => 'Donate to Igbobi College as a friend of the ICOBA community.',
            self::RELATIVES_OF_ICOBA => 'Donate to Igbobi College as a relative of an Igbobi College alumnus.',
        };
    }
}
