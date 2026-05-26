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
        $logoUrl = rtrim((string) config('app.url'), '/').'/assets/logo/icoba-endowment.png';

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
                    'design' => $this->designForTier($tierName, $logoUrl),
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
    private function designForTier(string $tierName, string $iconUrl): array
    {
        return [
            'image_type' => 'background',
            'image_url' => null,
            'general_text_position' => 'center',
            'icon_url' => $iconUrl,
            'icon_position' => 'center',
            'seal_image_url' => null,
            'awardee_font' => 'DejaVu Sans',
            'awardee_font_size' => '28px',
            'awardee_font_weight' => 'bold',
            'lines' => [
                [
                    'text' => 'Certificate of Recognition',
                    'font' => 'DejaVu Sans',
                    'size' => '22px',
                    'weight' => 'bold',
                    'position' => 'center',
                ],
                [
                    'text' => 'This is to certify that the donor named above',
                    'font' => 'DejaVu Sans',
                    'size' => '14px',
                    'weight' => 'normal',
                    'position' => 'center',
                ],
                [
                    'text' => 'has been recognized as a '.$tierName.' in appreciation of outstanding cumulative support to the ICOBA Endowment Fund.',
                    'font' => 'DejaVu Sans',
                    'size' => '14px',
                    'weight' => 'normal',
                    'position' => 'center',
                ],
            ],
            'signatories' => [
                [
                    'name' => 'Chairman, Endowment Board',
                    'position' => 'ICOBA Endowment',
                    'signature_url' => null,
                ],
                [
                    'name' => 'Principal',
                    'position' => 'Igbobi College',
                    'signature_url' => null,
                ],
            ],
        ];
    }
}
