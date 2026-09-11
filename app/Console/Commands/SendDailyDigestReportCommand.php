<?php

namespace App\Console\Commands;

use App\Jobs\SendDailyDigestReportJob;
use App\Services\Admin\Report\DailyDigest\DailyDigestReportService;
use Illuminate\Console\Command;

class SendDailyDigestReportCommand extends Command
{
    protected $signature = 'reports:send-daily-digest
        {--date= : Report date (Y-m-d). Defaults to yesterday in the app timezone}
        {--to=* : Send only to these addresses instead of all active admins}
        {--dry-run : Build the report and print the summary and recipients without sending}
        {--save-only : Generate and store the PDF/CSV without emailing}
        {--sync : Run inline instead of dispatching the queued job}';

    protected $description = 'Generate the daily donations & pledges digest (PDF + overdue CSV) and email it to active admins.';

    public function handle(DailyDigestReportService $service): int
    {
        if (! (bool) config('reports.daily_digest.enabled', true) && ! $this->option('dry-run') && ! $this->option('save-only')) {
            $this->warn('Daily digest is disabled (reports.daily_digest.enabled). Nothing sent.');

            return self::SUCCESS;
        }

        $reportDate = DailyDigestReportService::resolveReportDate($this->option('date'));
        $to = array_values(array_filter(array_map('trim', (array) $this->option('to'))));
        $recipients = $to !== [] ? $to : null;

        if ((bool) $this->option('dry-run')) {
            $generated = $service->generate($reportDate, store: false);
            $this->printSummary($generated['report']);
            $resolved = $recipients ?? $service->recipients();
            $this->line('Recipients ('.count($resolved).'): '.($resolved === [] ? '(none)' : implode(', ', $resolved)));
            $this->info('Dry run complete. Nothing was sent or stored.');

            return self::SUCCESS;
        }

        if ((bool) $this->option('save-only')) {
            $generated = $service->generate($reportDate, store: true);
            $this->printSummary($generated['report']);
            $this->info('Stored: '.($generated['pdf_path'] ?? '(store failed)').' and '.($generated['csv_path'] ?? '(store failed)'));

            return self::SUCCESS;
        }

        if ((bool) $this->option('sync')) {
            $result = $service->generateAndSend($reportDate, $recipients);
            $this->info(sprintf(
                'Daily digest for %s %s to %d recipient(s). Overdue donors: %d. Stored: %s',
                $result['report_date'],
                $result['sent'] ? 'sent' : 'NOT sent',
                count($result['recipients']),
                $result['overdue_donors'],
                $result['pdf_path'] ?? '(store failed)',
            ));

            return $result['sent'] ? self::SUCCESS : self::FAILURE;
        }

        SendDailyDigestReportJob::dispatch($reportDate->toDateString(), $recipients);
        $this->info('Queued daily digest job for '.$reportDate->toDateString().'.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function printSummary(array $report): void
    {
        $s = $report['summary'];
        $this->line('Report date: '.$report['report_date_label']);
        $this->table(['Metric', 'Value'], [
            ['Donations yesterday', '₦'.number_format((float) $s['donations_yesterday']['amount'], 2).' ('.$s['donations_yesterday']['count'].')'],
            ['Month to date', '₦'.number_format((float) $s['donations_mtd']['amount'], 2).' ('.$s['donations_mtd']['count'].')'],
            ['Year to date', '₦'.number_format((float) $s['donations_ytd']['amount'], 2)],
            ['Active pledges', $s['active_pledges']['count'].' ('.$s['active_pledges']['collection_rate'].'% collected)'],
            ['Overdue', '₦'.number_format((float) $s['overdue']['amount_ngn'], 2).' across '.$s['overdue']['donors'].' donor(s), '.$s['overdue']['pledges'].' pledge(s)'],
            ['New pledges yesterday', (string) $s['new_pledges_yesterday']['count']],
            ['Fulfilled yesterday', (string) $s['pledges_fulfilled_yesterday']],
            ['Paused pledges', (string) $s['paused_pledges']],
            ['Awaiting bank verification', $s['awaiting_bank_verification']['count'].' (₦'.number_format((float) $s['awaiting_bank_verification']['amount_ngn'], 2).')'],
        ]);
    }
}
