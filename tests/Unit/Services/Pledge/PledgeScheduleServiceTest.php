<?php

namespace Tests\Unit\Services\Pledge;

use App\Enums\PledgePaymentPlanType;
use App\Models\Pledge;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Pledge\PledgeScheduleInput;
use App\Services\Pledge\PledgeScheduleService;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Tests\TestCase;

final class PledgeScheduleServiceTest extends TestCase
{
    private PledgeScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PledgeScheduleService(new PledgeBalanceService);
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
}
