<?php

namespace App\Console\Commands;

use App\Services\Maintenance\DonationTestDataService;
use Illuminate\Console\Command;

class PurgeDonationDataCommand extends Command
{
    protected $signature = 'donations:purge
                            {--scope=test : test = only records flagged is_test, all = every pledge and transaction}
                            {--force : Actually delete (without it the command only reports what would go)}';

    protected $description = 'Permanently delete pledges, schedules, transactions, receipts and the tier recognitions they triggered — test data only, or everything.';

    // # Preview (default — nothing is deleted)
    // php artisan donations:purge
    // php artisan donations:purge --scope=all

    // # Delete test data only; live records are untouched
    // php artisan donations:purge --scope=test --force

    // # Wipe every pledge and transaction (asks for confirmation)
    // php artisan donations:purge --scope=all --force

    public function handle(DonationTestDataService $service): int
    {
        $scope = strtolower(trim((string) $this->option('scope')));
        if (! in_array($scope, [DonationTestDataService::SCOPE_TEST, DonationTestDataService::SCOPE_ALL], true)) {
            $this->error('--scope must be "test" or "all".');

            return self::FAILURE;
        }

        $dryRun = ! (bool) $this->option('force');

        if ($dryRun) {
            $this->info('Dry run — nothing will be deleted.');
        } elseif ($scope === DonationTestDataService::SCOPE_ALL
            && ! $this->confirm('This permanently deletes EVERY pledge and transaction, live ones included, on "'.config('database.default').'/'.config('database.connections.'.config('database.default').'.database').'". Continue?')) {
            $this->warn('Aborted.');

            return self::FAILURE;
        }

        $result = $service->purge($scope, $dryRun);

        $this->table(['Record', $dryRun ? 'Would delete / update' : 'Deleted / updated'], [
            ['Transactions', (string) $result['transactions']],
            ['Transaction receipts', (string) $result['receipts']],
            ['Tier recognitions', (string) $result['recognitions']],
            ['Pledges (incl. schedules and reminder history)', (string) $result['pledges']],
            ['Live pledges with status recomputed', (string) $result['live_pledges_refreshed']],
            ['Giving identities unlocked', (string) $result['giving_identities_unlocked']],
        ]);

        if ($result['pledges_kept_with_live_payments'] !== []) {
            $this->warn('Test pledges kept because they have live payments (fix the flag with donations:mark-test):');
            foreach ($result['pledges_kept_with_live_payments'] as $uuid) {
                $this->line('  '.$uuid);
            }
        }

        if ($dryRun) {
            $this->warn('Dry run complete. Re-run with --force to delete.');
        } elseif ($result['recognitions'] > 0 && $scope === DonationTestDataService::SCOPE_TEST) {
            $this->line('Donors who still qualify from live donations: php artisan recognitions:backfill --dry-run');
        }

        return self::SUCCESS;
    }
}
