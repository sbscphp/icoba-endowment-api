<?php

namespace Tests\Unit\Services\Pledge;

use App\Enums\PledgePaymentPlanType;
use App\Enums\PledgeStatus;
use App\Models\Pledge;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Pledge\PledgeScheduleInput;
use App\Services\Pledge\PledgeScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class PledgeScheduleServiceTest extends TestCase
{
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
        $this->assertSame('2026-05-28', $view['items'][0]['due_date']);
        $this->assertSame('2026-06-05', $view['items'][4]['due_date']);
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
