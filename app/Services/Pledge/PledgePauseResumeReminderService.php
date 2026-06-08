<?php

namespace App\Services\Pledge;

use App\Enums\PledgeStatus;
use App\Jobs\SendPledgePauseResumeReminderJob;
use App\Models\Pledge;
use Carbon\Carbon;

class PledgePauseResumeReminderService
{
    public function __construct(
        private readonly PledgeScheduleService $scheduleService,
    ) {}

    /**
     * @return list<int>
     */
    public function advanceReminderDaysBefore(): array
    {
        $days = config('pledges.pause_resume_reminder_days_before', [3, 1]);
        if (! is_array($days)) {
            return [3, 1];
        }

        $normalized = [];
        foreach ($days as $day) {
            $value = (int) $day;
            if ($value >= 1) {
                $normalized[] = $value;
            }
        }

        return $normalized !== [] ? array_values(array_unique($normalized)) : [3, 1];
    }

    /**
     * Queue advance and on-resume-date reminder emails for paused pledges.
     */
    public function dispatchDueReminders(?Carbon $asOf = null): int
    {
        $asOf = $asOf ?? now((string) config('app.timezone'));
        $today = $asOf->copy()->startOfDay();
        $dispatched = 0;

        foreach ($this->advanceReminderDaysBefore() as $daysBefore) {
            $targetResumeDate = $today->copy()->addDays($daysBefore)->toDateString();
            $dispatched += $this->dispatchForResumeDate(
                $targetResumeDate,
                $this->advanceReminderKind($daysBefore),
            );
        }

        $dispatched += $this->dispatchForResumeDate(
            $today->toDateString(),
            'on_resume_date',
        );

        return $dispatched;
    }

    private function advanceReminderKind(int $daysBefore): string
    {
        return $daysBefore.'_days_before';
    }

    private function dispatchForResumeDate(string $resumeDate, string $reminderKind): int
    {
        $dispatched = 0;

        Pledge::query()
            ->where('status', PledgeStatus::ACTIVE)
            ->orderBy('id')
            ->chunkById(100, function ($pledges) use ($resumeDate, $reminderKind, &$dispatched): void {
                foreach ($pledges as $pledge) {
                    if (! $pledge instanceof Pledge) {
                        continue;
                    }

                    if (! $this->scheduleService->isPledgePaused($pledge)) {
                        continue;
                    }

                    if ($this->scheduleService->pledgeResumeDate($pledge) !== $resumeDate) {
                        continue;
                    }

                    if ($this->scheduleService->wasPauseResumeReminderSent($pledge, $resumeDate, $reminderKind)) {
                        continue;
                    }

                    SendPledgePauseResumeReminderJob::dispatch($pledge->uuid, $resumeDate, $reminderKind);
                    $dispatched++;
                }
            });

        return $dispatched;
    }
}
