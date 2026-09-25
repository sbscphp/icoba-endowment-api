<?php

namespace Tests\Unit\Services\Donation;

use App\Enums\PledgeStatus;
use App\Http\Resources\TransactionResource;
use App\Models\Campaign;
use App\Models\Pledge;
use App\Services\Admin\Pledge\PledgeService;
use App\Services\Donation\DonationIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class DonationIntentPurposeTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_known_purpose_label_is_stored_as_slug(): void
    {
        $transaction = app(DonationIntentService::class)->createPendingIntent($this->intentPayload([
            'purpose' => 'Student Welfare',
        ]));

        $this->assertSame('student_welfare', $transaction->purpose);
        $this->assertSame(
            ['value' => 'student_welfare', 'label' => 'Student Welfare', 'is_custom' => false],
            TransactionResource::make($transaction)->resolve()['purpose'],
        );
    }

    public function test_custom_purpose_from_client_is_stored_verbatim(): void
    {
        $transaction = app(DonationIntentService::class)->createPendingIntent($this->intentPayload([
            'purpose' => 'Science lab equipment',
        ]));

        $this->assertSame('Science lab equipment', $transaction->purpose);
        $this->assertTrue(TransactionResource::make($transaction)->resolve()['purpose']['is_custom']);
    }

    public function test_purpose_is_null_when_omitted(): void
    {
        $transaction = app(DonationIntentService::class)->createPendingIntent($this->intentPayload());

        $this->assertNull($transaction->purpose);
        $this->assertNull(TransactionResource::make($transaction)->resolve()['purpose']);
    }

    public function test_pledge_payment_inherits_pledge_purpose_unless_overridden(): void
    {
        $pledge = $this->makePledge('infrastructure');

        $inherited = app(DonationIntentService::class)->createPendingIntent($this->intentPayload([
            'pledge_uuid' => $pledge->uuid,
            'amount' => 100,
        ]));
        $this->assertSame('infrastructure', $inherited->purpose);

        $overridden = app(DonationIntentService::class)->createPendingIntent($this->intentPayload([
            'pledge_uuid' => $pledge->uuid,
            'amount' => 100,
            'purpose' => 'general',
        ]));
        $this->assertSame('general', $overridden->purpose);
    }

    public function test_placeholder_transaction_inherits_pledge_purpose(): void
    {
        $pledge = $this->makePledge('Hostel repairs');

        $placeholder = app(PledgeService::class)->createPlaceholderTransaction($pledge, 500);

        $this->assertSame('Hostel repairs', $placeholder->purpose);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function intentPayload(array $overrides = []): array
    {
        return array_merge([
            'campaign_uuid' => $this->campaign->uuid,
            'amount' => 1000,
            'currency' => 'NGN',
            'donor_name' => 'Jane Donor',
            'donor_email' => 'jane@example.com',
            'donor_phone' => '+2348012345678',
        ], $overrides);
    }

    private function makePledge(string $purpose): Pledge
    {
        return Pledge::query()->create([
            'campaign_uuid' => $this->campaign->uuid,
            'user_uuid' => null,
            'donor_name' => 'Jane Donor',
            'donor_email' => 'jane@example.com',
            'donor_phone' => '+2348012345678',
            'is_anonymous' => false,
            'purpose' => $purpose,
            'committed_amount' => 500,
            'currency' => 'NGN',
            'committed_amount_ngn' => 500,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => 'monthly',
            'installment_count' => 1,
            'status' => PledgeStatus::ACTIVE,
        ]);
    }
}
