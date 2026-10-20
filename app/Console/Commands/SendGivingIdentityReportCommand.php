<?php

namespace App\Console\Commands;

use App\Services\Admin\Report\GivingIdentity\GivingIdentityReportService;
use Illuminate\Console\Command;

class SendGivingIdentityReportCommand extends Command
{
    protected $signature = 'reports:giving-identities
        {--to=* : Email address to send the Excel report to (repeat for several, or comma-separate)}
        {--dry-run : Build the report and print the summary without sending or storing}
        {--save-only : Build and store the Excel file without emailing it}';

    protected $description = 'Email an Excel snapshot of every giving identity and user: source, status, donor profile, account match, donation totals and items needing attention.';

    public function handle(GivingIdentityReportService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $saveOnly = (bool) $this->option('save-only');
        $recipients = $this->recipients();

        if ($recipients === null) {
            return self::INVALID;
        }

        if ($recipients === [] && ! $dryRun && ! $saveOnly) {
            $this->error('Pass at least one recipient, e.g. --to=admin@example.com (or use --dry-run / --save-only).');

            return self::INVALID;
        }

        if ($dryRun) {
            $report = $service->build();
            $this->printSummary($report['summary']);
            $this->info(sprintf(
                'Dry run complete: %d identity row(s), %d user(s) without identity, %d donor email(s) without identity. Nothing sent or stored.',
                count($report['identities']),
                count($report['users_without_identity']),
                count($report['donors_without_identity']),
            ));

            return self::SUCCESS;
        }

        if ($saveOnly) {
            $result = $service->generateAndStore();
            $this->printSummary($result['summary']);
            $this->info('Stored: '.($result['stored_path'] ?? '(store failed)'));

            return $result['stored_path'] !== null ? self::SUCCESS : self::FAILURE;
        }

        try {
            $result = $service->generateAndSend($recipients);
        } catch (\Throwable $e) {
            report($e);
            $this->error('Report could not be sent: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->printSummary($result['summary']);
        $this->info(sprintf(
            'Sent %s to %s. Copy stored at: %s',
            $result['filename'],
            implode(', ', $result['recipients']),
            $result['stored_path'] ?? '(store failed)',
        ));

        return self::SUCCESS;
    }

    /**
     * @return list<string>|null Null when an address is invalid
     */
    private function recipients(): ?array
    {
        $addresses = [];

        foreach ((array) $this->option('to') as $value) {
            foreach (explode(',', (string) $value) as $address) {
                $address = strtolower(trim($address));
                if ($address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        $invalid = array_values(array_filter($addresses, fn (string $a): bool => filter_var($a, FILTER_VALIDATE_EMAIL) === false));

        if ($invalid !== []) {
            $this->error('Invalid email address(es): '.implode(', ', $invalid));

            return null;
        }

        return array_values(array_unique($addresses));
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function printSummary(array $summary): void
    {
        $this->table(['Metric', 'Value'], [
            ['Giving identities', (string) $summary['total_identities']],
            ['  linked to an account', (string) $summary['with_account']],
            ['  guest only', (string) $summary['guest_only']],
            ['  locked', (string) $summary['locked']],
            ['  needing attention', (string) $summary['needs_attention']],
            ['  account profile differs', (string) $summary['account_profile_differs']],
            ['Total donated via identities', '₦'.number_format((float) $summary['total_ngn'], 2)],
            ['Registered users', (string) $summary['total_users']],
            ['  without an identity', (string) $summary['users_without_identity']],
            ['Donor emails with unattached donations', (string) $summary['donor_emails_without_identity']],
        ]);

        $this->table(
            ['Source', 'Identities'],
            collect($summary['by_source'])->sortDesc()->map(fn ($count, $source) => [$source, $count])->values()->all(),
        );

        $this->table(
            ['Status', 'Identities'],
            collect($summary['by_status'])->sortDesc()->map(fn ($count, $status) => [$status, $count])->values()->all(),
        );
    }
}
