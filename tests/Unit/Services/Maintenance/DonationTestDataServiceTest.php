<?php

namespace Tests\Unit\Services\Maintenance;

use App\Enums\PledgeStatus;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Services\Maintenance\DonationTestDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

final class DonationTestDataServiceTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    protected function setUp(): void
    {
        parent::setUp();

        $this->campaign = Campaign::query()->create([
            'uuid' => 'campaign-test-data',
            'campaign_id' => 'CMP-TD1',
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

    public function test_new_records_are_tagged_from_the_payment_mode(): void
    {
        config(['endowment.payments.test_mode' => false]);
        $this->assertFalse($this->pledge('live-pledge')->is_test);
        $this->assertFalse($this->transaction('TRN-LIVE')->is_test);

        config(['endowment.payments.test_mode' => true]);
        $this->assertTrue($this->pledge('test-pledge')->is_test);
        $this->assertTrue($this->transaction('TRN-TEST')->is_test);
    }

    public function test_auto_mode_in_production_follows_the_gateway_credentials(): void
    {
        config(['endowment.payments.test_mode' => null]);
        $this->app['env'] = 'production';

        config(['services.paystack.secret' => 'sk_test_abc', 'services.stripe.secret' => 'sk_live_abc']);

        $this->assertTrue($this->transaction('TRN-PS', ['gateway' => 'paystack'])->is_test);
        $this->assertFalse($this->transaction('TRN-ST', ['gateway' => 'stripe'])->is_test);
        $this->assertFalse($this->pledge('prod-pledge')->is_test);
    }

    public function test_payment_against_a_test_pledge_is_test_even_in_live_mode(): void
    {
        config(['endowment.payments.test_mode' => true]);
        $pledge = $this->pledge('inherit-pledge');

        config(['endowment.payments.test_mode' => false]);
        $this->assertTrue($this->transaction('TRN-INHERIT', ['pledge_uuid' => $pledge->uuid])->is_test);
    }

    public function test_marking_a_pledge_carries_its_transactions_and_dry_run_writes_nothing(): void
    {
        config(['endowment.payments.test_mode' => false]);
        $pledge = $this->pledge('mark-pledge');
        $this->transaction('TRN-MARK-1', ['pledge_uuid' => $pledge->uuid]);
        $this->transaction('TRN-OTHER');

        $service = app(DonationTestDataService::class);

        $preview = $service->mark(['pledges' => [$pledge->uuid]], true, dryRun: true);
        $this->assertSame(['pledges' => 1, 'transactions' => 1], ['pledges' => $preview['pledges'], 'transactions' => $preview['transactions']]);
        $this->assertFalse($pledge->fresh()->is_test);

        $service->mark(['pledges' => [$pledge->uuid]], true, dryRun: false);

        $this->assertTrue($pledge->fresh()->is_test);
        $this->assertTrue(Transaction::query()->where('transaction_id', 'TRN-MARK-1')->value('is_test'));
        $this->assertFalse(Transaction::query()->where('transaction_id', 'TRN-OTHER')->value('is_test'));
    }

    public function test_marking_by_email_and_before_date(): void
    {
        config(['endowment.payments.test_mode' => false]);

        Carbon::setTestNow('2026-07-01 10:00:00');
        $this->transaction('TRN-OLD-QA', ['donor_email' => 'QA@example.com']);
        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->transaction('TRN-NEW-QA', ['donor_email' => 'qa@example.com']);
        $this->transaction('TRN-NEW-REAL', ['donor_email' => 'real@example.com']);
        Carbon::setTestNow();

        app(DonationTestDataService::class)->mark(
            ['emails' => ['qa@example.com'], 'before' => Carbon::parse('2026-08-01')],
            true,
            dryRun: false,
        );

        $this->assertSame(['TRN-OLD-QA'], Transaction::query()->where('is_test', true)->pluck('transaction_id')->all());
    }

    public function test_purge_test_scope_removes_only_test_data_and_recomputes_live_pledges(): void
    {
        config(['endowment.payments.test_mode' => false]);
        $livePledge = $this->pledge('live-pledge', ['committed_amount' => 1000, 'status' => PledgeStatus::FULFILLED, 'fulfilled_at' => now()]);
        $this->transaction('TRN-LIVE-PAY', ['pledge_uuid' => $livePledge->uuid, 'amount' => 400, 'amount_in_naira' => 400]);
        $this->transaction('TRN-TEST-PAY', ['pledge_uuid' => $livePledge->uuid, 'amount' => 600, 'amount_in_naira' => 600, 'is_test' => true]);
        $this->transaction('TRN-LIVE-DONATION');

        $testPledge = $this->pledge('test-pledge', ['is_test' => true]);
        $this->transaction('TRN-TEST-PLEDGE-PAY', ['pledge_uuid' => $testPledge->uuid]);
        $softDeleted = $this->transaction('TRN-TEST-TRASHED', ['is_test' => true]);
        $softDeleted->delete();

        $mixedPledge = $this->pledge('mixed-pledge', ['is_test' => true]);
        $this->transaction('TRN-MIXED-LIVE', ['pledge_uuid' => $mixedPledge->uuid, 'is_test' => false]);

        $service = app(DonationTestDataService::class);

        $dry = $service->purge(DonationTestDataService::SCOPE_TEST, dryRun: true);
        $this->assertSame(3, $dry['transactions']);
        $this->assertSame(1, $dry['pledges']);
        $this->assertSame(6, Transaction::withTrashed()->count());

        $result = $service->purge(DonationTestDataService::SCOPE_TEST, dryRun: false);

        $this->assertSame(3, $result['transactions']);
        $this->assertSame(1, $result['pledges']);
        $this->assertSame([$mixedPledge->uuid], $result['pledges_kept_with_live_payments']);
        $this->assertEqualsCanonicalizing(
            ['TRN-LIVE-PAY', 'TRN-LIVE-DONATION', 'TRN-MIXED-LIVE'],
            Transaction::withTrashed()->pluck('transaction_id')->all(),
        );
        $this->assertEqualsCanonicalizing([$livePledge->uuid, $mixedPledge->uuid], Pledge::withTrashed()->pluck('uuid')->all());

        $livePledge->refresh();
        $this->assertSame(PledgeStatus::ACTIVE, $livePledge->status);
        $this->assertNull($livePledge->fulfilled_at);
    }

    public function test_purge_all_scope_removes_everything(): void
    {
        config(['endowment.payments.test_mode' => false]);
        $pledge = $this->pledge('any-pledge');
        $this->transaction('TRN-A', ['pledge_uuid' => $pledge->uuid]);
        $this->transaction('TRN-B', ['is_test' => true]);

        $result = app(DonationTestDataService::class)->purge(DonationTestDataService::SCOPE_ALL, dryRun: false);

        $this->assertSame(2, $result['transactions']);
        $this->assertSame(1, $result['pledges']);
        $this->assertSame(0, Transaction::withTrashed()->count());
        $this->assertSame(0, Pledge::withTrashed()->count());
    }

    public function test_purge_command_is_a_dry_run_without_force(): void
    {
        $this->transaction('TRN-CMD', ['is_test' => true]);

        $this->artisan('donations:purge')->assertSuccessful();
        $this->assertSame(1, Transaction::query()->count());

        $this->artisan('donations:purge', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, Transaction::query()->count());
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
