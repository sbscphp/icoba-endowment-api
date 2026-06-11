<?php

namespace App\Support;

final class CertificateDesignDefaults
{
    public const ICON_URL = 'https://res.cloudinary.com/sbsc/image/upload/v1780136149/uploads/images/26959_2026-05-30_1780136148.png';

    public const SEAL_IMAGE_URL = 'https://res.cloudinary.com/sbsc/image/upload/v1780136589/uploads/images/92467_2026-05-30_1780136584.png';

    public const GENERAL_TEXT_POSITION = 'center';

    public const ICON_POSITION = 'center';

    public const AWARDEE_FONT = 'Inter';

    public const AWARDEE_FONT_SIZE = '32px';

    public const AWARDEE_FONT_WEIGHT = 'Bold';

    public const LINE_WEIGHT = 'Regular';

    public const LINE_POSITION = 'center';

    /** @var list<string> */
    private const DESIGN_KEYS_MANAGED_BY_SYSTEM = [
        'general_text_position',
        'icon_url',
        'icon_position',
        'seal_image_url',
        'awardee_font',
        'awardee_font_size',
        'awardee_font_weight',
    ];

    /** @var list<string> */
    private const LINE_KEYS_MANAGED_BY_SYSTEM = [
        'weight',
        'position',
    ];

    /**
     * Merge system defaults into a design for certificate rendering.
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    public static function applyToDesign(array $design): array
    {
        $design = array_merge($design, [
            'general_text_position' => self::GENERAL_TEXT_POSITION,
            'icon_url' => self::ICON_URL,
            'icon_position' => self::ICON_POSITION,
            'seal_image_url' => self::SEAL_IMAGE_URL,
            'awardee_font' => self::AWARDEE_FONT,
            'awardee_font_size' => self::AWARDEE_FONT_SIZE,
            'awardee_font_weight' => self::AWARDEE_FONT_WEIGHT,
        ]);

        if (! isset($design['lines']) || ! is_array($design['lines'])) {
            return $design;
        }

        $design['lines'] = array_map(
            static function (mixed $line): array {
                if (! is_array($line)) {
                    return [];
                }

                return array_merge($line, [
                    'weight' => self::LINE_WEIGHT,
                    'position' => self::LINE_POSITION,
                ]);
            },
            $design['lines'],
        );

        return $design;
    }

    /**
     * Remove system-managed fields before persisting or returning API payloads.
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    public static function sanitizeForStorage(array $design): array
    {
        foreach (self::DESIGN_KEYS_MANAGED_BY_SYSTEM as $key) {
            unset($design[$key]);
        }

        if (! isset($design['lines']) || ! is_array($design['lines'])) {
            return $design;
        }

        $design['lines'] = array_map(
            static function (mixed $line): array {
                if (! is_array($line)) {
                    return [];
                }

                foreach (self::LINE_KEYS_MANAGED_BY_SYSTEM as $key) {
                    unset($line[$key]);
                }

                return $line;
            },
            $design['lines'],
        );

        return $design;
    }
}
