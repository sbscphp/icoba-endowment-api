<?php

namespace Database\Seeders;

use App\Enums\CampaignCategory;
use App\Enums\CampaignStatus;
use App\Enums\Currency;
use App\Models\Campaign;
use Illuminate\Database\Seeder;

class DefaultCampaignSeeder extends Seeder
{
    public function run(): void
    {
        Campaign::query()->updateOrCreate(
            ['is_default' => true],
            [
                'campaign_id' => 'ICB-DEF',
                'name' => 'ICOBA General Endowment',
                'short_description' => 'Default fallback campaign — all donations not tied to a specific campaign are recorded here.',
                'long_description' => '<p>Default fallback campaign. All donations not tied to a specific campaign are attributed here.</p>',
                'cover_image' => null,
                'gallery_images' => null,
                'categories' => [CampaignCategory::OTHERS->value],
                'base_currency' => Currency::NGN->value,
                'available_donation_currencies' => Currency::values(),
                'target_amount' => '0',
                'start_date' => now()->toDateString(),
                'end_date' => now()->addYears(50)->toDateString(),
                'actual_start_date' => now(),
                'actual_end_date' => null,
                'allow_anonymous_donation' => true,
                'allow_public_donation' => true,
                'applies_to_all_graduation_sets' => true,
                'status' => CampaignStatus::ACTIVE,
                'created_by_admin_uuid' => null,
            ]
        );
    }
}
