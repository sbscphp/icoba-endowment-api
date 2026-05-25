<?php

namespace App\Enums;

enum CampaignUpdateReportStatus: string
{
    case ACTIVATED = 'activated';
    case DEACTIVATED = 'deactivated';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function fromIsActive(bool $isActive): self
    {
        return $isActive ? self::ACTIVATED : self::DEACTIVATED;
    }
}
