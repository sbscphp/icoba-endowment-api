<?php

namespace Database\Seeders;

use App\Models\CertificateTemplate;
use App\Models\TierConfiguration;
use Illuminate\Database\Seeder;

class CertificateTemplateSeeder extends Seeder
{
    // php artisan db:seed --class=CertificateTemplateSeeder

    public function run(): void
    {
        $templates = [
            'Friends of Igbobi College',
            'Bronze Contributor',
            'Silver Supporter',
            'Gold Benefactor',
            'Platinum Benefactor',
            "Founders/Principals' Circle",
        ];

        foreach ($templates as $tierName) {
            $tier = TierConfiguration::query()
                ->where('name', $tierName)
                ->where('is_active', true)
                ->first();

            if ($tier === null) {
                $this->command?->warn("Skipping certificate template — active tier not found: {$tierName}");

                continue;
            }

            CertificateTemplate::query()->updateOrCreate(
                ['name' => $tierName.' Certificate'],
                [
                    'tier_uuid' => $tier->uuid,
                    'design' => $this->designForTier($tierName),
                    'is_active' => true,
                ],
            );
        }

        $seededTierUuids = TierConfiguration::query()
            ->whereIn('name', $templates)
            ->pluck('uuid')
            ->all();

        if ($seededTierUuids !== []) {
            CertificateTemplate::query()
                ->whereIn('tier_uuid', $seededTierUuids)
                ->whereNotIn('name', array_map(
                    static fn (string $name): string => $name.' Certificate',
                    $templates,
                ))
                ->update(['is_active' => false]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function designForTier(string $tierName): array
    {
        return [
            'image_type' => 'image_left',
            'image_url' => 'https://res.cloudinary.com/sbsc/image/upload/v1780135574/uploads/images/95276_2026-05-30_1780135564.png',
            'awardee_name_after_line' => 2,
            'lines' => [
                [
                    'text' => 'Certificate Of',
                    'font' => 'DejaVu Sans',
                    'size' => '22px',
                ],
                [
                    'text' => 'Endowment Contribution',
                    'font' => 'DejaVu Sans',
                    'size' => '30px',
                ],
                [
                    'text' => 'THIS CERTIFICATE IS PROUDLY PRESENTED TO',
                    'font' => 'DejaVu Sans',
                    'size' => '11px',
                ],
                [
                    'text' => 'In grateful recognition of their generous contribution to the ICOBA Endowment Programme. Your support plays a vital role in sustaining excellence, empowering future generations, and strengthening the legacy of our institution.',
                    'font' => 'DejaVu Sans',
                    'size' => '12px',
                ],
                [
                    'text' => 'has been recognized as a '.$tierName.' in appreciation of outstanding cumulative support to the ICOBA Endowment Fund.',
                    'font' => 'DejaVu Sans',
                    'size' => '12px',
                ],
            ],
            'signatories' => [
                [
                    'name' => 'Ayanwale Erikina',
                    'position' => 'Global President',
                    'signature_url' => 'https://res.cloudinary.com/sbsc/image/upload/v1780135927/uploads/images/30202_2026-05-30_1780135925.png',
                ],
                [
                    'name' => 'Adekunle Modupeola',
                    'position' => 'Head, Endowment Initiative',
                    'signature_url' => 'https://res.cloudinary.com/sbsc/image/upload/v1780136000/uploads/images/72185_2026-05-30_1780135998.png',
                ],
            ],
        ];
    }
}
