<?php

namespace Tests\Unit\Models;

use App\Enums\CampaignStatus;
use App\Enums\PaymentGateway;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Http\Resources\TransactionResource;
use App\Models\Campaign;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionPaymentMethodTest extends TestCase
{
    use RefreshDatabase;

    public function test_resolve_payment_method_uses_metadata_when_present(): void
    {
        $transaction = $this->makeTransaction([
            'gateway' => PaymentGateway::Fcmb->value,
            'metadata' => ['payment_method' => 'paystack'],
        ]);

        $this->assertSame('paystack', $transaction->resolvePaymentMethod());
    }

    public function test_resolve_payment_method_falls_back_to_fcmb_gateway_for_bank_transfer(): void
    {
        $transaction = $this->makeTransaction([
            'gateway' => PaymentGateway::Fcmb->value,
            'application_type' => TransactionApplicationType::BANK_TRANSFER->value,
            'metadata' => ['source' => 'admin_manual'],
        ]);

        $this->assertSame('bank_transfer', $transaction->resolvePaymentMethod());
    }

    public function test_resolve_payment_method_returns_fcmb_checkout_for_online_fcmb(): void
    {
        $transaction = $this->makeTransaction([
            'gateway' => PaymentGateway::Fcmb->value,
            'application_type' => TransactionApplicationType::INSTANT_DONATION->value,
            'metadata' => ['payment_method' => 'fcmb_checkout'],
        ]);

        $this->assertSame('fcmb_checkout', $transaction->resolvePaymentMethod());
    }

    public function test_transaction_resource_exposes_resolved_payment_method(): void
    {
        $transaction = $this->makeTransaction([
            'gateway' => PaymentGateway::Fcmb->value,
            'application_type' => TransactionApplicationType::BANK_TRANSFER->value,
            'metadata' => ['source' => 'admin_manual'],
        ]);

        $payload = TransactionResource::make($transaction)->resolve();

        $this->assertSame('bank_transfer', $payload['payment_method']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTransaction(array $overrides = []): Transaction
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

        return Transaction::query()->create(array_merge([
            'transaction_id' => 'TXN-'.Str::upper(Str::random(8)),
            'campaign_uuid' => $campaign->uuid,
            'amount' => 1000,
            'currency' => 'NGN',
            'status' => TransactionStatus::SUCCESSFUL->value,
            'application_type' => TransactionApplicationType::BANK_TRANSFER->value,
            'gateway' => PaymentGateway::Fcmb->value,
            'metadata' => [],
        ], $overrides));
    }
}
