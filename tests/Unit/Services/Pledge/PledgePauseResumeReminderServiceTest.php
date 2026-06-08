<?php

namespace Tests\Unit\Services\Pledge;

use App\Services\Pledge\PledgeBalanceService;
use App\Services\Pledge\PledgePauseResumeReminderService;
use App\Services\Pledge\PledgeScheduleService;
use Tests\TestCase;

final class PledgePauseResumeReminderServiceTest extends TestCase
{
    public function test_advance_reminder_days_before_reads_config(): void
    {
        config(['pledges.pause_resume_reminder_days_before' => [5, 2, 1]]);

        $service = new PledgePauseResumeReminderService(
            new PledgeScheduleService(new PledgeBalanceService),
        );

        $this->assertSame([5, 2, 1], $service->advanceReminderDaysBefore());
    }

    public function test_advance_reminder_days_before_falls_back_to_defaults(): void
    {
        config(['pledges.pause_resume_reminder_days_before' => []]);

        $service = new PledgePauseResumeReminderService(
            new PledgeScheduleService(new PledgeBalanceService),
        );

        $this->assertSame([3, 1], $service->advanceReminderDaysBefore());
    }
}
