<?php

namespace App\Console\Commands;

use App\Services\Pledge\PledgePauseResumeReminderService;
use Illuminate\Console\Command;

class SendPledgePauseResumeRemindersCommand extends Command
{
    protected $signature = 'pledges:send-pause-resume-reminders';

    protected $description = 'Send pledge pause resume reminder emails and auto-resume pledges on their resume date.';

    public function handle(PledgePauseResumeReminderService $reminderService): int
    {
        $count = $reminderService->dispatchDueReminders();
        $days = implode(', ', $reminderService->advanceReminderDaysBefore());
        $this->info("Dispatched {$count} pledge pause/resume reminder job(s) (advance days: {$days}; plus on resume date).");

        return self::SUCCESS;
    }
}
