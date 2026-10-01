<?php

namespace App\Enums;

enum AboutSectionKey: string
{
    case OVERVIEW = 'overview';
    case VISION_MISSION = 'vision_mission';
    case IMPLEMENTATION_PLAN = 'implementation_plan';
    case PDF_VIEWER = 'pdf_viewer';

    public function label(): string
    {
        return match ($this) {
            self::OVERVIEW => 'Overview',
            self::VISION_MISSION => 'Vision and Mission',
            self::IMPLEMENTATION_PLAN => 'Implementation Plan',
            self::PDF_VIEWER => 'PDF Viewer',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
