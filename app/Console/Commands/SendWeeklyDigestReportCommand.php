<?php

namespace App\Console\Commands;

use App\Jobs\SendWeeklyDigestReportJob;
use App\Services\Admin\Report\WeeklyDigest\WeeklyDigestReportService;
use Illuminate\Console\Command;

class SendWeeklyDigestReportCommand extends Command
{
    protected $signature = 'reports:send-weekly-digest
        {--date= : Any date (Y-m-d) inside the week to report on. Defaults to the most recently completed Monday–Sunday week}
        {--to=* : Send only to these addresses instead of all active admins}
        {--dry-run : Build the report and print the summary and recipients without sending}
        {--save-only : Generate and store the PDF/CSV without emailing}
        {--sync : Run inline instead of dispatching the queued job}';

    protected $description = 'Generate the weekly donations & pledges digest (PDF + overdue CSV) and email it to active admins.';

    public function handle(WeeklyDigestReportService $service): int
    {
        if (! (bool) config('reports.weekly_digest.enabled', true) && ! $this->option('dry-run') && ! $this->option('save-only')) {
            $this->warn('Weekly digest is disabled (reports.weekly_digest.enabled). Nothing sent.');

            return self::SUCCESS;
        }

        $periodEnd = WeeklyDigestReportService::resolvePeriodEnd($this->option('date'));
        $to = array_values(array_filter(array_map('trim', (array) $this->option('to'))));
        $recipients = $to !== [] ? $to : null;

        if ((bool) $this->option('dry-run')) {
            $generated = $service->generate($periodEnd, store: false);
            $this->printSummary($generated['report']);
            $resolved = $recipients ?? $service->recipients();
            $this->line('Recipients ('.count($resolved).'): '.($resolved === [] ? '(none)' : implode(', ', $resolved)));
            $this->info('Dry run complete. Nothing was sent or stored.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('save-only')) {
            $generated = $service->generate($periodEnd, store: true);
            $this->printSummary($generated['report']);
            $this->info('Stored: '.($generated['pdf_path'] ?? '(store failed)').' and '.($generated['csv_path'] ?? '(store failed)'));

            return self::SUCCESS;
        }

        if ((bool) $this->option('sync')) {
            $result = $service->generateAndSend($periodEnd, $recipients);
            $this->info(sprintf(
                'Weekly digest for %s to %s %s to %d recipient(s). Overdue donors: %d. Stored: %s',
                $result['period_start'],
                $result['period_end'],
                $result['sent'] ? 'sent' : 'NOT sent',
                count($result['recipients']),
                $result['overdue_donors'],
                $result['pdf_path'] ?? '(store failed)',
            ));

            return $result['sent'] ? self::SUCCESS : self::FAILURE;
        }

        SendWeeklyDigestReportJob::dispatch($periodEnd->toDateString(), $recipients);
        $this->info('Queued weekly digest job for the week ending '.$periodEnd->toDateString().'.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printSummary(array $report): void
    {
        $s = $report['summary'];
        $this->line('Report period: '.$report['period_label']);
        $this->table(['Metric', 'Value'], [
            ['Donations this week', '₦'.number_format((float) $s['donations_this_week']['amount'], 2).' ('.$s['donations_this_week']['count'].')'],
            ['Month to date', '₦'.number_format((float) $s['donations_mtd']['amount'], 2).' ('.$s['donations_mtd']['count'].')'],
            ['Year to date', '₦'.number_format((float) $s['donations_ytd']['amount'], 2)],
            ['Active pledges', $s['active_pledges']['count'].' ('.$s['active_pledges']['collection_rate'].'% collected)'],
            ['Overdue', '₦'.number_format((float) $s['overdue']['amount_ngn'], 2).' across '.$s['overdue']['donors'].' donor(s), '.$s['overdue']['pledges'].' pledge(s)'],
            ['New pledges this week', (string) $s['new_pledges_this_week']['count']],
            ['Fulfilled this week', (string) $s['pledges_fulfilled_this_week']],
            ['Paused pledges', (string) $s['paused_pledges']],
            ['Awaiting bank verification', $s['awaiting_bank_verification']['count'].' (₦'.number_format((float) $s['awaiting_bank_verification']['amount_ngn'], 2).')'],
        ]);
    }
}
