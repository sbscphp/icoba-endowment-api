<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Stub command for the future FCMB transaction polling integration.
 *
 * Once FCMB exposes an inbound transactions API (or webhook reliability requires
 * a polling fallback), this command will fetch new credits from FCMB and forward
 * them to the shared {@see \App\Services\Reconciliation\BankFeedIngestionService}.
 *
 * Until that contract is finalised, the command logs the request and exits cleanly
 * so it can be wired into the scheduler in preparation for go-live without affecting
 * production behaviour.
 */
class SyncFcmbTransactionsCommand extends Command
{
    protected $signature = 'fcmb:sync-transactions {--from= : Optional ISO date to fetch transactions from}';

    protected $description = 'Stub: poll FCMB for inbound endowment credits and forward to the bank feed ingestion pipeline.';

    public function handle(): int
    {
        $this->warn('FCMB transaction sync is not yet implemented — awaiting partnership contract.');

        $from = $this->option('from');
        if (is_string($from) && $from !== '') {
            $this->info(sprintf('Requested sync window starting %s.', $from));
        }

        return self::SUCCESS;
    }
}
