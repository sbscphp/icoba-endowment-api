<?php

namespace App\Console\Commands;

use App\Services\Maintenance\DonationTestDataService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class MarkDonationTestDataCommand extends Command
{
    protected $signature = 'donations:mark-test
                            {--transaction=* : Transaction UUID or TRN- id (repeatable)}
                            {--pledge=* : Pledge UUID — its transactions follow (repeatable)}
                            {--email=* : Donor email — every pledge and transaction of that donor (repeatable)}
                            {--before= : Only records created before this date/time (app timezone), e.g. the go-live date}
                            {--live : Flag the matches as live instead of test}
                            {--force : Write the change (without it the command only previews)}
                            {--preview=50 : Max transactions to list}';

    protected $description = 'Flag existing pledges and transactions as test (or back to live) so donations:purge --scope=test can remove them.';

    // # Everything created before go-live was testing
    // php artisan donations:mark-test --before="2026-08-01"
    // php artisan donations:mark-test --before="2026-08-01" --force

    // # A tester's records, or individual ones
    // php artisan donations:mark-test --email=qa@example.com --force
    // php artisan donations:mark-test --transaction=TRN-ABC123XYZ789 --pledge=<uuid> --force

    // # Undo
    // php artisan donations:mark-test --transaction=TRN-ABC123XYZ789 --live --force

    public function handle(DonationTestDataService $service): int
    {
        $before = $this->option('before');
        $before = is_string($before) && trim($before) !== '' ? trim($before) : null;

        try {
            $criteria = [
                'transactions' => array_map('strval', (array) $this->option('transaction')),
                'pledges' => array_map('strval', (array) $this->option('pledge')),
                'emails' => array_map('strval', (array) $this->option('email')),
                'before' => $before !== null ? Carbon::parse($before) : null,
            ];
        } catch (\Throwable) {
            $this->error('--before is not a valid date.');

            return self::FAILURE;
        }

        $isTest = ! (bool) $this->option('live');
        $dryRun = ! (bool) $this->option('force');
        $label = $isTest ? 'test' : 'live';

        if ($dryRun) {
            $this->info('Dry run — no records will be updated.');
        }

        try {
            $result = $service->mark($criteria, $isTest, $dryRun, max(0, (int) $this->option('preview')));
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($result['preview'] !== []) {
            $this->table(array_keys($result['preview'][0]), $result['preview']);
        }

        $this->line(sprintf(
            '%s as %s — pledges: %d | transactions: %d',
            $dryRun ? 'Would flag' : 'Flagged',
            $label,
            $result['pledges'],
            $result['transactions'],
        ));

        if ($dryRun) {
            $this->warn('Dry run complete. Re-run with --force to apply.');
        }

        return self::SUCCESS;
    }
}
