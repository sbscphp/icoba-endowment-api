<?php

namespace Tests\Unit\Support;

use App\Support\CertificateDesignDefaults;
use PHPUnit\Framework\TestCase;

class CertificateDesignDefaultsTest extends TestCase
{
    public function test_apply_to_design_merges_system_defaults(): void
    {
        $design = CertificateDesignDefaults::applyToDesign([
            'image_type' => 'image_right',
            'lines' => [
                [
                    'text' => 'Presented to',
                    'font' => 'Inter',
                    'size' => '14px',
                ],
            ],
        ]);

        $this->assertSame(CertificateDesignDefaults::ICON_URL, $design['icon_url']);
        $this->assertSame(CertificateDesignDefaults::GENERAL_TEXT_POSITION, $design['general_text_position']);
        $this->assertSame(CertificateDesignDefaults::ICON_POSITION, $design['icon_position']);
        $this->assertSame(CertificateDesignDefaults::SEAL_IMAGE_URL, $design['seal_image_url']);
        $this->assertSame(CertificateDesignDefaults::AWARDEE_FONT, $design['awardee_font']);
        $this->assertSame(CertificateDesignDefaults::AWARDEE_FONT_SIZE, $design['awardee_font_size']);
        $this->assertSame(CertificateDesignDefaults::AWARDEE_FONT_WEIGHT, $design['awardee_font_weight']);
        $this->assertSame(CertificateDesignDefaults::LINE_WEIGHT, $design['lines'][0]['weight']);
        $this->assertSame(CertificateDesignDefaults::LINE_POSITION, $design['lines'][0]['position']);
    }

    public function test_sanitize_for_storage_removes_system_managed_fields(): void
    {
        $design = CertificateDesignDefaults::sanitizeForStorage([
            'image_type' => 'image_right',
            'image_url' => 'https://example.com/bg.png',
            'general_text_position' => 'center',
            'icon_url' => 'https://example.com/icon.png',
            'icon_position' => 'left',
            'seal_image_url' => 'https://example.com/seal.png',
            'awardee_font' => 'Inter',
            'awardee_font_size' => '32px',
            'awardee_font_weight' => 'Bold',
            'lines' => [
                [
                    'text' => 'Presented to',
                    'font' => 'Inter',
                    'size' => '14px',
                    'weight' => 'Regular',
                    'position' => 'center',
                ],
            ],
            'signatories' => [
                [
                    'name' => 'Ayanwale Erikina',
                    'position' => 'Global President',
                    'signature_url' => 'https://example.com/sign.png',
                ],
            ],
        ]);

        $this->assertSame([
            'image_type' => 'image_right',
            'image_url' => 'https://example.com/bg.png',
            'lines' => [
                [
                    'text' => 'Presented to',
                    'font' => 'Inter',
                    'size' => '14px',
                ],
            ],
            'signatories' => [
                [
                    'name' => 'Ayanwale Erikina',
                    'position' => 'Global President',
                    'signature_url' => 'https://example.com/sign.png',
                ],
            ],
        ], $design);
    }
}
