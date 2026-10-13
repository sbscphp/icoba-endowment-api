<?php

namespace Tests\Unit\Services\Donation;

use App\Http\Resources\TransactionResource;
use App\Models\Campaign;
use App\Models\PaymentFeeRule;
use App\Services\Donation\DonationIntentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * The donation amount must never absorb the service fee: `amount` is the donation,
 * the fee has its own column, and total_amount is what the gateway is asked to charge.
 */
final class DonationIntentServiceFeeTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        config(['platform_charges.enabled' => true]);

        $this->campaign = Campaign::query()->create([
            'campaign_id' => 'CMP-FEES',
            'name' => 'Fee campaign',
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

        PaymentFeeRule::query()->create([
            'name' => 'Service fee NGN',
            'currency' => 'NGN',
            'min_amount' => 0.01,
            'percentage' => 3.0,
            'fixed_amount' => 0,
            'is_active' => true,
        ]);
    }

    public function test_intent_keeps_donation_amount_and_stores_fee_separately(): void
    {
        $transaction = app(DonationIntentService::class)->createPendingIntent($this->payload(['gateway' => 'paystack']));

        $this->assertSame('10000.00', (string) $transaction->amount);
        $this->assertSame('300.00', (string) $transaction->fee_amount);
        $this->assertSame('10300.00', (string) $transaction->total_amount);
        $this->assertSame('10000.00', (string) $transaction->amount_in_naira);
        $this->assertSame('300.00', (string) $transaction->fee_amount_in_naira);
        $this->assertNotNull($transaction->fee_rule_uuid);
        $this->assertSame('Service fee NGN', $transaction->fee_snapshot['rule']['name']);
        $this->assertSame(10300.0, $transaction->chargeAmount());
    }

    public function test_fee_is_the_same_whichever_gateway_is_chosen(): void
    {
        $service = app(DonationIntentService::class);

        $stripe = $service->createPendingIntent($this->payload(['gateway' => 'stripe']));
        $fcmb = $service->createPendingIntent($this->payload(['gateway' => 'fcmb']));

        $this->assertSame('300.00', (string) $stripe->fee_amount);
        $this->assertSame('300.00', (string) $fcmb->fee_amount);
    }

    public function test_intent_with_charges_disabled_charges_exactly_the_donation(): void
    {
        config(['platform_charges.enabled' => false]);

        $transaction = app(DonationIntentService::class)->createPendingIntent($this->payload(['gateway' => 'paystack']));

        $this->assertSame('10000.00', (string) $transaction->amount);
        $this->assertSame('0.00', (string) $transaction->fee_amount);
        $this->assertSame('10000.00', (string) $transaction->total_amount);
        $this->assertSame('0.00', (string) $transaction->fee_amount_in_naira);
        $this->assertNull($transaction->fee_snapshot);
    }

    public function test_stale_expected_total_is_rejected(): void
    {
        $this->expectException(ValidationException::class);

        app(DonationIntentService::class)->createPendingIntent($this->payload([
            'gateway' => 'paystack',
            'expected_total_amount' => 10000,
        ]));
    }

    public function test_matching_expected_total_is_accepted(): void
    {
        $transaction = app(DonationIntentService::class)->createPendingIntent($this->payload([
            'gateway' => 'paystack',
            'expected_total_amount' => 10300,
        ]));

        $this->assertSame('10300.00', (string) $transaction->total_amount);
    }

    public function test_resource_exposes_fee_alongside_donation(): void
    {
        $transaction = app(DonationIntentService::class)->createPendingIntent($this->payload(['gateway' => 'paystack']));

        $payload = TransactionResource::make($transaction)->resolve();

        $this->assertSame('10000.00', $payload['amount']);
        $this->assertSame('300.00', $payload['fee_amount']);
        $this->assertSame('10300.00', $payload['total_amount']);
        $this->assertNull($payload['realized_margin_amount']);
        $this->assertFalse($payload['gateway_amount_mismatch']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'campaign_uuid' => $this->campaign->uuid,
            'amount' => 10000,
            'currency' => 'NGN',
            'donor_name' => 'Jane Donor',
            'donor_email' => 'jane@example.com',
            'donor_phone' => '+2348012345678',
        ], $overrides);
    }
}
