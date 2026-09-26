<?php

namespace Tests\Unit\Services\Maintenance;

use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use App\Models\Transaction;
use App\Services\Maintenance\TransactionFailureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;
use Tests\TestCase;

final class TransactionFailureServiceTest extends TestCase
{
    use RefreshDatabase;

    private Campaign $campaign;

    private TransactionFailureService $service;

    protected function setUp(): void
    {
        parent::setUp();

        config(['endowment.payments.test_mode' => false]);

        $this->campaign = Campaign::query()->create([
            'uuid' => 'campaign-mark-failed',
            'campaign_id' => 'CMP-MF1',
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

        $this->service = app(TransactionFailureService::class);
    }

    public function test_requires_a_selector(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->markFailed([], true);
    }

    public function test_dry_run_previews_but_writes_nothing(): void
    {
        $pending = $this->transaction('TRN-DRY', ['status' => TransactionStatus::PENDING]);

        $result = $this->service->markFailed(['transactions' => ['TRN-DRY']], true);

        $this->assertSame(1, $result['matched']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame('TRN-DRY', $result['preview'][0]['transaction_id']);
        $this->assertSame(TransactionStatus::PENDING, $pending->fresh()->status);
    }

    public function test_marks_pending_rows_by_uuid_or_transaction_id_and_records_the_reason(): void
    {
        $byId = $this->transaction('TRN-BY-ID', ['status' => TransactionStatus::PENDING, 'metadata' => ['channel' => 'card']]);
        $byUuid = $this->transaction('TRN-BY-UUID', ['status' => TransactionStatus::PENDING]);

        $result = $this->service->markFailed([
            'transactions' => ['TRN-BY-ID', $byUuid->uuid],
            'reason' => 'Abandoned checkout',
        ], false);

        $this->assertSame(2, $result['updated']);
        $this->assertSame([], $result['not_found']);
        $this->assertSame([], $result['skipped']);

        $byId = $byId->fresh();
        $this->assertSame(TransactionStatus::FAILED, $byId->status);
        $this->assertSame('card', $byId->metadata['channel']);
        $this->assertSame('Abandoned checkout', $byId->metadata[TransactionFailureService::METADATA_KEY]['reason']);
        $this->assertSame('console', $byId->metadata[TransactionFailureService::METADATA_KEY]['source']);
        $this->assertSame(TransactionStatus::FAILED, $byUuid->fresh()->status);
    }

    public function test_non_pending_and_unknown_references_are_reported_and_left_alone(): void
    {
        $successful = $this->transaction('TRN-OK', ['status' => TransactionStatus::SUCCESSFUL]);
        $reversed = $this->transaction('TRN-REV', ['status' => TransactionStatus::REVERSED]);

        $result = $this->service->markFailed(['transactions' => ['TRN-OK', 'TRN-REV', 'TRN-NOPE']], false);

        $this->assertSame(0, $result['matched']);
        $this->assertSame(0, $result['updated']);
        $this->assertSame(['TRN-NOPE'], $result['not_found']);
        $this->assertEqualsCanonicalizing([
            ['reference' => 'TRN-OK', 'status' => 'successful'],
            ['reference' => 'TRN-REV', 'status' => 'reversed'],
        ], $result['skipped']);
        $this->assertSame(TransactionStatus::SUCCESSFUL, $successful->fresh()->status);
        $this->assertSame(TransactionStatus::REVERSED, $reversed->fresh()->status);
    }

    public function test_pending_before_sweeps_old_gateway_rows_but_not_bank_transfers_unless_asked(): void
    {
        $old = $this->transaction('TRN-OLD', ['status' => TransactionStatus::PENDING, 'created_at' => Date::now()->subDays(5)]);
        $recent = $this->transaction('TRN-NEW', ['status' => TransactionStatus::PENDING, 'created_at' => Date::now()->subHours(1)]);
        $oldSuccess = $this->transaction('TRN-OLD-OK', ['status' => TransactionStatus::SUCCESSFUL, 'created_at' => Date::now()->subDays(5)]);
        $bank = $this->transaction('TRN-BANK', [
            'status' => TransactionStatus::PENDING,
            'gateway' => null,
            'application_type' => TransactionApplicationType::BANK_TRANSFER,
            'created_at' => Date::now()->subDays(5),
        ]);

        $result = $this->service->markFailed(['pending_before' => Date::now()->subDays(2)], false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(TransactionStatus::FAILED, $old->fresh()->status);
        $this->assertSame(TransactionStatus::PENDING, $recent->fresh()->status);
        $this->assertSame(TransactionStatus::SUCCESSFUL, $oldSuccess->fresh()->status);
        $this->assertSame(TransactionStatus::PENDING, $bank->fresh()->status);

        $result = $this->service->markFailed(['pending_before' => Date::now()->subDays(2), 'include_bank_transfers' => true], false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(TransactionStatus::FAILED, $bank->fresh()->status);
    }

    public function test_gateway_filter_narrows_the_sweep(): void
    {
        $paystack = $this->transaction('TRN-PS', ['status' => TransactionStatus::PENDING, 'gateway' => 'paystack', 'created_at' => Date::now()->subDays(5)]);
        $stripe = $this->transaction('TRN-ST', ['status' => TransactionStatus::PENDING, 'gateway' => 'stripe', 'created_at' => Date::now()->subDays(5)]);

        $result = $this->service->markFailed(['pending_before' => Date::now()->subDays(2), 'gateways' => ['Stripe']], false);

        $this->assertSame(1, $result['updated']);
        $this->assertSame(TransactionStatus::PENDING, $paystack->fresh()->status);
        $this->assertSame(TransactionStatus::FAILED, $stripe->fresh()->status);
    }

    public function test_command_dry_runs_by_default_and_applies_with_force(): void
    {
        $pending = $this->transaction('TRN-CMD', ['status' => TransactionStatus::PENDING]);

        $this->artisan('donations:mark-failed', ['--transaction' => ['TRN-CMD']])
            ->expectsOutputToContain('Would mark as failed — transactions: 1')
            ->assertExitCode(0);
        $this->assertSame(TransactionStatus::PENDING, $pending->fresh()->status);

        $this->artisan('donations:mark-failed', ['--transaction' => ['TRN-CMD'], '--reason' => 'Dead reference', '--force' => true])
            ->expectsOutputToContain('Marked as failed — transactions: 1')
            ->assertExitCode(0);
        $this->assertSame(TransactionStatus::FAILED, $pending->fresh()->status);
    }

    public function test_command_rejects_bad_input(): void
    {
        $this->artisan('donations:mark-failed')->assertExitCode(1);
        $this->artisan('donations:mark-failed', ['--pending-before' => 'soon-ish'])->assertExitCode(1);
        $this->artisan('donations:mark-failed', ['--pending-before' => '48h', '--gateway' => ['flutterwave']])->assertExitCode(1);
    }

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
