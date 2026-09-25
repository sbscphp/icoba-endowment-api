<?php

namespace Tests\Feature\Public;

use App\Enums\CampaignStatus;
use App\Enums\PaymentGateway;
use App\Enums\PledgeStatus;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use App\Models\Country;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Tests\TestCase;

class HouseLeaderboardTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutMiddleware(ThrottleRequests::class);
        Country::query()->firstOrCreate(['iso2' => 'NG'], ['name' => 'Nigeria', 'dial_code' => '+234', 'is_active' => true]);
        $this->campaign = $this->createCampaign();
    }

    public function test_donations_scope_lists_every_house_ranked_by_paid_donations(): void
    {
        $parkerA = User::factory()->create(['house' => 'parker']);
        $parkerB = User::factory()->create(['house' => 'parker']);
        $townsend = User::factory()->create(['house' => 'townsend']);

        $this->createTransaction(['user_uuid' => $parkerA->uuid, 'amount' => 1000, 'amount_in_naira' => 1000]);
        $this->createTransaction(['user_uuid' => $parkerB->uuid, 'amount' => 500, 'amount_in_naira' => 500]);
        $this->createTransaction(['user_uuid' => $townsend->uuid, 'amount' => 3000, 'amount_in_naira' => 3000]);

        // Guest donor whose house lives only in the metadata snapshot.
        $this->createTransaction([
            'user_uuid' => null,
            'amount' => 250,
            'amount_in_naira' => 250,
            'donor_email' => 'guest@example.com',
            'metadata' => ['guest_donor_profile' => ['house' => 'aggrey']],
        ]);

        // Pledge instalment payments are excluded from the donations scope.
        $pledge = $this->createPledge(['user_uuid' => $parkerA->uuid, 'committed_amount' => 9000, 'committed_amount_ngn' => 9000]);
        $this->createTransaction(['user_uuid' => $parkerA->uuid, 'pledge_uuid' => $pledge->uuid, 'amount' => 9000, 'amount_in_naira' => 9000]);

        $response = $this->getJson('/api/v1/public/leaderboard/houses?scope=donations&page=1');

        $response->assertOk()
            ->assertJsonPath('error', false)
            ->assertJsonPath('data.total', 5)
            ->assertJsonCount(5, 'data.data')
            ->assertJsonPath('data.data.0.rank', 1)
            ->assertJsonPath('data.data.0.house.value', 'townsend')
            ->assertJsonPath('data.data.0.house.label', 'Townsend')
            ->assertJsonPath('data.data.0.total_amount', '3000.00')
            ->assertJsonPath('data.data.0.amount_in_ngn', '3000.00')
            ->assertJsonPath('data.data.0.house.contributing_donors', 1)
            ->assertJsonPath('data.data.0.house.registered_members', 1)
            ->assertJsonPath('data.data.0.currency', 'NGN')
            ->assertJsonPath('data.data.1.house.value', 'parker')
            ->assertJsonPath('data.data.1.total_amount', '1500.00')
            ->assertJsonPath('data.data.1.house.contributing_donors', 2)
            ->assertJsonPath('data.data.1.house.registered_members', 2)
            ->assertJsonPath('data.data.2.house.value', 'aggrey')
            ->assertJsonPath('data.data.2.total_amount', '250.00')
            ->assertJsonPath('data.data.2.house.registered_members', 0)
            ->assertJsonPath('data.data.3.house.value', 'oluwole')
            ->assertJsonPath('data.data.3.total_amount', '0.00')
            ->assertJsonPath('data.data.4.house.value', 'freeman')
            ->assertJsonPath('data.data.4.rank', 5)
            ->assertJsonMissingPath('data.data.0.fulfilled_amount');
    }

    public function test_pledges_scope_ranks_by_committed_amount_and_reports_fulfilled_totals(): void
    {
        $parker = User::factory()->create(['house' => 'parker']);
        $freeman = User::factory()->create(['house' => 'freeman']);

        $parkerPledge = $this->createPledge(['user_uuid' => $parker->uuid, 'committed_amount' => 5000, 'committed_amount_ngn' => 5000]);
        $this->createTransaction(['user_uuid' => $parker->uuid, 'pledge_uuid' => $parkerPledge->uuid, 'amount' => 2000, 'amount_in_naira' => 2000]);

        $this->createPledge(['user_uuid' => $freeman->uuid, 'committed_amount' => 8000, 'committed_amount_ngn' => 8000]);

        // Cancelled pledges are ignored entirely.
        $this->createPledge(['user_uuid' => $freeman->uuid, 'committed_amount' => 99000, 'committed_amount_ngn' => 99000, 'status' => PledgeStatus::CANCELLED]);

        // A direct donation never counts toward the pledges scope.
        $this->createTransaction(['user_uuid' => $parker->uuid, 'amount' => 70000, 'amount_in_naira' => 70000]);

        $response = $this->getJson('/api/v1/public/leaderboard/houses?scope=pledges');

        $response->assertOk()
            ->assertJsonCount(5, 'data.data')
            ->assertJsonPath('data.data.0.house.value', 'freeman')
            ->assertJsonPath('data.data.0.total_amount', '8000.00')
            ->assertJsonPath('data.data.0.fulfilled_amount', '0.00')
            ->assertJsonPath('data.data.1.house.value', 'parker')
            ->assertJsonPath('data.data.1.total_amount', '5000.00')
            ->assertJsonPath('data.data.1.fulfilled_amount', '2000.00')
            ->assertJsonPath('data.data.1.fulfilled_amount_ngn', '2000.00')
            ->assertJsonPath('data.data.2.total_amount', '0.00');
    }

    public function test_supports_search_house_sort_and_pagination(): void
    {
        $this->getJson('/api/v1/public/leaderboard/houses?scope=donations&search=free')
            ->assertOk()
            ->assertJsonPath('data.total', 1)
            ->assertJsonPath('data.data.0.house.value', 'freeman');

        $this->getJson('/api/v1/public/leaderboard/houses?sort_by=house&sort_dir=asc&per_page=2&page=2')
            ->assertOk()
            ->assertJsonPath('data.total', 5)
            ->assertJsonPath('data.last_page', 3)
            ->assertJsonCount(2, 'data.data')
            ->assertJsonPath('data.data.0.rank', 3)
            ->assertJsonPath('data.data.0.house.value', 'oluwole')
            ->assertJsonPath('data.data.1.house.value', 'aggrey');
    }

    public function test_top_houses_returns_both_scopes(): void
    {
        $parker = User::factory()->create(['house' => 'parker']);
        $oluwole = User::factory()->create(['house' => 'oluwole']);

        $this->createTransaction(['user_uuid' => $parker->uuid, 'amount' => 100, 'amount_in_naira' => 100]);
        $pledge = $this->createPledge(['user_uuid' => $oluwole->uuid, 'committed_amount' => 5000, 'committed_amount_ngn' => 5000]);
        $this->createTransaction(['user_uuid' => $oluwole->uuid, 'pledge_uuid' => $pledge->uuid, 'amount' => 400, 'amount_in_naira' => 400]);

        $this->getJson('/api/v1/public/leaderboard/top-houses?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data.donations')
            ->assertJsonCount(2, 'data.pledges')
            ->assertJsonPath('data.donations.0.house.value', 'parker')
            ->assertJsonPath('data.donations.0.total_amount', '100.00')
            ->assertJsonPath('data.pledges.0.house.value', 'oluwole')
            ->assertJsonPath('data.pledges.0.total_amount', '400.00');
    }

    private function createCampaign(): Campaign
    {
        return Campaign::query()->create([
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

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPledge(array $overrides = []): Pledge
    {
        return Pledge::query()->create(array_merge([
            'campaign_uuid' => $this->campaign->uuid,
            'donor_name' => 'Jane Donor',
            'donor_email' => 'pledger-'.Str::lower(Str::random(6)).'@example.com',
            'donor_phone' => '08030000001',
            'is_anonymous' => false,
            'committed_amount' => 1000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 1000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => 'monthly',
            'installment_count' => 1,
            'status' => PledgeStatus::ACTIVE,
        ], $overrides));
    }
}
