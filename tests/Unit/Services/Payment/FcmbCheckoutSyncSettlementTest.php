<?php

namespace Tests\Unit\Services\Payment;

use App\Enums\CampaignStatus;
use App\Enums\PaymentGateway;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use App\Models\Transaction;
use App\Services\Payment\FcmbCheckoutSyncService;
use App\Services\Payment\FcmbCheckoutTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Webhook separation: what the gateway reports goes into gateway_* columns and metadata only.
 * The donation amount, fee and total fixed at intent time are never rewritten.
 */
final class FcmbCheckoutSyncSettlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_webhook_records_gateway_figures_without_touching_donation_or_fee(): void
    {
        $transaction = $this->makePendingTransaction(amount: 10000, fee: 200);

        $checkout = FcmbCheckoutTransaction::fromClnxData([
            'invoiceRequestReference' => $transaction->uuid,
            'reference' => 'GAT-1',
            'status' => 'SUCCESS',
            'originalAmount' => 10200,
            'amount' => 10965.00,
            'serviceFee' => 765.00,
            'customFields' => [['label' => 'transaction_uuid', 'value' => $transaction->uuid]],
        ]);

        $this->assertTrue(app(FcmbCheckoutSyncService::class)->finalizeIfPaid($checkout));

        $transaction->refresh();
        $this->assertSame(TransactionStatus::SUCCESSFUL, $transaction->status);
        $this->assertSame('10000.00', (string) $transaction->amount);
        $this->assertSame('200.00', (string) $transaction->fee_amount);
        $this->assertSame('10200.00', (string) $transaction->total_amount);
        $this->assertSame('10965.00', (string) $transaction->gateway_amount_received);
        $this->assertSame('765.00', (string) $transaction->gateway_fee_amount);
        $this->assertSame('200.00', (string) $transaction->fee_amount_in_naira);
        // Margin = our fee minus what the gateway kept.
        $this->assertSame(-565.0, $transaction->realizedMarginAmount());
        $this->assertFalse($transaction->hasGatewayAmountMismatch());
        $this->assertEquals(10200, $transaction->metadata['fcmb_original_amount']);
    }

    public function test_mismatch_between_gateway_charge_and_total_is_flagged_but_still_finalized(): void
    {
        $transaction = $this->makePendingTransaction(amount: 10000, fee: 200);

        $checkout = FcmbCheckoutTransaction::fromClnxData([
            'invoiceRequestReference' => $transaction->uuid,
            'reference' => 'GAT-2',
            'status' => 'SUCCESS',
            'originalAmount' => 10000,
            'amount' => 10000,
        ]);

        $this->assertTrue(app(FcmbCheckoutSyncService::class)->finalizeIfPaid($checkout));

        $transaction->refresh();
        $this->assertSame(TransactionStatus::SUCCESSFUL, $transaction->status);
        $this->assertTrue($transaction->hasGatewayAmountMismatch());
        $this->assertSame('-200.00', $transaction->metadata['gateway_amount_difference']);
        $this->assertSame('10000.00', (string) $transaction->amount);
        $this->assertSame('10200.00', (string) $transaction->total_amount);
    }

    public function test_legacy_row_without_total_is_backfilled_on_finalize(): void
    {
        $transaction = $this->makePendingTransaction(amount: 5000, fee: 0);
        Transaction::query()->whereKey($transaction->getKey())->update(['total_amount' => null, 'fee_amount_in_naira' => null]);

        $checkout = new FcmbCheckoutTransaction(
            invoiceRequestReference: $transaction->uuid,
            status: 'SUCCESS',
            transactionUuid: $transaction->uuid,
            reference: 'GAT-3',
        );

        $this->assertTrue(app(FcmbCheckoutSyncService::class)->finalizeIfPaid($checkout));

        $transaction->refresh();
        $this->assertSame('5000.00', (string) $transaction->total_amount);
        $this->assertSame('0.00', (string) $transaction->fee_amount_in_naira);
        $this->assertNull($transaction->gateway_amount_received);
    }

    private function makePendingTransaction(float $amount, float $fee): Transaction
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
            'amount' => $amount,
            'fee_amount' => $fee,
            'total_amount' => $amount + $fee,
            'currency' => 'NGN',
            'exchange_rate_to_naira' => 1,
            'amount_in_naira' => $amount,
            'status' => TransactionStatus::PENDING->value,
            'application_type' => TransactionApplicationType::INSTANT_DONATION->value,
            'gateway' => PaymentGateway::Fcmb->value,
            'gateway_reference' => null,
            'donor_email' => 'donor@example.com',
            'donor_name' => 'Jane Donor',
            'metadata' => ['payment_method' => 'fcmb_checkout'],
        ]);
    }
}
