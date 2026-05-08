<?php

namespace App\Enums;

enum BulkEmailStatus: string
{
    case DRAFT = 'draft';
    case QUEUED = 'queued';
    case SENT = 'sent';
    case PARTIALLY_SENT = 'partially_sent';
    case FAILED = 'failed';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
