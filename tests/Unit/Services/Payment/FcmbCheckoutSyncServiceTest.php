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

class FcmbCheckoutSyncServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_finalize_if_paid_is_idempotent(): void
    {
        $transaction = $this->makePendingTransaction();
        $checkout = new FcmbCheckoutTransaction(
            invoiceRequestReference: $transaction->uuid,
            status: 'SUCCESS',
            transactionUuid: $transaction->uuid,
            reference: 'GAT-test-reference',
        );

        $service = app(FcmbCheckoutSyncService::class);

        $this->assertTrue($service->finalizeIfPaid($checkout));
        $transaction->refresh();
        $this->assertSame(TransactionStatus::SUCCESSFUL, $transaction->status);

        $this->assertFalse($service->finalizeIfPaid($checkout));
    }

    public function test_mark_pending_failed_only_when_pending(): void
    {
        $transaction = $this->makePendingTransaction();
        $checkout = new FcmbCheckoutTransaction(
            invoiceRequestReference: $transaction->uuid,
            status: 'FAILED',
            transactionUuid: $transaction->uuid,
            reference: 'GAT-failed-reference',
        );

        $service = app(FcmbCheckoutSyncService::class);

        $this->assertTrue($service->markPendingFailed($checkout));
        $transaction->refresh();
        $this->assertSame(TransactionStatus::FAILED, $transaction->status);

        $this->assertFalse($service->markPendingFailed($checkout));
    }

    private function makePendingTransaction(): Transaction
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
            'amount' => 1000,
            'currency' => 'NGN',
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
