<?php

namespace Tests\Feature\Admin\Dashboard;

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

final class DashboardOverviewServiceFeeTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/admin/dashboard/overview';

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware([ThrottleRequests::class, PermissionMiddleware::class]);
        Country::query()->firstOrCreate(['iso2' => 'NG'], ['name' => 'Nigeria', 'dial_code' => '+234', 'is_active' => true]);

        $admin = Admin::query()->create([
            'name' => 'Dashboard Admin',
            'email' => 'dashboard-admin@example.com',
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
    }

    public function test_overview_reports_donation_total_separately_from_service_fee(): void
    {
        // NGN donation with a fee.
        $this->createTransaction([
            'amount' => 1000,
            'amount_in_naira' => 1000,
            'fee_amount' => 30,
            'total_amount' => 1030,
            'fee_amount_in_naira' => 30,
        ]);
        // USD donation with a fee, converted at 1500.
        $this->createTransaction([
            'currency' => 'USD',
            'amount' => 10,
            'amount_in_naira' => 15000,
            'exchange_rate_to_naira' => 1500,
            'fee_amount' => 0.5,
            'total_amount' => 10.5,
            'fee_amount_in_naira' => 750,
        ]);
        // Row created before the fee feature: fee_amount_in_naira is null and must count as 0.
        $this->createTransaction([
            'amount' => 500,
            'amount_in_naira' => 500,
            'fee_amount' => 0,
            'total_amount' => null,
            'fee_amount_in_naira' => null,
        ]);
        // Failed transaction must not count anywhere.
        $this->createTransaction([
            'amount' => 9999,
            'amount_in_naira' => 9999,
            'fee_amount' => 999,
            'fee_amount_in_naira' => 999,
            'status' => TransactionStatus::FAILED->value,
        ]);

        $this->getJson(self::URL)
            ->assertOk()
            ->assertJsonPath('data.currency_filter_applied', false)
            ->assertJsonPath('data.total_fund_raised', '16500')
            ->assertJsonPath('data.total_fund_raised_including_service_fee', '17280')
            ->assertJsonPath('data.total_service_fee_raised', '780')
            // No date window means no comparison period, so the previous total is zero.
            ->assertJsonPath('data.total_service_fee_raised_change_percentage', 100)
            ->assertJsonPath('data.total_service_fee_raised_change_direction', 'increase')
            ->assertJsonPath('data.total_fund_raised_including_service_fee_change_direction', 'increase');
    }

    public function test_currency_filter_sums_native_service_fee(): void
    {
        $this->createTransaction([
            'amount' => 1000,
            'amount_in_naira' => 1000,
            'fee_amount' => 30,
            'total_amount' => 1030,
            'fee_amount_in_naira' => 30,
        ]);
        $this->createTransaction([
            'currency' => 'USD',
            'amount' => 10,
            'amount_in_naira' => 15000,
            'exchange_rate_to_naira' => 1500,
            'fee_amount' => 0.5,
            'total_amount' => 10.5,
            'fee_amount_in_naira' => 750,
        ]);

        $this->getJson(self::URL.'?currency=usd')
            ->assertOk()
            ->assertJsonPath('data.currency', 'USD')
            ->assertJsonPath('data.currency_filter_applied', true)
            ->assertJsonPath('data.total_fund_raised', '10')
            ->assertJsonPath('data.total_fund_raised_including_service_fee', '10.5')
            ->assertJsonPath('data.total_service_fee_raised', '0.5');
    }

    public function test_service_fee_change_is_compared_against_previous_period(): void
    {
        $this->createTransaction([
            'amount' => 1000,
            'amount_in_naira' => 1000,
            'fee_amount' => 40,
            'fee_amount_in_naira' => 40,
        ]);
        $this->createTransaction([
            'amount' => 1000,
            'amount_in_naira' => 1000,
            'fee_amount' => 20,
            'fee_amount_in_naira' => 20,
            'created_at' => now()->subDays(10),
            'paid_at' => now()->subDays(10),
        ]);

        $this->getJson(self::URL.'?period=7days')
            ->assertOk()
            ->assertJsonPath('data.total_service_fee_raised', '40')
            ->assertJsonPath('data.total_fund_raised_including_service_fee', '1040')
            ->assertJsonPath('data.total_fund_raised_including_service_fee_change_percentage', 2)
            ->assertJsonPath('data.total_fund_raised_including_service_fee_change_direction', 'increase')
            ->assertJsonPath('data.total_service_fee_raised_change_percentage', 100)
            ->assertJsonPath('data.total_service_fee_raised_change_direction', 'increase');
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
