<?php

namespace Tests\Unit\Services\Reconciliation;

use App\Enums\CampaignStatus;
use App\Enums\DonorTypeSlug;
use App\Enums\eRole;
use App\Enums\GivingIdentitySource;
use App\Enums\GivingIdentityStatus;
use App\Enums\PledgeStatus;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Admin;
use App\Models\Campaign;
use App\Models\CorporateCategory;
use App\Models\Country;
use App\Models\DonorType;
use App\Models\GivingIdentity;
use App\Models\GraduationSet;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Reconciliation\DonationReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DonationReconciliationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_manual_captures_affiliation_for_new_corporate_donor(): void
    {
        [$set, $category, $adminUuid] = $this->seedDonorReferenceData();
        $campaign = $this->createCampaign(['NGN']);

        $transaction = app(DonationReconciliationService::class)->createManual([
            'amount' => 5000,
            'reference_id' => 'REF-'.Str::upper(Str::random(8)),
            'bank_key' => 'fcmb_ngn',
            'narration' => 'Corporate donation',
            'campaign_uuid' => $campaign->uuid,
            'donor_type' => DonorTypeSlug::CORPORATE_DONOR->value,
            'donor_email' => 'corp@example.com',
            'donor_phone' => '+2348012345678',
            'country_code' => '+234',
            'organization_name' => 'Acme Ltd',
            'corporate_category_uuid' => $category->uuid,
            'rc_number' => 'RC123456',
            'tin' => 'TIN123456',
            'is_igbobian_owned' => true,
            'affiliated_set_number' => '2002',
            'house' => 'parker',
        ], $adminUuid);

        $user = User::query()->where('email', 'corp@example.com')->firstOrFail();
        $this->assertTrue((bool) $user->is_igbobian_owned);
        $this->assertSame($set->uuid, $user->affiliated_graduation_set_uuid);
        $this->assertSame('parker', $user->house);

        $identity = GivingIdentity::query()->where('user_uuid', $user->uuid)->firstOrFail();
        $this->assertTrue((bool) $identity->is_igbobian_owned);
        $this->assertSame($set->uuid, $identity->affiliated_graduation_set_uuid);
        $this->assertSame('parker', $identity->house);

        $draft = $transaction->metadata['reconciliation_draft'] ?? [];
        $this->assertTrue($draft['is_igbobian_owned'] ?? null);
        $this->assertSame('2002', $draft['affiliated_set_number'] ?? null);
        $this->assertSame('parker', $draft['house'] ?? null);
    }

    public function test_create_manual_uses_admin_payment_date_and_records_wives_type(): void
    {
        [$set, , $adminUuid] = $this->seedDonorReferenceData();
        $campaign = $this->createCampaign(['NGN']);

        $transaction = app(DonationReconciliationService::class)->createManual([
            'amount' => 5000,
            'reference_id' => 'REF-'.Str::upper(Str::random(8)),
            'bank_key' => 'fcmb_ngn',
            'narration' => 'Late FCMB transfer',
            'payment_date' => '2026-09-10',
            'campaign_uuid' => $campaign->uuid,
            'donor_type' => DonorTypeSlug::WIVES_OF_ICOBA->value,
            'donor_email' => 'wife@example.com',
            'donor_phone' => '+2348012345679',
            'country_code' => '+234',
            'firstname' => 'Bisi',
            'lastname' => 'Ola',
            'wives_type' => 'wives_of_icoba_europe',
            'affiliated_set_number' => '2002',
        ], $adminUuid);

        $this->assertSame('2026-09-10', $transaction->paid_at?->toDateString());
        $this->assertSame('2026-09-10', substr((string) ($transaction->metadata['bank_transaction_date'] ?? ''), 0, 10));
        $this->assertTrue($transaction->metadata['payment_date_set_by_admin'] ?? false);
        $this->assertSame('wives_of_icoba_europe', $transaction->metadata['reconciliation_draft']['wives_type'] ?? null);

        $user = User::query()->where('email', 'wife@example.com')->firstOrFail();
        $this->assertSame('wives_of_icoba_europe', $user->wives_type);
        $this->assertSame($set->uuid, $user->affiliated_graduation_set_uuid);

        $identity = GivingIdentity::query()->where('user_uuid', $user->uuid)->firstOrFail();
        $this->assertSame('wives_of_icoba_europe', $identity->wives_type);
    }

    public function test_create_manual_defaults_payment_date_to_now(): void
    {
        [, , $adminUuid] = $this->seedDonorReferenceData();
        $campaign = $this->createCampaign(['NGN']);

        $transaction = app(DonationReconciliationService::class)->createManual([
            'amount' => 5000,
            'reference_id' => 'REF-'.Str::upper(Str::random(8)),
            'bank_key' => 'fcmb_ngn',
            'narration' => 'Transfer',
            'campaign_uuid' => $campaign->uuid,
        ], $adminUuid);

        $this->assertNotNull($transaction->paid_at);
        $this->assertTrue($transaction->paid_at->isSameDay(now()));
        $this->assertArrayNotHasKey('payment_date_set_by_admin', $transaction->metadata ?? []);
    }

    public function test_complete_manual_overrides_payment_date(): void
    {
        [, , $adminUuid] = $this->seedDonorReferenceData();
        $campaign = $this->createCampaign(['NGN']);
        $user = User::factory()->create([
            'donor_type_uuid' => DonorType::query()->where('slug', DonorTypeSlug::FRIENDS_OF_ICOBA->value)->value('uuid'),
        ]);
        $originalPaidAt = now()->subDays(2)->startOfSecond();
        $transaction = $this->createPendingBankTransfer([
            'campaign_uuid' => $campaign->uuid,
            'paid_at' => $originalPaidAt,
            'metadata' => ['source' => 'admin_manual', 'bank_transaction_date' => $originalPaidAt->toIso8601String()],
        ]);

        $finalized = app(DonationReconciliationService::class)->completeManual($transaction, [
            'user_uuid' => $user->uuid,
            'campaign_uuid' => $campaign->uuid,
            'payment_date' => '2026-09-05 14:30:00',
        ], $adminUuid);

        $this->assertSame(TransactionStatus::SUCCESSFUL, $finalized->status);
        $this->assertSame('2026-09-05 14:30:00', $finalized->paid_at?->format('Y-m-d H:i:s'));
        $this->assertSame('2026-09-05', substr((string) ($finalized->metadata['bank_transaction_date'] ?? ''), 0, 10));
        $this->assertTrue($finalized->metadata['payment_date_set_by_admin'] ?? false);
        $this->assertSame($originalPaidAt->toIso8601String(), $finalized->metadata['payment_date_previous'] ?? null);
    }

    public function test_complete_manual_without_payment_date_keeps_existing_paid_at(): void
    {
        [, , $adminUuid] = $this->seedDonorReferenceData();
        $campaign = $this->createCampaign(['NGN']);
        $user = User::factory()->create([
            'donor_type_uuid' => DonorType::query()->where('slug', DonorTypeSlug::FRIENDS_OF_ICOBA->value)->value('uuid'),
        ]);
        $originalPaidAt = now()->subDays(2)->startOfSecond();
        $transaction = $this->createPendingBankTransfer([
            'campaign_uuid' => $campaign->uuid,
            'paid_at' => $originalPaidAt,
        ]);

        $finalized = app(DonationReconciliationService::class)->completeManual($transaction, [
            'user_uuid' => $user->uuid,
            'campaign_uuid' => $campaign->uuid,
        ], $adminUuid);

        $this->assertTrue($finalized->paid_at?->equalTo($originalPaidAt));
        $this->assertArrayNotHasKey('payment_date_set_by_admin', $finalized->metadata ?? []);
    }

    public function test_create_manual_from_guest_identity_carries_affiliation_to_new_user(): void
    {
        [$set, , $adminUuid] = $this->seedDonorReferenceData();
        $campaign = $this->createCampaign(['NGN']);
        $donorType = DonorType::query()->where('slug', DonorTypeSlug::FRIENDS_OF_ICOBA->value)->firstOrFail();

        $identity = GivingIdentity::query()->create([
            'email_lower' => 'friend@example.com',
            'donor_type_uuid' => $donorType->uuid,
            'firstname' => 'Ada',
            'lastname' => 'Obi',
            'house' => 'townsend',
            'affiliated_graduation_set_uuid' => $set->uuid,
            'is_igbobian_owned' => false,
            'status' => GivingIdentityStatus::ACTIVE,
            'source' => GivingIdentitySource::GUEST_CHECKOUT,
        ]);

        app(DonationReconciliationService::class)->createManual([
            'amount' => 2500,
            'reference_id' => 'REF-'.Str::upper(Str::random(8)),
            'bank_key' => 'fcmb_ngn',
            'narration' => 'Friend donation',
            'campaign_uuid' => $campaign->uuid,
            'user_identity' => $identity->uuid,
        ], $adminUuid);

        $user = User::query()->where('email', 'friend@example.com')->firstOrFail();
        $this->assertSame('townsend', $user->house);
        $this->assertSame($set->uuid, $user->affiliated_graduation_set_uuid);
        $this->assertFalse((bool) $user->is_igbobian_owned);
        $this->assertSame($user->uuid, $identity->refresh()->user_uuid);
    }

    public function test_create_manual_sets_gateway_reference_from_reference_id(): void
    {
        $campaign = $this->createCampaign(['NGN']);
        $referenceId = 'FCMB-REF-'.Str::upper(Str::random(8));

        $transaction = app(DonationReconciliationService::class)->createManual([
            'amount' => 5000,
            'reference_id' => $referenceId,
            'bank_key' => 'fcmb_ngn',
            'narration' => 'Corporate donation',
            'campaign_uuid' => $campaign->uuid,
        ], Str::uuid()->toString());

        $this->assertSame($referenceId, $transaction->gateway_reference);
        $this->assertSame($referenceId, $transaction->fcmb_statement_reference);
    }

    public function test_create_manual_rejects_unsupported_bank_currency_for_campaign(): void
    {
        $campaign = $this->createCampaign(['NGN']);

        $this->expectException(ValidationException::class);

        try {
            app(DonationReconciliationService::class)->createManual([
                'amount' => 500,
                'reference_id' => 'REF-'.Str::upper(Str::random(8)),
                'bank_key' => 'fcmb_usd',
                'narration' => 'Corporate donation',
                'campaign_uuid' => $campaign->uuid,
            ], Str::uuid()->toString());
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('bank_key', $exception->errors());
            throw $exception;
        }
    }

    public function test_update_bank_account_rejects_unsupported_currency_for_linked_campaign(): void
    {
        $campaign = $this->createCampaign(['NGN']);
        $transaction = $this->createPendingBankTransfer([
            'campaign_uuid' => $campaign->uuid,
            'currency' => 'NGN',
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(DonationReconciliationService::class)->updateBankAccount($transaction, [
                'paid_into_account_key' => 'fcmb_usd',
            ]);
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('paid_into_account_number', $exception->errors());
            throw $exception;
        }
    }

    public function test_complete_manual_rejects_campaign_when_transaction_currency_is_not_allowed(): void
    {
        $usdCampaign = $this->createCampaign(['USD', 'NGN']);
        $ngnOnlyCampaign = $this->createCampaign(['NGN']);
        $transaction = $this->createPendingBankTransfer([
            'campaign_uuid' => $usdCampaign->uuid,
            'currency' => 'USD',
            'amount' => 100,
            'amount_in_naira' => 150000,
            'exchange_rate_to_naira' => 1500,
        ]);

        $this->expectException(ValidationException::class);

        try {
            app(DonationReconciliationService::class)->completeManual($transaction, [
                'campaign_uuid' => $ngnOnlyCampaign->uuid,
            ], Str::uuid()->toString());
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('campaign_uuid', $exception->errors());
            throw $exception;
        }
    }

    public function test_create_manual_stores_normalized_purpose_in_column_and_draft(): void
    {
        [, , $adminUuid] = $this->seedDonorReferenceData();
        $campaign = $this->createCampaign(['NGN']);

        $transaction = app(DonationReconciliationService::class)->createManual([
            'amount' => 5000,
            'reference_id' => 'REF-'.Str::upper(Str::random(8)),
            'bank_key' => 'fcmb_ngn',
            'narration' => 'Transfer',
            'campaign_uuid' => $campaign->uuid,
            'purpose' => 'Student Welfare',
        ], $adminUuid);

        $this->assertSame('student_welfare', $transaction->purpose);
        $this->assertSame('Student Welfare', $transaction->metadata['reconciliation_draft']['purpose'] ?? null);
    }

    public function test_complete_manual_sets_custom_purpose_from_payload(): void
    {
        [, , $adminUuid] = $this->seedDonorReferenceData();
        $campaign = $this->createCampaign(['NGN']);
        $user = User::factory()->create([
            'donor_type_uuid' => DonorType::query()->where('slug', DonorTypeSlug::FRIENDS_OF_ICOBA->value)->value('uuid'),
        ]);
        $transaction = $this->createPendingBankTransfer(['campaign_uuid' => $campaign->uuid]);

        $finalized = app(DonationReconciliationService::class)->completeManual($transaction, [
            'user_uuid' => $user->uuid,
            'campaign_uuid' => $campaign->uuid,
            'purpose' => 'Chapel roof',
        ], $adminUuid);

        $this->assertSame(TransactionStatus::SUCCESSFUL, $finalized->status);
        $this->assertSame('Chapel roof', $finalized->purpose);
    }

    public function test_complete_manual_inherits_pledge_purpose_when_linking_pledge_without_purpose(): void
    {
        [, , $adminUuid] = $this->seedDonorReferenceData();
        $campaign = $this->createCampaign(['NGN']);
        $user = User::factory()->create([
            'donor_type_uuid' => DonorType::query()->where('slug', DonorTypeSlug::FRIENDS_OF_ICOBA->value)->value('uuid'),
        ]);
        $pledge = Pledge::query()->create([
            'campaign_uuid' => $campaign->uuid,
            'user_uuid' => $user->uuid,
            'donor_name' => 'Pledge Donor',
            'donor_email' => $user->email,
            'is_anonymous' => false,
            'purpose' => 'infrastructure',
            'committed_amount' => 5000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 5000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => 'monthly',
            'installment_count' => 1,
            'status' => PledgeStatus::ACTIVE,
        ]);
        $transaction = $this->createPendingBankTransfer(['campaign_uuid' => $campaign->uuid]);

        $finalized = app(DonationReconciliationService::class)->completeManual($transaction, [
            'user_uuid' => $user->uuid,
            'pledge_uuid' => $pledge->uuid,
        ], $adminUuid);

        $this->assertSame($pledge->uuid, $finalized->pledge_uuid);
        $this->assertSame('infrastructure', $finalized->purpose);
    }

    /**
     * @return array{0: GraduationSet, 1: CorporateCategory, 2: string}
     */
    private function seedDonorReferenceData(): array
    {
        Mail::fake();
        Notification::fake();

        Country::query()->firstOrCreate(['iso2' => 'NG'], ['name' => 'Nigeria', 'dial_code' => '+234', 'is_active' => true]);
        Role::firstOrCreate(['name' => eRole::CUSTOMER->value, 'guard_name' => 'api'], ['uuid' => (string) Str::uuid()]);

        foreach (DonorTypeSlug::cases() as $slug) {
            DonorType::query()->firstOrCreate(
                ['slug' => $slug->value],
                ['label' => $slug->label(), 'description' => $slug->description()],
            );
        }

        $set = GraduationSet::query()->create([
            'public_id' => 'SET-2002',
            'name' => 'Class 2002',
            'set_number' => '2002',
        ]);
        $category = CorporateCategory::query()->create(['name' => 'Bank']);

        $admin = Admin::query()->create([
            'name' => 'Reconciliation Admin',
            'email' => 'admin-'.Str::lower(Str::random(8)).'@example.com',
            'password' => 'secret-password',
        ]);

        return [$set, $category, $admin->uuid];
    }

    /**
     * @param  list<string>  $currencies
     */
    private function createCampaign(array $currencies): Campaign
    {
        return Campaign::query()->create([
            'campaign_id' => 'CAMP-'.Str::upper(Str::random(8)),
            'name' => 'Test Campaign',
            'short_description' => 'Short description',
            'long_description' => 'Long description',
            'categories' => ['general'],
            'base_currency' => $currencies[0],
            'available_donation_currencies' => $currencies,
            'target_amount' => 1000000,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => CampaignStatus::ACTIVE->value,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createPendingBankTransfer(array $overrides = []): Transaction
    {
        return Transaction::query()->create(array_merge([
            'transaction_id' => 'TXN-'.Str::upper(Str::random(8)),
            'amount' => 1000,
            'currency' => 'NGN',
            'status' => TransactionStatus::PENDING->value,
            'application_type' => TransactionApplicationType::BANK_TRANSFER->value,
            'bank_transfer_reference' => 'REF-'.Str::upper(Str::random(8)),
            'metadata' => ['source' => 'admin_manual'],
        ], $overrides));
    }
}
