<?php

namespace App\Services\Pledge;

use App\Enums\PledgeScheduleItemStatus;
use App\Enums\PledgeStatus;
use App\Jobs\SendPledgePaymentReminderJob;
use App\Models\Pledge;
use Carbon\Carbon;

class PledgePaymentReminderService
{
    public function __construct(
        private readonly PledgeScheduleService $scheduleService,
    ) {}

    public function daysBeforeDue(): int
    {
        return max(1, (int) config('pledges.payment_reminder_days_before', 3));
    }

    /**
     * Queue reminder emails for installments due on ($today + daysBeforeDue).
     */
    public function dispatchDueReminders(?Carbon $asOf = null): int
    {
        $asOf = $asOf ?? now((string) config('app.timezone'));
        $targetDueDate = $asOf->copy()->startOfDay()->addDays($this->daysBeforeDue())->toDateString();
        $dispatched = 0;

        Pledge::query()
            ->where('status', PledgeStatus::ACTIVE)
            ->orderBy('id')
            ->chunkById(100, function ($pledges) use ($targetDueDate, &$dispatched): void {
                foreach ($pledges as $pledge) {
                    if (! $pledge instanceof Pledge) {
                        continue;
                    }

                    if ($this->scheduleService->isPledgePaused($pledge)) {
                        continue;
                    }

                    $view = $this->scheduleService->buildForPledge($pledge);
                    foreach ($view['items'] as $item) {
                        if (($item['due_date'] ?? null) !== $targetDueDate) {
                            continue;
                        }

                        if (! in_array($item['status'], [
                            PledgeScheduleItemStatus::PENDING->value,
                            PledgeScheduleItemStatus::PARTIAL->value,
                            PledgeScheduleItemStatus::OVERDUE->value,
                        ], true)) {
                            continue;
                        }

                        if ((float) $item['remaining_amount'] <= 0.00001) {
                            continue;
                        }

                        $itemId = (string) $item['id'];
                        if ($this->scheduleService->wasPaymentReminderSent($pledge, $itemId, $targetDueDate)) {
                            continue;
                        }

                        SendPledgePaymentReminderJob::dispatch($pledge->uuid, $itemId, $targetDueDate);
                        $dispatched++;
                    }
                }
            });

        return $dispatched;
    }
}
