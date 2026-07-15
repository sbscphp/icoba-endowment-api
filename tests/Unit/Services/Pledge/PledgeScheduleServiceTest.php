<?php

namespace Tests\Unit\Services\Pledge;

use App\Enums\CampaignStatus;
use App\Enums\PledgePaymentPlanType;
use App\Enums\PledgeStatus;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Pledge\PledgeScheduleInput;
use App\Services\Pledge\PledgeScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PledgeScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private PledgeScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PledgeScheduleService(new PledgeBalanceService);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_custom_daily_interval_generates_equal_installments_and_due_dates(): void
    {
        $start = Carbon::parse('2026-05-26 10:00:00');

        $pledge = new Pledge([
            'uuid' => 'pledge-test-uuid',
            'committed_amount' => 500000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 500000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => PledgePaymentPlanType::CUSTOM,
            'installment_count' => 5,
            'metadata' => [
                'schedule_config' => [
                    'frequency' => 'daily',
                    'interval' => 2,
                ],
            ],
            'created_at' => $start,
        ]);

        $view = $this->service->buildForPledge($pledge, new Collection);

        $this->assertSame('custom', $view['payment_plan_type']);
        $this->assertSame(5, $view['installment_count']);
        $this->assertSame('100000.00', $view['amount_per_installment']);
        $this->assertSame([
            'frequency' => 'daily',
            'interval' => 2,
        ], $view['schedule_config']);
        $this->assertCount(5, $view['items']);
        $this->assertSame('100000.00', $view['items'][0]['pledged_amount']);
        $this->assertSame('2026-05-26', $view['items'][0]['due_date']);
        $this->assertSame('2026-06-03', $view['items'][4]['due_date']);
    }

    public function test_monthly_schedule_first_installment_is_due_on_start_date(): void
    {
        $start = Carbon::parse('2026-07-15 10:00:00');

        $pledge = new Pledge([
            'uuid' => 'pledge-monthly-start-today',
            'committed_amount' => 200000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 200000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => PledgePaymentPlanType::MONTHLY,
            'installment_count' => 2,
            'created_at' => $start,
        ]);

        $view = $this->service->buildForPledge($pledge, new Collection);

        $this->assertSame('2026-07-15', $view['items'][0]['due_date']);
        $this->assertSame('2026-08-15', $view['items'][1]['due_date']);
    }

    public function test_one_time_future_schedule_is_due_on_the_exact_start_date(): void
    {
        $start = Carbon::parse('2026-07-15 10:00:00');

        $pledge = new Pledge([
            'uuid' => 'pledge-one-time-future',
            'committed_amount' => 150000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 150000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => PledgePaymentPlanType::ONE_TIME_FUTURE,
            'created_at' => $start,
        ]);

        $view = $this->service->buildForPledge($pledge, new Collection);

        $this->assertCount(1, $view['items']);
        $this->assertSame('2026-07-15', $view['items'][0]['due_date']);
    }

    public function test_schedule_input_accepts_duration_alias_for_interval(): void
    {
        $resolved = PledgeScheduleInput::normalizeConfig([
            'frequency' => 'weekly',
            'duration' => 3,
        ]);

        $this->assertSame([
            'frequency' => 'weekly',
            'interval' => 3,
        ], $resolved);
    }

    public function test_pledge_resume_date_reads_metadata(): void
    {
        $pledge = new Pledge([
            'metadata' => [
                'is_paused' => true,
                'resume_date' => '2026-06-15',
            ],
        ]);

        $this->assertTrue($this->service->isPledgePaused($pledge));
        $this->assertSame('2026-06-15', $this->service->pledgeResumeDate($pledge));
    }

    public function test_pause_resume_reminder_key_format(): void
    {
        $this->assertSame(
            '2026-06-20:3_days_before',
            $this->service->pauseResumeReminderKey('2026-06-20', '3_days_before'),
        );
    }

    public function test_pause_resume_max_months_reads_config(): void
    {
        config(['pledges.pause_resume_max_months_from_due_date' => 6]);

        $this->assertSame(6, $this->service->pauseResumeMaxMonthsFromDueDate());
    }

    public function test_assert_resume_date_within_limit_accepts_date_within_window(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $pledge = $this->makePledgeForPauseValidation('2026-06-15');

        $this->assertSame('2026-06-15', $this->service->nextDueInstallment($pledge, new Collection)['due_date']);
        $this->service->assertResumeDateWithinLimit($pledge, '2026-09-15', new Collection);

        $this->addToAssertionCount(1);
    }

    public function test_assert_resume_date_within_limit_rejects_date_beyond_window(): void
    {
        Carbon::setTestNow('2026-06-01 12:00:00');

        $pledge = $this->makePledgeForPauseValidation('2026-06-15');

        try {
            $this->service->assertResumeDateWithinLimit($pledge, '2026-09-16', new Collection);
            $this->fail('Expected ValidationException was not thrown.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                'The resume date cannot be more than 3 months after the next installment due date (June 15, 2026).',
                $exception->errors()['resume_date'][0],
            );
        }
    }

    public function test_reschedule_regenerates_schedule_from_new_start_date_and_reallocates_existing_payment(): void
    {
        Carbon::setTestNow('2026-07-15 09:00:00');

        $pledge = $this->createPledgeRow([
            'committed_amount' => 200000,
            'committed_amount_ngn' => 200000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => PledgePaymentPlanType::MONTHLY->value,
            'installment_count' => 2,
        ]);
        $this->createSuccessfulTransaction($pledge, 60000);

        $rescheduled = $this->service->reschedulePledge($pledge, [
            'start_date' => '2026-08-01',
            'installment_count' => 3,
            'payment_plan_type' => PledgePaymentPlanType::MONTHLY->value,
        ]);

        $this->assertSame(3, $rescheduled->installment_count);
        $this->assertSame(PledgePaymentPlanType::MONTHLY, $rescheduled->payment_plan_type);

        $view = $this->service->buildForPledge($rescheduled);
        $this->assertSame('2026-08-01', $view['items'][0]['due_date']);
        $this->assertSame('2026-09-01', $view['items'][1]['due_date']);
        $this->assertSame('2026-10-01', $view['items'][2]['due_date']);

        // The 60,000 already paid (untagged to any specific installment) fills the
        // earliest installment of the new schedule (66,666.67) as a partial payment.
        $this->assertSame('60000.00', $view['items'][0]['paid_amount']);
        $this->assertSame('partial', $view['items'][0]['status']);
        $this->assertSame('pending', $view['items'][1]['status']);
        $this->assertSame('pending', $view['items'][2]['status']);

        $history = $rescheduled->metadata['reschedule_history'] ?? [];
        $this->assertCount(1, $history);
        $this->assertSame(2, $history[0]['previous_installment_count']);
    }

    public function test_reschedule_rejects_non_active_pledge(): void
    {
        $pledge = $this->createPledgeRow([
            'status' => PledgeStatus::CANCELLED->value,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->reschedulePledge($pledge, ['start_date' => now()->toDateString()]);
    }

    public function test_reschedule_to_custom_plan_requires_a_schedule_config(): void
    {
        $pledge = $this->createPledgeRow([
            'payment_plan_type' => PledgePaymentPlanType::MONTHLY->value,
        ]);

        $this->expectException(ValidationException::class);

        $this->service->reschedulePledge($pledge, [
            'start_date' => now()->toDateString(),
            'payment_plan_type' => PledgePaymentPlanType::CUSTOM->value,
        ]);
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
    private function createPledgeRow(array $overrides = []): Pledge
    {
        $campaign = $this->createCampaign();

        return Pledge::query()->create(array_merge([
            'campaign_uuid' => $campaign->uuid,
            'committed_amount' => 100000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 100000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => PledgePaymentPlanType::MONTHLY->value,
            'installment_count' => 1,
            'status' => PledgeStatus::ACTIVE->value,
        ], $overrides));
    }

    private function createSuccessfulTransaction(Pledge $pledge, float $amount): Transaction
    {
        return Transaction::query()->create([
            'transaction_id' => 'TXN-'.Str::upper(Str::random(8)),
            'campaign_uuid' => $pledge->campaign_uuid,
            'pledge_uuid' => $pledge->uuid,
            'amount' => $amount,
            'currency' => $pledge->currency,
            'status' => TransactionStatus::SUCCESSFUL->value,
            'application_type' => TransactionApplicationType::SCHEDULED_INSTALLMENT->value,
            'paid_at' => now(),
            'metadata' => [],
        ]);
    }

    private function makePledgeForPauseValidation(string $dueDate): Pledge
    {
        return new Pledge([
            'uuid' => 'pledge-pause-validation',
            'status' => PledgeStatus::ACTIVE,
            'committed_amount' => 300000,
            'currency' => 'NGN',
            'committed_amount_ngn' => 300000,
            'exchange_rate_to_naira' => 1,
            'payment_plan_type' => PledgePaymentPlanType::MONTHLY,
            'installment_count' => 3,
            'schedule' => [
                [
                    'id' => 'installment-1',
                    'sequence' => 1,
                    'due_date' => $dueDate,
                    'amount' => 100000,
                    'amount_ngn' => 100000,
                ],
                [
                    'id' => 'installment-2',
                    'sequence' => 2,
                    'due_date' => '2026-07-15',
                    'amount' => 100000,
                    'amount_ngn' => 100000,
                ],
                [
                    'id' => 'installment-3',
                    'sequence' => 3,
                    'due_date' => '2026-08-15',
                    'amount' => 100000,
                    'amount_ngn' => 100000,
                ],
            ],
            'created_at' => Carbon::parse('2026-05-15'),
        ]);
    }
}
