<?php

namespace App\Enums;

enum IssuedCertificateStatus: string
{
    case AUTO_ISSUED = 'auto_issued';
    case REISSUED = 'reissued';
    case REVOKED = 'revoked';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
