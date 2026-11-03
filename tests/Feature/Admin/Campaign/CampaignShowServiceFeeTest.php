<?php

namespace Tests\Feature\Admin\Campaign;

use App\Enums\CampaignStatus;
use App\Enums\PaymentGateway;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Admin;
use App\Models\Campaign;
use App\Models\Country;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Tests\TestCase;

final class CampaignShowServiceFeeTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class, PermissionMiddleware::class]);
        Country::query()->firstOrCreate(['iso2' => 'NG'], ['name' => 'Nigeria', 'dial_code' => '+234', 'is_active' => true]);

        $admin = Admin::query()->create([
            'name' => 'Campaign Admin',
            'email' => 'campaign-admin@example.com',
            'password' => Hash::make('password'),
            'is_active' => true,
            'can_login' => true,
            '2fa' => false,
        ]);
        Sanctum::actingAs($admin);

        $this->campaign = Campaign::query()->create([
            'campaign_id' => 'CAMP-'.Str::upper(Str::random(8)),
            'name' => 'Test Campaign',
            'short_description' => 'Short description',
            'long_description' => 'Long description',
            'categories' => ['general'],
            'base_currency' => 'NGN',
            'available_donation_currencies' => ['NGN', 'USD'],
            'target_amount' => 1000000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => CampaignStatus::ACTIVE->value,
        ]);

        // NGN donation with a fee.
        $this->createTransaction(['amount' => 1000, 'amount_in_naira' => 1000, 'fee_amount' => 30, 'fee_amount_in_naira' => 30]);
        // USD donation with a fee, converted at 1500.
        $this->createTransaction([
            'currency' => 'USD',
            'amount' => 10,
            'amount_in_naira' => 15000,
            'exchange_rate_to_naira' => 1500,
            'fee_amount' => 0.5,
            'fee_amount_in_naira' => 750,
        ]);
        // Legacy row with no fee data.
        $this->createTransaction(['amount' => 500, 'amount_in_naira' => 500, 'fee_amount' => 0, 'fee_amount_in_naira' => null]);
        // Failed transaction must not count.
        $this->createTransaction(['amount' => 9999, 'amount_in_naira' => 9999, 'fee_amount' => 999, 'fee_amount_in_naira' => 999, 'status' => TransactionStatus::FAILED->value]);
    }

    public function test_show_reports_service_fee_in_naira_by_default(): void
    {
        $response = $this->getJson('/api/v1/admin/campaigns/'.$this->campaign->uuid)
            ->assertOk()
            ->assertJsonPath('data.total_service_fee', '780.00')
            ->assertJsonPath('data.total_service_fee_currency', 'NGN')
            ->assertJsonPath('data.total_service_fee_in_naira', '780.00')
            ->assertJsonPath('data.total_raised_including_service_fee', '17280.00');

        $this->assertSame(16500.0, (float) $response->json('data.total_raised'));
    }

    public function test_show_reports_native_service_fee_for_a_currency_filter(): void
    {
        $response = $this->getJson('/api/v1/admin/campaigns/'.$this->campaign->uuid.'?filters[raised_currency]=USD')
            ->assertOk()
            ->assertJsonPath('data.total_service_fee', '0.50')
            ->assertJsonPath('data.total_service_fee_currency', 'USD')
            ->assertJsonPath('data.total_service_fee_in_naira', '780.00')
            ->assertJsonPath('data.total_raised_including_service_fee', '10.50');

        $this->assertSame(10.0, (float) $response->json('data.total_raised'));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTransaction(array $overrides = []): Transaction
    {
        return Transaction::query()->create(array_merge([
            'transaction_id' => 'TXN-'.Str::upper(Str::random(8)),
            'campaign_uuid' => $this->campaign->uuid,
            'amount' => 1000,
            'currency' => 'NGN',
            'amount_in_naira' => 1000,
            'exchange_rate_to_naira' => 1,
            'status' => TransactionStatus::SUCCESSFUL->value,
            'application_type' => TransactionApplicationType::INSTANT_DONATION->value,
            'gateway' => PaymentGateway::Fcmb->value,
            'gateway_reference' => 'INV-'.Str::upper(Str::random(6)),
            'donor_email' => 'donor-'.Str::lower(Str::random(6)).'@example.com',
            'donor_name' => 'Jane Donor',
            'is_anonymous' => false,
            'paid_at' => now(),
        ], $overrides));
    }
}
