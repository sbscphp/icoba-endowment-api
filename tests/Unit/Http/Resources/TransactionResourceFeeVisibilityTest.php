<?php

namespace Tests\Unit\Http\Resources;

use App\Enums\CampaignStatus;
use App\Enums\PaymentGateway;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Http\Resources\Admin\TransactionListResource;
use App\Http\Resources\TransactionResource;
use App\Models\Admin;
use App\Models\Campaign;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Fee details are admin-only. A donor (or guest) resolving the same resource sees the donation
 * and the total they paid, nothing that reveals the fee or the percentage behind it.
 */
final class TransactionResourceFeeVisibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_sees_donation_and_total_but_no_fee_details(): void
    {
        $transaction = $this->makeTransaction();

        $payload = TransactionResource::make($transaction)->resolve(Request::create('/'));

        $this->assertSame('10000.00', $payload['amount']);
        $this->assertSame('10300.00', $payload['total_amount']);
        foreach (['service_fee', 'service_fee_in_naira', 'fee_amount', 'fee_amount_in_naira', 'fee_snapshot', 'gateway_amount_received', 'gateway_fee_amount', 'realized_margin_amount', 'gateway_amount_mismatch'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $payload, $hidden.' leaked to a customer');
        }
        $this->assertArrayNotHasKey('gateway_amount_mismatch', $payload['metadata']);
        $this->assertArrayNotHasKey('stripe_balance_fee', $payload['metadata']);
        $this->assertSame('stripe', $payload['metadata']['payment_method']);
        $this->assertStringNotContainsString('percentage', json_encode($payload));
    }

    public function test_admin_sees_the_full_fee_breakdown(): void
    {
        $transaction = $this->makeTransaction();
        $admin = Admin::query()->create([
            'name' => 'Fee Admin',
            'email' => 'fee-admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'can_login' => true,
            '2fa' => false,
        ]);
        $request = Request::create('/');
        $request->setUserResolver(static fn () => $admin);

        $payload = TransactionResource::make($transaction)->resolve($request);

        $this->assertSame('300.00', $payload['fee_amount']);
        $this->assertSame('300.00', $payload['service_fee']);
        $this->assertSame('300.00', $payload['service_fee_in_naira']);
        $this->assertSame('10300.00', $payload['total_amount']);
        $this->assertSame('250.00', $payload['gateway_fee_amount']);
        $this->assertSame('50.00', $payload['realized_margin_amount']);
        $this->assertSame('3.0000', $payload['fee_snapshot']['rule']['percentage']);
        $this->assertSame('0.49', $payload['metadata']['stripe_balance_fee']);
    }

    public function test_admin_list_row_exposes_service_fee(): void
    {
        $payload = TransactionListResource::make($this->makeTransaction())->resolve(Request::create('/'));

        $this->assertSame('300.00', $payload['service_fee']);
        $this->assertSame('300.00', $payload['service_fee_in_naira']);
        $this->assertSame('300.00', $payload['fee_amount']);
    }

    private function makeTransaction(): Transaction
    {
        $campaign = Campaign::query()->create([
            'campaign_id' => 'CAMP-'.Str::upper(Str::random(8)),
            'name' => 'Test Campaign',
            'short_description' => 'Short description',
            'long_description' => 'Long description',
            'categories' => ['general'],
            'base_currency' => 'NGN',
            'available_donation_currencies' => ['NGN'],
            'target_amount' => 1000000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => CampaignStatus::ACTIVE->value,
        ]);

        return Transaction::query()->create([
            'transaction_id' => 'TXN-'.Str::upper(Str::random(8)),
            'campaign_uuid' => $campaign->uuid,
            'amount' => 10000,
            'fee_amount' => 300,
            'total_amount' => 10300,
            'fee_amount_in_naira' => 300,
            'fee_snapshot' => ['rule' => ['name' => 'Service fee NGN', 'percentage' => '3.0000']],
            'gateway_amount_received' => 10300,
            'gateway_fee_amount' => 250,
            'currency' => 'NGN',
            'exchange_rate_to_naira' => 1,
            'amount_in_naira' => 10000,
            'status' => TransactionStatus::SUCCESSFUL->value,
            'application_type' => TransactionApplicationType::INSTANT_DONATION->value,
            'gateway' => PaymentGateway::Stripe->value,
            'gateway_reference' => 'pi_test',
            'donor_email' => 'donor@example.com',
            'donor_name' => 'Jane Donor',
            'paid_at' => now(),
            'metadata' => [
                'payment_method' => 'stripe',
                'gateway_amount_mismatch' => false,
                'stripe_balance_fee' => '0.49',
                'stripe_balance_fee_currency' => 'USD',
            ],
        ]);
    }
}
