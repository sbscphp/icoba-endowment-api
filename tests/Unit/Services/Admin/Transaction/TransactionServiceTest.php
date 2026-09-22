<?php

namespace Tests\Unit\Services\Admin\Transaction;

use App\Enums\TransactionStatus;
use App\Http\Resources\Admin\TransactionListResource;
use App\Models\Campaign;
use App\Models\GivingIdentity;
use App\Models\GraduationSet;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Admin\Transaction\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class TransactionServiceTest extends TestCase
{
    use RefreshDatabase;

    private GraduationSet $set1990;

    private GraduationSet $set2001;

    private Campaign $campaign;

    /**
     * Alumni: own set 1990, house parker.
     */
    private User $alumni;

    /**
     * Wife of ICOBA: no own set, affiliated set 1990, house townsend.
     */
    private User $wife;

    /**
     * Guest corporate (no user): giving identity with house parker, affiliated set 2001.
     */
    private GivingIdentity $guestIdentity;

    protected function setUp(): void
    {
        parent::setUp();

        $this->set1990 = GraduationSet::query()->create([
            'public_id' => 'SET-1990',
            'name' => 'Class of 1990',
            'set_number' => '1990',
        ]);
        $this->set2001 = GraduationSet::query()->create([
            'public_id' => 'SET-2001',
            'name' => 'Class of 2001',
            'set_number' => '2001',
        ]);

        $this->campaign = Campaign::query()->create([
            'campaign_id' => 'CMP-TX-001',
            'name' => 'Transactions campaign',
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

        $this->alumni = User::factory()->create([
            'firstname' => 'Ade',
            'lastname' => 'Alumni',
            'graduation_set_uuid' => $this->set1990->uuid,
            'house' => 'parker',
        ]);

        $this->wife = User::factory()->create([
            'firstname' => 'Bola',
            'lastname' => 'Wife',
            'graduation_set_uuid' => null,
            'affiliated_graduation_set_uuid' => $this->set1990->uuid,
            'house' => 'townsend',
        ]);

        $this->guestIdentity = GivingIdentity::query()->create([
            'email_lower' => 'guest-corp@example.com',
            'organization_name' => 'Guest Corp',
            'house' => 'parker',
            'affiliated_graduation_set_uuid' => $this->set2001->uuid,
            'is_igbobian_owned' => true,
        ]);

        $this->createTransaction('TRN-ALUMNI', ['user_uuid' => $this->alumni->uuid]);
        $this->createTransaction('TRN-WIFE', ['user_uuid' => $this->wife->uuid]);
        $this->createTransaction('TRN-GUEST', [
            'user_uuid' => null,
            'giving_identity_uuid' => $this->guestIdentity->uuid,
            'donor_name' => 'Guest Corp',
            'donor_email' => 'guest-corp@example.com',
        ]);
    }

    public function test_house_filter_matches_donor_or_giving_identity_house(): void
    {
        $ids = $this->listIds(['filters' => ['house' => 'parker']]);

        $this->assertSame(['TRN-ALUMNI', 'TRN-GUEST'], $ids);

        $this->assertSame(['TRN-WIFE'], $this->listIds(['filters' => ['house' => 'townsend']]));
        $this->assertSame([], $this->listIds(['filters' => ['house' => 'freeman']]));
    }

    public function test_affiliated_set_filter_matches_donor_or_giving_identity_affiliated_set(): void
    {
        $this->assertSame(
            ['TRN-WIFE'],
            $this->listIds(['filters' => ['affiliated_graduation_set_uuid' => $this->set1990->uuid]]),
        );

        $this->assertSame(
            ['TRN-GUEST'],
            $this->listIds(['filters' => ['affiliated_graduation_set_uuid' => $this->set2001->uuid]]),
        );
    }

    public function test_graduation_set_filter_stays_own_set_only_and_ignores_affiliated_set(): void
    {
        $ids = $this->listIds(['filters' => ['graduation_set_uuid' => $this->set1990->uuid]]);

        $this->assertSame(['TRN-ALUMNI'], $ids);
        $this->assertNotContains('TRN-WIFE', $ids);
    }

    public function test_list_resource_exposes_set_affiliated_set_house_and_igbobian_flag(): void
    {
        $rows = collect(app(TransactionService::class)->list(['per_page' => 50])->items())
            ->keyBy('transaction_id');

        $alumni = TransactionListResource::make($rows['TRN-ALUMNI'])->resolve();
        $this->assertSame('1990', $alumni['set']['set_number']);
        $this->assertNull($alumni['affiliated_set']);
        $this->assertSame(['value' => 'parker', 'label' => 'Parker'], $alumni['house']);
        $this->assertFalse($alumni['is_igbobian_owned']);

        $wife = TransactionListResource::make($rows['TRN-WIFE'])->resolve();
        $this->assertNull($wife['set']);
        $this->assertSame('1990', $wife['affiliated_set']['set_number']);
        $this->assertSame('townsend', $wife['house']['value']);

        $guest = TransactionListResource::make($rows['TRN-GUEST'])->resolve();
        $this->assertNull($guest['set']);
        $this->assertSame('2001', $guest['affiliated_set']['set_number']);
        $this->assertSame('parker', $guest['house']['value']);
        $this->assertTrue($guest['is_igbobian_owned']);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return list<string>
     */
    private function listIds(array $validated): array
    {
        $paginator = app(TransactionService::class)->list(array_merge(['per_page' => 50], $validated));

        return collect($paginator->items())->pluck('transaction_id')->sort()->values()->all();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createTransaction(string $transactionId, array $overrides): Transaction
    {
        return Transaction::query()->create(array_merge([
            'transaction_id' => $transactionId,
            'campaign_uuid' => $this->campaign->uuid,
            'donor_name' => 'Donor',
            'donor_email' => strtolower($transactionId).'@example.com',
            'donor_phone' => '08030000000',
            'amount' => 100,
            'currency' => 'NGN',
            'amount_in_naira' => 100,
            'status' => TransactionStatus::SUCCESSFUL,
            'gateway' => 'paystack',
        ], $overrides));
    }
}
