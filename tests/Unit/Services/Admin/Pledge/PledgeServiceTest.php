<?php

namespace Tests\Unit\Services\Admin\Pledge;

use App\Enums\PledgeStatus;
use App\Enums\TransactionStatus;
use App\Http\Resources\PledgeListResource;
use App\Models\Campaign;
use App\Models\GivingIdentity;
use App\Models\GraduationSet;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\Pledge\PledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PledgeServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_stats_include_total_values_for_pledge_value_fulfilled_and_active(): void
    {
        $campaign = Campaign::query()->create([
            'uuid' => 'campaign-pledge-stats',
            'campaign_id' => 'CMP-001',
            'name' => 'Campaign 1',
            'short_description' => 'desc',
            'long_description' => 'desc',
            'categories' => ['general'],
            'base_currency' => 'NGN',
            'available_donation_currencies' => ['NGN'],
            'target_amount' => 10000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'allow_anonymous_donation' => true,
            'allow_public_donation' => true,
            'applies_to_all_graduation_sets' => true,
            'status' => 'active',
        ]);

        $activePledge = Pledge::query()->create([
            'uuid' => 'pledge-active-1',
            'campaign_uuid' => $campaign->uuid,
            'user_uuid' => null,
            'donor_name' => 'Jane Doe',
            'donor_email' => 'jane@example.com',
            'donor_phone' => '08030000001',
            'is_anonymous' => false,
            'committed_amount' => 1000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 1000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => 'monthly',
            'installment_count' => 1,
            'status' => PledgeStatus::ACTIVE,
        ]);

        $fulfilledPledge = Pledge::query()->create([
            'uuid' => 'pledge-fulfilled-1',
            'campaign_uuid' => $campaign->uuid,
            'user_uuid' => null,
            'donor_name' => 'John Doe',
            'donor_email' => 'john@example.com',
            'donor_phone' => '08030000002',
            'is_anonymous' => false,
            'committed_amount' => 2000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 2000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => 'monthly',
            'installment_count' => 1,
            'status' => PledgeStatus::FULFILLED,
        ]);

        Transaction::query()->create([
            'transaction_id' => 'TRN-ACTIVE-1',
            'uuid' => 'txn-active-1',
            'campaign_uuid' => $campaign->uuid,
            'pledge_uuid' => $activePledge->uuid,
            'user_uuid' => null,
            'donor_name' => 'Jane Doe',
            'donor_email' => 'jane@example.com',
            'donor_phone' => '08030000001',
            'amount' => 400,
            'currency' => 'NGN',
            'amount_in_naira' => 400,
            'status' => TransactionStatus::SUCCESSFUL,
            'gateway' => 'paystack',
        ]);

        Transaction::query()->create([
            'transaction_id' => 'TRN-FULFILLED-1',
            'uuid' => 'txn-fulfilled-1',
            'campaign_uuid' => $campaign->uuid,
            'pledge_uuid' => $fulfilledPledge->uuid,
            'user_uuid' => null,
            'donor_name' => 'John Doe',
            'donor_email' => 'john@example.com',
            'donor_phone' => '08030000002',
            'amount' => 2000,
            'currency' => 'NGN',
            'amount_in_naira' => 2000,
            'status' => TransactionStatus::SUCCESSFUL,
            'gateway' => 'paystack',
        ]);

        $service = app(PledgeService::class);
        $stats = $service->stats([]);

        $this->assertSame('3000.00', $stats['total_pledge_value']);
        $this->assertSame('2400.00', $stats['total_fulfilled']);
        $this->assertSame('600.00', $stats['total_active']);
    }

    public function test_pledge_list_resource_includes_amount_aliases(): void
    {
        $pledge = new Pledge([
            'uuid' => 'pledge-list-resource-1',
            'campaign_uuid' => 'campaign-uuid-1',
            'user_uuid' => null,
            'donor_name' => 'Jane Doe',
            'donor_email' => 'jane@example.com',
            'donor_phone' => '08030000001',
            'is_anonymous' => false,
            'committed_amount' => 1500,
            'committed_amount_ngn' => 1500,
            'currency' => 'NGN',
            'payment_plan_type' => 'monthly',
            'installment_count' => 2,
            'status' => PledgeStatus::ACTIVE,
        ]);

        $pledge->setAttribute('fulfilled_amount', '450.00');
        $pledge->setAttribute('remaining_amount', '1050.00');

        $payload = PledgeListResource::make($pledge)->resolve();

        $this->assertSame('1500.00', $payload['amount_pledged']);
        $this->assertSame('450.00', $payload['amount_fulfilled']);
        $this->assertSame('1050.00', $payload['amount_pending']);
    }

    public function test_house_filter_matches_donor_or_giving_identity_house_in_list_and_stats(): void
    {
        $fixture = $this->seedAffiliationFixture();
        $service = app(PledgeService::class);

        $this->assertSame(['Ade Alumni', 'Guest Corp'], $this->pledgeDonorNames($service, ['house' => 'parker']));
        $this->assertSame(['Bola Wife'], $this->pledgeDonorNames($service, ['house' => 'townsend']));
        $this->assertSame([], $this->pledgeDonorNames($service, ['house' => 'freeman']));

        // Stats share the same filters: alumni (100) + guest (300).
        $stats = $service->stats(['filters' => ['house' => 'parker']]);
        $this->assertSame('400.00', $stats['total_pledge_value']);
    }

    public function test_affiliated_set_filter_matches_donor_or_giving_identity_affiliated_set(): void
    {
        $fixture = $this->seedAffiliationFixture();
        $service = app(PledgeService::class);

        $this->assertSame(
            ['Bola Wife'],
            $this->pledgeDonorNames($service, ['affiliated_graduation_set_uuid' => $fixture['set1990']->uuid]),
        );
        $this->assertSame(
            ['Guest Corp'],
            $this->pledgeDonorNames($service, ['affiliated_graduation_set_uuid' => $fixture['set2001']->uuid]),
        );

        $stats = $service->stats(['filters' => ['affiliated_graduation_set_uuid' => $fixture['set1990']->uuid]]);
        $this->assertSame('200.00', $stats['total_pledge_value']);
    }

    public function test_graduation_set_filter_stays_own_set_only_and_ignores_affiliated_set(): void
    {
        $fixture = $this->seedAffiliationFixture();
        $service = app(PledgeService::class);

        $ids = $this->pledgeDonorNames($service, ['graduation_set_uuid' => $fixture['set1990']->uuid]);

        $this->assertSame(['Ade Alumni'], $ids);
        $this->assertNotContains('Bola Wife', $ids);
    }

    public function test_pledge_list_resource_exposes_affiliated_set_house_and_igbobian_flag(): void
    {
        $fixture = $this->seedAffiliationFixture();
        $rows = collect(app(PledgeService::class)->list(['per_page' => 50])->items())->keyBy('donor_name');

        $wife = PledgeListResource::make($rows['Bola Wife'])->resolve();
        $this->assertSame('1990', $wife['donor']['affiliated_set']['set_number']);
        $this->assertSame(['value' => 'townsend', 'label' => 'Townsend'], $wife['donor']['house']);
        $this->assertFalse($wife['donor']['is_igbobian_owned']);

        $guest = PledgeListResource::make($rows['Guest Corp'])->resolve();
        $this->assertSame('2001', $guest['donor']['affiliated_set']['set_number']);
        $this->assertSame('parker', $guest['donor']['house']['value']);
        $this->assertTrue($guest['donor']['is_igbobian_owned']);

        $alumni = PledgeListResource::make($rows['Ade Alumni'])->resolve();
        $this->assertNull($alumni['donor']['affiliated_set']);
        $this->assertSame('parker', $alumni['donor']['house']['value']);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return list<string>
     */
    private function pledgeDonorNames(PledgeService $service, array $filters): array
    {
        $paginator = $service->list(['per_page' => 50, 'filters' => $filters]);

        return collect($paginator->items())->pluck('donor_name')->sort()->values()->all();
    }

    /**
     * Alumni (own set 1990, parker) = 100, wife (affiliated 1990, townsend) = 200,
     * guest corporate identity (affiliated 2001, parker, Igbobian-owned) = 300.
     *
     * @return array{set1990: GraduationSet, set2001: GraduationSet}
     */
    private function seedAffiliationFixture(): array
    {
        $set1990 = GraduationSet::query()->create([
            'public_id' => 'SET-1990',
            'name' => 'Class of 1990',
            'set_number' => '1990',
        ]);
        $set2001 = GraduationSet::query()->create([
            'public_id' => 'SET-2001',
            'name' => 'Class of 2001',
            'set_number' => '2001',
        ]);

        $campaign = Campaign::query()->create([
            'uuid' => 'campaign-affiliation',
            'campaign_id' => 'CMP-AFF',
            'name' => 'Affiliation campaign',
            'short_description' => 'desc',
            'long_description' => 'desc',
            'categories' => ['general'],
            'base_currency' => 'NGN',
            'available_donation_currencies' => ['NGN'],
            'target_amount' => 10000,
            'start_date' => '2026-01-01',
            'end_date' => '2026-12-31',
            'allow_anonymous_donation' => true,
            'allow_public_donation' => true,
            'applies_to_all_graduation_sets' => true,
            'status' => 'active',
        ]);

        $alumni = User::factory()->create([
            'graduation_set_uuid' => $set1990->uuid,
            'house' => 'parker',
        ]);
        $wife = User::factory()->create([
            'graduation_set_uuid' => null,
            'affiliated_graduation_set_uuid' => $set1990->uuid,
            'house' => 'townsend',
        ]);
        $guestIdentity = GivingIdentity::query()->create([
            'email_lower' => 'guest-corp@example.com',
            'organization_name' => 'Guest Corp',
            'house' => 'parker',
            'affiliated_graduation_set_uuid' => $set2001->uuid,
            'is_igbobian_owned' => true,
        ]);

        $base = [
            'campaign_uuid' => $campaign->uuid,
            'donor_phone' => '08030000000',
            'is_anonymous' => false,
            'currency' => 'NGN',
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => 'monthly',
            'installment_count' => 1,
            'status' => PledgeStatus::ACTIVE,
        ];

        Pledge::query()->create(array_merge($base, [
            'user_uuid' => $alumni->uuid,
            'donor_name' => 'Ade Alumni',
            'donor_email' => $alumni->email,
            'committed_amount' => 100,
            'committed_amount_ngn' => 100,
        ]));
        Pledge::query()->create(array_merge($base, [
            'user_uuid' => $wife->uuid,
            'donor_name' => 'Bola Wife',
            'donor_email' => $wife->email,
            'committed_amount' => 200,
            'committed_amount_ngn' => 200,
        ]));
        Pledge::query()->create(array_merge($base, [
            'user_uuid' => null,
            'giving_identity_uuid' => $guestIdentity->uuid,
            'donor_name' => 'Guest Corp',
            'donor_email' => 'guest-corp@example.com',
            'committed_amount' => 300,
            'committed_amount_ngn' => 300,
        ]));

        return ['set1990' => $set1990, 'set2001' => $set2001];
    }
}
