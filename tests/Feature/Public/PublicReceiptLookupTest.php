<?php

namespace Tests\Feature\Public;

use App\Enums\CampaignStatus;
use App\Enums\PaymentGateway;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use App\Models\Transaction;
use App\Services\Receipt\ReceiptService;
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

    public function test_returns_receipt_details_by_transaction_uuid(): void
    {
        $transaction = $this->makeReceiptedTransaction();

        $this->getJson('/api/v1/public/receipts/'.$transaction->uuid)
            ->assertOk()
            ->assertJsonPath('data.receipt_number', $transaction->receipt_number)
            ->assertJsonPath('data.transaction_uuid', $transaction->uuid);

        $this->getJson('/api/v1/public/receipts/'.strtoupper($transaction->uuid))
            ->assertOk()
            ->assertJsonPath('data.transaction_uuid', $transaction->uuid);
    }

    public function test_returns_404_for_uuid_of_unreceipted_transaction(): void
    {
        $transaction = $this->makeReceiptedTransaction();
        $transaction->forceFill(['receipt_number' => null])->save();

        $this->getJson('/api/v1/public/receipts/'.$transaction->uuid)
            ->assertStatus(404);
    }

    public function test_returns_404_for_unknown_receipt_number(): void
    {
        $this->getJson('/api/v1/public/receipts/RCT-DOES-NOT-EXIST')
            ->assertStatus(404)
            ->assertJsonPath('error', true);
    }

    public function test_downloads_tax_receipt_pdf_by_transaction_uuid(): void
    {
        $transaction = $this->makeReceiptedTransaction(corporate: true, receiptToken: 'tok-123');

        $this->get('/api/v1/public/receipts/'.$transaction->uuid.'/tax/download?token=tok-123')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="tax-receipt-'.$transaction->transaction_id.'.pdf"');

        $this->get('/api/v1/public/receipts/'.strtoupper($transaction->uuid).'/tax/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_downloads_tax_receipt_pdf_by_receipt_number(): void
    {
        $transaction = $this->makeReceiptedTransaction(corporate: true);

        $this->get('/api/v1/public/receipts/'.$transaction->receipt_number.'/tax/download')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_tax_receipt_download_returns_404_for_uuid_of_unreceipted_transaction(): void
    {
        $transaction = $this->makeReceiptedTransaction(corporate: true);
        $transaction->forceFill(['receipt_number' => null])->save();

        $this->get('/api/v1/public/receipts/'.$transaction->uuid.'/tax/download')
            ->assertStatus(404);
    }

    public function test_tax_receipt_download_rejects_non_corporate_donations(): void
    {
        $transaction = $this->makeReceiptedTransaction();

        $this->get('/api/v1/public/receipts/'.$transaction->uuid.'/tax/download')
            ->assertStatus(422);
    }

    public function test_receipt_templates_do_not_render_a_signature_block(): void
    {
        $transaction = $this->makeReceiptedTransaction(corporate: true);
        $receiptService = app(ReceiptService::class);

        $taxHtml = view('pdf.tax-exemption-receipt', $receiptService->taxReceiptViewData($transaction))->render();
        $donationHtml = view('pdf.donation-receipt', $receiptService->donationReceiptViewData($transaction))->render();

        foreach ([$taxHtml, $donationHtml] as $html) {
            $this->assertStringNotContainsStringIgnoringCase('signature-name', $html);
            $this->assertStringNotContainsStringIgnoringCase('Executive Director', $html);
            $this->assertStringContainsString('Date of Issue', $html);
        }
    }

    private function makeReceiptedTransaction(bool $anonymous = false, ?string $receiptToken = null, bool $corporate = false): Transaction
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
            'organization_name' => $corporate ? 'Acme Holdings Ltd' : null,
            'rc_number' => $corporate ? 'RC123456' : null,
            'tin' => $corporate ? 'TIN-7890' : null,
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
