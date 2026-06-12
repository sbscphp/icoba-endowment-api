<?php

namespace Tests\Unit\Services\Reconciliation;

use App\Enums\CampaignStatus;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use App\Models\Transaction;
use App\Services\Reconciliation\DonationReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DonationReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_manual_sets_gateway_reference_from_reference_id(): void
    {
        $campaign = $this->createCampaign(['NGN']);
        $referenceId = 'FCMB-REF-'.Str::upper(Str::random(8));

        $transaction = app(DonationReconciliationService::class)->createManual([
            'amount' => 5000,
            'reference_id' => $referenceId,
            'bank_key' => 'fcmb_ngn',
            'narration' => 'Corporate donation',
            'campaign_uuid' => $campaign->uuid,
        ], Str::uuid()->toString());

        $this->assertSame($referenceId, $transaction->gateway_reference);
        $this->assertSame($referenceId, $transaction->fcmb_statement_reference);
    }

    public function test_create_manual_rejects_unsupported_bank_currency_for_campaign(): void
    {
        $campaign = $this->createCampaign(['NGN']);

        $this->expectException(ValidationException::class);

        try {
            app(DonationReconciliationService::class)->createManual([
                'amount' => 500,
                'reference_id' => 'REF-'.Str::upper(Str::random(8)),
                'bank_key' => 'fcmb_usd',
                'narration' => 'Corporate donation',
                'campaign_uuid' => $campaign->uuid,
            ], Str::uuid()->toString());
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bank_key', $exception->errors());
            throw $exception;
        }
    }

    public function test_update_bank_account_rejects_unsupported_currency_for_linked_campaign(): void
    {
        $campaign = $this->createCampaign(['NGN']);
        $transaction = $this->createPendingBankTransfer([
            'campaign_uuid' => $campaign->uuid,
            'currency' => 'NGN',
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(DonationReconciliationService::class)->updateBankAccount($transaction, [
                'paid_into_account_key' => 'fcmb_usd',
            ]);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('paid_into_account_number', $exception->errors());
            throw $exception;
        }
    }

    public function test_complete_manual_rejects_campaign_when_transaction_currency_is_not_allowed(): void
    {
        $usdCampaign = $this->createCampaign(['USD', 'NGN']);
        $ngnOnlyCampaign = $this->createCampaign(['NGN']);
        $transaction = $this->createPendingBankTransfer([
            'campaign_uuid' => $usdCampaign->uuid,
            'currency' => 'USD',
            'amount' => 100,
            'amount_in_naira' => 150000,
            'exchange_rate_to_naira' => 1500,
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(DonationReconciliationService::class)->completeManual($transaction, [
                'campaign_uuid' => $ngnOnlyCampaign->uuid,
            ], Str::uuid()->toString());
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('campaign_uuid', $exception->errors());
            throw $exception;
        }
    }

    /**
     * @param  list<string>  $currencies
     */
    private function createCampaign(array $currencies): Campaign
    {
        return Campaign::query()->create([
            'campaign_id' => 'CAMP-'.Str::upper(Str::random(8)),
            'name' => 'Test Campaign',
            'short_description' => 'Short description',
            'long_description' => 'Long description',
            'categories' => ['general'],
            'base_currency' => $currencies[0],
            'available_donation_currencies' => $currencies,
            'target_amount' => 1000000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => CampaignStatus::ACTIVE->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPendingBankTransfer(array $overrides = []): Transaction
    {
        return Transaction::query()->create(array_merge([
            'transaction_id' => 'TXN-'.Str::upper(Str::random(8)),
            'amount' => 1000,
            'currency' => 'NGN',
            'status' => TransactionStatus::PENDING->value,
            'application_type' => TransactionApplicationType::BANK_TRANSFER->value,
            'bank_transfer_reference' => 'REF-'.Str::upper(Str::random(8)),
            'metadata' => ['source' => 'admin_manual'],
        ], $overrides));
    }
}
