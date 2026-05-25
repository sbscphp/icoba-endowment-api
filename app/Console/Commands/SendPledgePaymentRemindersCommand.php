<?php

namespace App\Console\Commands;

use App\Services\Pledge\PledgePaymentReminderService;
use Illuminate\Console\Command;

class SendPledgePaymentRemindersCommand extends Command
{
    protected $signature = 'pledges:send-payment-reminders {--dry-run : Count reminders without dispatching jobs}';

    protected $description = 'Send pledge installment payment reminder emails due in N days (default: 3).';

    public function handle(PledgePaymentReminderService $reminderService): int
    {
        if ((bool) $this->option('dry-run')) {
            $this->info('Dry run is not implemented; run without --dry-run to dispatch reminder jobs.');

            return self::SUCCESS;
        }

        $days = $reminderService->daysBeforeDue();
        $count = $reminderService->dispatchDueReminders();
        $this->info("Dispatched {$count} pledge payment reminder job(s) ({$days} day(s) before due date).");

        return self::SUCCESS;
    }
}
