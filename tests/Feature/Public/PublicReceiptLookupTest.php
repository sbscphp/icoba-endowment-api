<?php

namespace Tests\Feature\Public;

use App\Enums\CampaignStatus;
use App\Enums\PaymentGateway;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

class PublicReceiptLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        config(['app.url' => 'https://api.example.test']);
    }

    public function test_returns_receipt_details_by_receipt_number(): void
    {
        $transaction = $this->makeReceiptedTransaction(receiptToken: 'tok-123');

        $response = $this->getJson('/api/v1/public/receipts/'.$transaction->receipt_number);

        $response->assertOk()
            ->assertJsonPath('error', false)
            ->assertJsonPath('data.receipt_number', $transaction->receipt_number)
            ->assertJsonPath('data.transaction_id', $transaction->transaction_id)
            ->assertJsonPath('data.status', 'successful')
            ->assertJsonPath('data.amount', '1000.00')
            ->assertJsonPath('data.currency', 'NGN')
            ->assertJsonPath('data.donor_name', 'Jane Donor')
            ->assertJsonPath('data.linked_campaign.name', 'Test Campaign')
            ->assertJsonPath(
                'data.receipt_download_url',
                'https://api.example.test/api/v1/public/receipts/'.$transaction->receipt_number.'/download?token=tok-123',
            )
            ->assertJsonMissingPath('data.donor_email')
            ->assertJsonMissingPath('data.donor_phone');
    }

    public function test_lookup_is_case_insensitive_on_receipt_number(): void
    {
        $transaction = $this->makeReceiptedTransaction();

        $this->getJson('/api/v1/public/receipts/'.strtolower($transaction->receipt_number))
            ->assertOk()
            ->assertJsonPath('data.receipt_number', $transaction->receipt_number);
    }

    public function test_hides_donor_name_for_anonymous_donations(): void
    {
        $transaction = $this->makeReceiptedTransaction(anonymous: true);

        $this->getJson('/api/v1/public/receipts/'.$transaction->receipt_number)
            ->assertOk()
            ->assertJsonPath('data.is_anonymous', true)
            ->assertJsonPath('data.donor_name', 'Anonymous')
            ->assertJsonPath('data.organization_name', null);
    }

    public function test_rejects_wrong_token_when_supplied(): void
    {
        $transaction = $this->makeReceiptedTransaction(receiptToken: 'tok-123');

        $this->getJson('/api/v1/public/receipts/'.$transaction->receipt_number.'?token=wrong')
            ->assertStatus(403);
    }

    public function test_returns_404_for_unknown_receipt_number(): void
    {
        $this->getJson('/api/v1/public/receipts/RCT-DOES-NOT-EXIST')
            ->assertStatus(404)
            ->assertJsonPath('error', true);
    }

    private function makeReceiptedTransaction(bool $anonymous = false, ?string $receiptToken = null): Transaction
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

        $transaction = Transaction::query()->create([
            'transaction_id' => 'TXN-'.Str::upper(Str::random(8)),
            'campaign_uuid' => $campaign->uuid,
            'amount' => 1000,
            'currency' => 'NGN',
            'amount_in_naira' => 1000,
            'exchange_rate_to_naira' => 1,
            'status' => TransactionStatus::SUCCESSFUL->value,
            'application_type' => TransactionApplicationType::INSTANT_DONATION->value,
            'gateway' => PaymentGateway::Fcmb->value,
            'gateway_reference' => 'INV-'.Str::upper(Str::random(6)),
            'donor_email' => 'donor@example.com',
            'donor_phone' => '+2348012345678',
            'donor_name' => 'Jane Donor',
            'is_anonymous' => $anonymous,
            'paid_at' => now(),
            'metadata' => ['payment_method' => 'fcmb_checkout'],
        ]);

        $transaction->forceFill([
            'receipt_number' => 'RCT-'.Str::upper(Str::random(8)),
            'receipt_token' => $receiptToken,
        ])->save();

        return $transaction->fresh();
    }
}
