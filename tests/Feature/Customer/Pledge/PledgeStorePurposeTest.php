<?php

namespace Tests\Feature\Customer\Pledge;

use App\Enums\DonorTypeSlug;
use App\Models\Campaign;
use App\Models\Country;
use App\Models\DonorType;
use App\Models\GraduationSet;
use App\Models\Pledge;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

final class PledgeStorePurposeTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        Notification::fake();

        Country::query()->create(['name' => 'Nigeria', 'iso2' => 'NG', 'dial_code' => '+234', 'is_active' => true]);

        foreach (DonorTypeSlug::cases() as $slug) {
            DonorType::query()->firstOrCreate(['slug' => $slug->value], ['label' => $slug->label(), 'description' => $slug->description()]);
        }

        GraduationSet::query()->create(['public_id' => 'SET-2002', 'name' => 'Class 2002', 'set_number' => '2002']);

        $this->campaign = Campaign::query()->create([
            'campaign_id' => 'CMP-PURPOSE',
            'name' => 'Purpose campaign',
            'short_description' => 'desc',
            'long_description' => 'desc',
            'categories' => ['general'],
            'base_currency' => 'NGN',
            'available_donation_currencies' => ['NGN'],
            'target_amount' => 10000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'allow_anonymous_donation' => true,
            'allow_public_donation' => true,
            'applies_to_all_graduation_sets' => true,
            'status' => 'active',
        ]);
    }

    public function test_guest_pledge_stores_known_purpose_as_slug(): void
    {
        $response = $this->postJson('/api/v1/pledges', $this->payload(['purpose' => 'Infrastructure']));

        $response->assertCreated()
            ->assertJsonPath('data.purpose.value', 'infrastructure')
            ->assertJsonPath('data.purpose.label', 'Infrastructure')
            ->assertJsonPath('data.purpose.is_custom', false);

        $this->assertSame('infrastructure', Pledge::query()->firstOrFail()->purpose);
    }

    public function test_guest_pledge_accepts_custom_purpose(): void
    {
        $response = $this->postJson('/api/v1/pledges', $this->payload(['purpose' => 'Sports pavilion']));

        $response->assertCreated()
            ->assertJsonPath('data.purpose.value', 'Sports pavilion')
            ->assertJsonPath('data.purpose.is_custom', true);
    }

    public function test_purpose_is_optional_and_length_limited(): void
    {
        $this->postJson('/api/v1/pledges', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.purpose', null);

        $this->postJson('/api/v1/pledges', $this->payload(['purpose' => str_repeat('x', 121)]))
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['purpose']]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'campaign_uuid' => $this->campaign->uuid,
            'donor_type' => DonorTypeSlug::ICOBA_ALUMNI->value,
            'firstname' => 'Ade',
            'lastname' => 'Alumni',
            'set_number' => '2002',
            'donor_email' => 'ade@example.com',
            'donor_phone' => '+2348012345678',
            'committed_amount' => 1000,
            'currency' => 'NGN',
            'payment_plan_type' => 'monthly',
            'installment_count' => 1,
        ], $overrides);
    }
}
