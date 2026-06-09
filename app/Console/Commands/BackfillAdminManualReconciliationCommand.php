<?php

namespace App\Console\Commands;

use App\Services\Reconciliation\AdminManualReconciliationBackfillService;
use Illuminate\Console\Command;

class BackfillAdminManualReconciliationCommand extends Command
{
    protected $signature = 'reconciliation:backfill-admin-manual
                            {--dry-run : Preview fixes without writing changes}
                            {--finalize-linked : Finalize pending admin records that already have a campaign or pledge}
                            {--no-email : Suppress confirmation and tax receipt emails when finalizing}
                            {--chunk=100 : Records per batch}
                            {--transaction= : Process a single transaction UUID only}
                            {--preview=50 : Max rows to show in dry-run output}';

    protected $description = 'Backfill donor details and clear incorrect awaiting-verification flags on admin-manual reconciliation records.';

    // # Preview affected records
    // php artisan reconciliation:backfill-admin-manual --dry-run

    // # Apply donor + status fixes
    // php artisan reconciliation:backfill-admin-manual

    // # Also finalize pending rows that already have campaign_uuid or pledge_uuid
    // php artisan reconciliation:backfill-admin-manual --finalize-linked --no-email

    public function handle(AdminManualReconciliationBackfillService $backfillService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $finalizeLinked = (bool) $this->option('finalize-linked');
        $suppressEmails = (bool) $this->option('no-email');
        $chunkSize = max(1, (int) $this->option('chunk'));
        $previewLimit = max(0, (int) $this->option('preview'));

        $transactionUuid = $this->option('transaction');
        $transactionUuid = is_string($transactionUuid) && trim($transactionUuid) !== ''
            ? trim($transactionUuid)
            : null;

        if ($dryRun) {
            $this->info('Dry run — no records will be updated.');
        }

        if ($finalizeLinked && $dryRun) {
            $this->warn('--finalize-linked is ignored during --dry-run.');
        }

        $result = $backfillService->run(
            dryRun: $dryRun,
            finalizeLinked: $finalizeLinked,
            suppressEmails: $suppressEmails,
            chunkSize: $chunkSize,
            transactionUuid: $transactionUuid,
            previewLimit: $previewLimit,
        );

        if ($dryRun && $previewLimit > 0 && $result['preview'] !== []) {
            $this->table(
                ['transaction_id', 'uuid', 'changes'],
                array_map(static fn (array $row): array => [
                    $row['transaction_id'],
                    $row['uuid'],
                    implode(', ', $row['changes']),
                ], $result['preview']),
            );

            $changed = $result['scanned'] - $result['unchanged'];
            if ($changed > count($result['preview'])) {
                $this->line('Showing first '.count($result['preview'])." of {$changed} record(s) that would change.");
            }
        }

        $this->newLine();
        $this->line(sprintf(
            'Scanned: %d | Cleared awaiting verification: %d | Donor backfilled: %d | Finalized: %d | Unchanged: %d',
            $result['scanned'],
            $result['cleared_awaiting_verification'],
            $result['donor_backfilled'],
            $result['finalized'],
            $result['unchanged'],
        ));

        if ($dryRun) {
            $wouldChange = $result['scanned'] - $result['unchanged'];
            $this->info("Dry run complete. {$wouldChange} record(s) would be updated.");
        } else {
            $this->info('Backfill complete.');
        }

        return self::SUCCESS;
    }
}
