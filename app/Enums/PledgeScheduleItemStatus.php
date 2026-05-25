<?php

namespace App\Enums;

enum PledgeScheduleItemStatus: string
{
    case PENDING = 'pending';
    case PARTIAL = 'partial';
    case PAID = 'paid';
    case PAUSED = 'paused';
    case OVERDUE = 'overdue';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
