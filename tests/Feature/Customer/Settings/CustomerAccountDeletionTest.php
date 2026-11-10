<?php

namespace Tests\Feature\Customer\Settings;

use App\Enums\AuditActionEnum;
use App\Enums\eRole;
use App\Enums\PledgeStatus;
use App\Enums\TransactionStatus;
use App\Enums\UserTypeEnum;
use App\Mail\AccountDeletedMail;
use App\Models\Campaign;
use App\Models\Country;
use App\Models\GivingIdentity;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Settings\CustomerAccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerAccountDeletionTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        Country::query()->create(['name' => 'Nigeria', 'iso2' => 'NG', 'dial_code' => '+234', 'is_active' => true]);
        $this->withoutMiddleware(ThrottleRequests::class);
        Role::firstOrCreate(['name' => eRole::CUSTOMER->value, 'guard_name' => 'api'], ['uuid' => (string) Str::uuid()]);

        $this->campaign = Campaign::query()->create([
            'uuid' => 'campaign-account-delete',
            'campaign_id' => 'CMP-AD1',
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

    public function test_requires_authentication(): void
    {
        $this->deleteJson('/api/v1/settings/profile')->assertUnauthorized();
    }

    public function test_deleting_closes_and_anonymises_the_account_but_keeps_the_ledger(): void
    {
        Mail::fake();

        $user = $this->customer([
            'firstname' => 'Jane',
            'lastname' => 'Doe',
            'email' => 'jane@example.com',
            'phone_number' => '08012345678',
            'stripe_customer_id' => 'cus_123',
            '2fa' => true,
        ]);
        $identity = GivingIdentity::query()->create(['email_lower' => 'jane@example.com', 'user_uuid' => $user->uuid]);
        $activePledge = $this->pledge('pledge-active', ['user_uuid' => $user->uuid, 'giving_identity_uuid' => $identity->uuid]);
        $fulfilledPledge = $this->pledge('pledge-fulfilled', ['user_uuid' => $user->uuid, 'status' => PledgeStatus::FULFILLED]);
        $this->transaction('TRN-JANE', ['user_uuid' => $user->uuid, 'giving_identity_uuid' => $identity->uuid]);
        $user->createToken('mobile');
        $user->createToken('web');
        DB::table('auth_challenges')->insert([
            'uuid' => 'challenge-account-delete',
            'subject_type' => UserTypeEnum::CUSTOMER->value,
            'subject_id' => $user->uuid,
            'purpose' => 'EMAIL_VERIFICATION',
            'code_hash' => 'hash',
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('password_reset_tokens')->insert(['email' => 'jane@example.com', 'token' => 'tok', 'created_at' => now()]);

        Sanctum::actingAs($user);

        $response = $this->deleteJson('/api/v1/settings/profile', ['reason' => 'No longer donating']);

        $response->assertOk();
        $this->assertFalse($response->json('error'));
        $this->assertSame('Your account has been deleted.', $response->json('message'));

        $user->refresh();
        $this->assertSame(CustomerAccountDeletionService::ANONYMISED_FIRSTNAME, $user->firstname);
        $this->assertSame(CustomerAccountDeletionService::ANONYMISED_LASTNAME, $user->lastname);
        $this->assertSame('deleted+'.$user->uuid.'@deleted.invalid', $user->email);
        $this->assertNull($user->phone_number);
        $this->assertNull($user->stripe_customer_id);
        $this->assertFalse((bool) $user->{'2fa'});
        $this->assertFalse((bool) $user->is_active);
        $this->assertFalse((bool) $user->can_login);
        $this->assertNotNull($user->account_closed_at);

        // Credentials and sessions are gone.
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->assertDatabaseMissing('auth_challenges', ['subject_id' => $user->uuid]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'jane@example.com']);

        // Active pledges are cancelled, others untouched; the ledger survives and stays linked.
        $activePledge->refresh();
        $this->assertSame(PledgeStatus::CANCELLED, $activePledge->status);
        $this->assertSame('account_deleted', data_get($activePledge->metadata, 'cancelled_reason'));
        $this->assertSame(PledgeStatus::FULFILLED, $fulfilledPledge->refresh()->status);
        $this->assertDatabaseHas('transactions', ['transaction_id' => 'TRN-JANE', 'user_uuid' => $user->uuid]);
        $this->assertDatabaseHas('giving_identities', ['uuid' => $identity->uuid, 'user_uuid' => $user->uuid, 'email_lower' => 'jane@example.com']);

        // The snapshot keeps who they were.
        $this->assertDatabaseHas('account_deletions', [
            'user_uuid' => $user->uuid,
            'email' => 'jane@example.com',
            'display_name' => 'Jane Doe',
            'reason' => 'No longer donating',
        ]);
        $deletion = DB::table('account_deletions')->where('user_uuid', $user->uuid)->first();
        $profile = json_decode((string) $deletion->profile, true);
        $summary = json_decode((string) $deletion->summary, true);
        $this->assertSame('Jane', $profile['firstname']);
        $this->assertSame('jane@example.com', $profile['email']);
        $this->assertSame('08012345678', $profile['phone_number']);
        $this->assertSame(1, $summary['pledges_cancelled']);
        $this->assertSame(2, $summary['tokens_revoked']);
        $this->assertSame(1, $summary['transactions_kept']);

        // Audit trail entry.
        $this->assertDatabaseHas('audit_logs', [
            'user_type' => UserTypeEnum::CUSTOMER->value,
            'user_id' => $user->uuid,
            'action' => AuditActionEnum::ACCOUNT_DELETED->value,
            'model_id' => $user->uuid,
        ]);

        Mail::assertSent(AccountDeletedMail::class, function (AccountDeletedMail $mail): bool {
            $html = $mail->render();

            return $mail->hasTo('jane@example.com')
                && $mail->pledgesCancelled === 1
                && str_contains($html, 'Goodbye, Jane Doe')
                && str_contains($html, 'Your active pledge has been cancelled');
        });
    }

    public function test_deleted_account_cannot_log_in_and_email_is_free_to_register_again(): void
    {
        Mail::fake();

        $user = $this->customer(['email' => 'jane@example.com']);
        Sanctum::actingAs($user);
        $this->postJson('/api/v1/settings/profile/delete')->assertOk();

        $this->assertNull(User::query()->where('email', 'jane@example.com')->first());

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => 'password'])
            ->assertStatus(400);
    }

    public function test_reason_longer_than_500_characters_is_rejected(): void
    {
        $user = $this->customer([]);
        Sanctum::actingAs($user);

        $this->deleteJson('/api/v1/settings/profile', ['reason' => str_repeat('x', 501)])
            ->assertStatus(422);

        $this->assertTrue((bool) $user->refresh()->is_active);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole(eRole::CUSTOMER->value);

        return $user;
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
