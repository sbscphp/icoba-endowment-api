<?php

namespace Tests\Unit\Services\Admin\Transaction;

use App\Enums\CampaignStatus;
use App\Enums\PaymentGateway;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Exceptions\ApiException;
use App\Models\Campaign;
use App\Models\Transaction;
use App\Services\Admin\Transaction\TransactionGatewayVerificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class TransactionGatewayVerificationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fcmb.base_url' => 'https://dev.clnx.io',
            'services.fcmb.business_id' => 'test-business-id',
            'services.fcmb.secret_key' => 'test-secret-key',
        ]);
    }

    public function test_marks_pending_fcmb_transaction_failed_when_gateway_reports_failure(): void
    {
        $transaction = $this->makePendingFcmbTransaction();
        $this->fakeFcmbStatus($transaction, 'FAILED');

        $result = app(TransactionGatewayVerificationService::class)->verify($transaction);

        $this->assertSame('fcmb', $result['payment_gateway']);
        $this->assertSame('marked_failed', $result['sync_action']);
        $this->assertSame('unpaid', $result['payment_status']);
        $this->assertSame(TransactionStatus::FAILED, $transaction->fresh()->status);
    }

    public function test_finalizes_pending_fcmb_transaction_when_gateway_reports_success(): void
    {
        $transaction = $this->makePendingFcmbTransaction();
        $this->fakeFcmbStatus($transaction, 'SUCCESS');

        $result = app(TransactionGatewayVerificationService::class)->verify($transaction);

        $this->assertSame('finalized', $result['sync_action']);
        $this->assertSame('paid', $result['payment_status']);
        $this->assertSame(TransactionStatus::SUCCESSFUL, $transaction->fresh()->status);
    }

    public function test_leaves_transaction_pending_when_gateway_status_is_unknown(): void
    {
        $transaction = $this->makePendingFcmbTransaction();
        $this->fakeFcmbStatus($transaction, 'PENDING');

        $result = app(TransactionGatewayVerificationService::class)->verify($transaction);

        $this->assertSame('pending', $result['sync_action']);
        $this->assertSame(TransactionStatus::PENDING, $transaction->fresh()->status);
    }

    public function test_rejects_transaction_without_gateway_reference(): void
    {
        $transaction = $this->makePendingFcmbTransaction(gatewayReference: null);

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('no gateway reference');

        app(TransactionGatewayVerificationService::class)->verify($transaction);
    }

    public function test_rejects_transaction_without_verifiable_gateway(): void
    {
        $transaction = $this->makePendingFcmbTransaction();
        $transaction->forceFill(['gateway' => null])->save();

        $this->expectException(ApiException::class);
        $this->expectExceptionMessage('verifiable payment gateway');

        app(TransactionGatewayVerificationService::class)->verify($transaction->fresh());
    }

    private function fakeFcmbStatus(Transaction $transaction, string $status): void
    {
        Http::fake([
            'https://dev.clnx.io/api/v1/public/pay/'.$transaction->gateway_reference.'*' => Http::response([
                'status' => true,
                'data' => [
                    'invoiceRequestReference' => $transaction->gateway_reference,
                    'status' => $status,
                    'reference' => 'GAT-'.$status,
                    'customFields' => [
                        ['label' => 'transaction_uuid', 'value' => $transaction->uuid],
                    ],
                ],
            ]),
        ]);
    }

    private function makePendingFcmbTransaction(?string $gatewayReference = 'INV-TEST-REF'): Transaction
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
            'gateway_reference' => $gatewayReference,
            'donor_email' => 'donor@example.com',
            'donor_name' => 'Jane Donor',
            'metadata' => ['payment_method' => 'fcmb_checkout'],
        ]);
    }
}
