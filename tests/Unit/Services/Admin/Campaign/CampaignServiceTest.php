<?php

namespace Tests\Unit\Services\Admin\Campaign;

use App\Enums\CampaignStatus;
use App\Helpers\PDFReportHelper;
use App\Models\Admin;
use App\Models\Campaign;
use App\Services\Admin\Campaign\CampaignService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class CampaignServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_updates_available_donation_currencies_for_non_draft_campaigns(): void
    {
        $admin = Admin::query()->create([
            'name' => 'Test Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret1234'),
        ]);

        $campaign = Campaign::query()->create([
            'campaign_id' => 'ICB-TEST-1',
            'name' => 'Test Campaign',
            'short_description' => 'Short description',
            'long_description' => 'Long description',
            'categories' => ['infrastructural_development'],
            'base_currency' => 'NGN',
            'available_donation_currencies' => ['NGN'],
            'target_amount' => '100000',
            'start_date' => '2026-07-14',
            'end_date' => '2026-08-31',
            'allow_anonymous_donation' => true,
            'allow_public_donation' => true,
            'applies_to_all_graduation_sets' => true,
            'status' => CampaignStatus::ACTIVE,
            'created_by_admin_uuid' => $admin->uuid,
        ]);

        $service = new CampaignService(new PDFReportHelper());

        $updatedCampaign = $service->update(
            $campaign->uuid,
            ['available_donation_currencies' => ['USD', 'EUR']],
            $admin,
            new Request(),
        );

        $this->assertSame(['USD', 'EUR'], $updatedCampaign->fresh()->available_donation_currencies);
    }
}
