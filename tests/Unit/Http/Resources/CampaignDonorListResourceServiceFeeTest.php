<?php

namespace Tests\Unit\Http\Resources;

use App\Enums\CampaignStatus;
use App\Enums\PaymentGateway;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Http\Resources\Admin\CampaignDonorListResource;
use App\Models\Campaign;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CampaignDonorListResourceServiceFeeTest extends TestCase
{
    use RefreshDatabase;

    public function test_row_exposes_service_fee_and_total_charged(): void
    {
        $payload = CampaignDonorListResource::make($this->makeTransaction([
            'fee_amount' => 300,
            'total_amount' => 10300,
            'fee_amount_in_naira' => 300,
        ]))->resolve(Request::create('/'));

        $this->assertSame('10000.00', $payload['donation_value']);
        $this->assertSame('300.00', $payload['service_fee']);
        $this->assertSame('300.00', $payload['service_fee_in_naira']);
        $this->assertSame('10300.00', $payload['total_amount']);
    }

    public function test_legacy_row_without_a_fee_reports_zero(): void
    {
        $payload = CampaignDonorListResource::make($this->makeTransaction([
            'fee_amount' => 0,
            'total_amount' => null,
            'fee_amount_in_naira' => null,
        ]))->resolve(Request::create('/'));

        $this->assertSame('0.00', $payload['service_fee']);
        $this->assertNull($payload['service_fee_in_naira']);
        $this->assertSame('10000.00', $payload['total_amount']);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function makeTransaction(array $overrides): Transaction
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
            'amount' => 10000,
            'currency' => 'NGN',
            'exchange_rate_to_naira' => 1,
            'amount_in_naira' => 10000,
            'status' => TransactionStatus::SUCCESSFUL->value,
            'application_type' => TransactionApplicationType::INSTANT_DONATION->value,
            'gateway' => PaymentGateway::Fcmb->value,
            'gateway_reference' => 'INV-'.Str::upper(Str::random(6)),
            'donor_email' => 'donor@example.com',
            'donor_name' => 'Jane Donor',
            'paid_at' => now(),
        ], $overrides));
    }
}
