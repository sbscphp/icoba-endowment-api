<?php

namespace App\Enums;

enum CertificatePreviewFormat: string
{
    case Html = 'html';
    case Pdf = 'pdf';
    case Png = 'png';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public static function tryFromRequest(mixed $value): self
    {
        $normalized = strtolower(trim((string) $value));

        return self::tryFrom($normalized) ?? self::Pdf;
    }
}
