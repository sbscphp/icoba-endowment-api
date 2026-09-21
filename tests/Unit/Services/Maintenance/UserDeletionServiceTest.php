<?php

namespace Tests\Unit\Services\Maintenance;

use App\Enums\PledgeStatus;
use App\Enums\TransactionStatus;
use App\Enums\UserTypeEnum;
use App\Models\Campaign;
use App\Models\GivingIdentity;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UserDeletionServiceTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campaign = Campaign::query()->create([
            'uuid' => 'campaign-user-delete',
            'campaign_id' => 'CMP-UD1',
            'name' => 'Campaign',
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
    }

    public function test_deletes_the_user_with_everything_tied_to_the_account(): void
    {
        $user = User::factory()->create(['email' => 'gone@example.com']);
        $other = User::factory()->create(['email' => 'stays@example.com']);

        $identity = GivingIdentity::query()->create(['email_lower' => 'gone@example.com', 'user_uuid' => $user->uuid]);
        $pledge = $this->pledge('user-pledge', ['user_uuid' => $user->uuid, 'giving_identity_uuid' => $identity->uuid]);
        $this->transaction('TRN-USER', ['user_uuid' => $user->uuid, 'giving_identity_uuid' => $identity->uuid]);
        $this->transaction('TRN-ON-USER-PLEDGE', ['pledge_uuid' => $pledge->uuid]);
        $this->transaction('TRN-OTHER', ['user_uuid' => $other->uuid]);
        $user->createToken('api');
        DB::table('auth_challenges')->insert($this->challengeRow($user));

        $this->artisan('users:delete', ['uuid' => $user->uuid])->assertSuccessful();

        $this->assertDatabaseMissing('users', ['uuid' => $user->uuid]);
        $this->assertDatabaseMissing('giving_identities', ['uuid' => $identity->uuid]);
        $this->assertDatabaseMissing('pledges', ['uuid' => 'user-pledge']);
        $this->assertDatabaseMissing('transactions', ['transaction_id' => 'TRN-USER']);
        $this->assertDatabaseMissing('transactions', ['transaction_id' => 'TRN-ON-USER-PLEDGE']);
        $this->assertDatabaseMissing('auth_challenges', ['subject_id' => $user->uuid]);
        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->assertDatabaseHas('users', ['uuid' => $other->uuid]);
        $this->assertDatabaseHas('transactions', ['transaction_id' => 'TRN-OTHER']);
    }

    public function test_giving_identity_with_other_donations_is_kept_and_unlinked(): void
    {
        $user = User::factory()->create(['email' => 'guest-before@example.com']);
        $identity = GivingIdentity::query()->create(['email_lower' => 'guest-before@example.com', 'user_uuid' => $user->uuid]);
        $this->transaction('TRN-GUEST', ['giving_identity_uuid' => $identity->uuid]);

        $this->artisan('users:delete', ['uuid' => $user->uuid])->assertSuccessful();

        $this->assertDatabaseHas('giving_identities', ['uuid' => $identity->uuid, 'user_uuid' => null]);
        $this->assertDatabaseHas('transactions', ['transaction_id' => 'TRN-GUEST']);
    }

    public function test_dry_run_and_production_without_force_delete_nothing(): void
    {
        $user = User::factory()->create();
        $this->transaction('TRN-KEEP', ['user_uuid' => $user->uuid]);

        $this->artisan('users:delete', ['uuid' => $user->uuid, '--dry-run' => true])->assertSuccessful();
        $this->assertDatabaseHas('users', ['uuid' => $user->uuid]);

        $this->app['env'] = 'production';

        $this->artisan('users:delete', ['uuid' => $user->uuid])->assertFailed();
        $this->assertDatabaseHas('users', ['uuid' => $user->uuid]);
        $this->assertDatabaseHas('transactions', ['transaction_id' => 'TRN-KEEP']);

        $this->artisan('users:delete', ['uuid' => $user->uuid, '--force' => true])->assertSuccessful();
        $this->assertDatabaseMissing('users', ['uuid' => $user->uuid]);
        $this->assertDatabaseMissing('transactions', ['transaction_id' => 'TRN-KEEP']);
    }

    public function test_unknown_uuid_fails(): void
    {
        $this->artisan('users:delete', ['uuid' => 'does-not-exist'])->assertFailed();
    }

    /**
     * @return array<string, mixed>
     */
    private function challengeRow(User $user): array
    {
        return [
            'uuid' => 'challenge-user-delete',
            'subject_type' => UserTypeEnum::CUSTOMER->value,
            'subject_id' => $user->uuid,
            'purpose' => 'EMAIL_VERIFICATION',
            'code_hash' => 'hash',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function pledge(string $uuid, array $overrides = []): Pledge
    {
        return Pledge::query()->create(array_merge([
            'uuid' => $uuid,
            'campaign_uuid' => $this->campaign->uuid,
            'donor_name' => 'Jane Doe',
            'donor_email' => 'jane@example.com',
            'committed_amount' => 1000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 1000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => 'monthly',
            'installment_count' => 1,
            'status' => PledgeStatus::ACTIVE,
        ], $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function transaction(string $transactionId, array $overrides = []): Transaction
    {
        return Transaction::query()->create(array_merge([
            'transaction_id' => $transactionId,
            'campaign_uuid' => $this->campaign->uuid,
            'donor_name' => 'Jane Doe',
            'donor_email' => 'jane@example.com',
            'amount' => 100,
            'currency' => 'NGN',
            'amount_in_naira' => 100,
            'status' => TransactionStatus::SUCCESSFUL,
            'gateway' => 'paystack',
        ], $overrides));
    }
}
