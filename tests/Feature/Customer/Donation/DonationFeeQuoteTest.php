<?php

namespace Tests\Feature\Customer\Donation;

use App\Models\PaymentFeeRule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

/**
 * Donors see what they give, the service fee and what they pay. The rule and its percentage
 * never appear in a customer response.
 */
final class DonationFeeQuoteTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/donations/fee-quote';

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        config(['platform_charges.enabled' => true]);

        PaymentFeeRule::query()->create([
            'name' => 'Service fee NGN',
            'currency' => 'NGN',
            'min_amount' => 0.01,
            'percentage' => 3.0,
            'is_active' => true,
        ]);
    }

    public function test_quote_returns_donation_fee_and_total_without_the_rule(): void
    {
        $response = $this->getJson(self::URL.'?amount=10000&currency=ngn')
            ->assertOk()
            ->assertJsonPath('error', false)
            ->assertJsonPath('data.donation_amount', '10000.00')
            ->assertJsonPath('data.service_fee', '300.00')
            ->assertJsonPath('data.fee_amount', '300.00')
            ->assertJsonPath('data.total_amount', '10300.00')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.charges_enabled', true)
            ->assertJsonPath('data.fee_applied', true);

        $this->assertArrayNotHasKey('rule', $response->json('data'));
        $this->assertStringNotContainsString('percentage', $response->getContent());
    }

    public function test_total_equals_donation_for_a_currency_without_a_rule(): void
    {
        $this->getJson(self::URL.'?amount=100&currency=USD')
            ->assertOk()
            ->assertJsonPath('data.donation_amount', '100.00')
            ->assertJsonPath('data.service_fee', '0.00')
            ->assertJsonPath('data.fee_applied', false)
            ->assertJsonPath('data.total_amount', '100.00');
    }

    public function test_total_equals_donation_when_platform_charges_are_off(): void
    {
        config(['platform_charges.enabled' => false]);

        $this->getJson(self::URL.'?amount=10000&currency=NGN')
            ->assertOk()
            ->assertJsonPath('data.charges_enabled', false)
            ->assertJsonPath('data.service_fee', '0.00')
            ->assertJsonPath('data.total_amount', '10000.00');
    }

    public function test_quote_validates_input(): void
    {
        $this->getJson(self::URL.'?amount=0&currency=NGN')->assertStatus(422);
        $this->getJson(self::URL.'?amount=100&currency=XYZ')->assertStatus(422);
        $this->getJson(self::URL.'?currency=NGN')->assertStatus(422);
    }
}
