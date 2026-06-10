<?php

namespace App\Console\Commands;

use App\Services\Reconciliation\AdminManualReconciliationBackfillService;
use Illuminate\Console\Command;

class BackfillAdminManualReconciliationCommand extends Command
{
    protected $signature = 'reconciliation:backfill-admin-manual
                            {--dry-run : Preview fixes without writing changes}
                            {--skip-finalize : Leave pending admin records with a campaign or pledge in the queue}
                            {--no-email : Suppress confirmation and tax receipt emails when finalizing}
                            {--chunk=100 : Records per batch}
                            {--transaction= : Process a single transaction UUID only}
                            {--preview=50 : Max rows to show in dry-run output}';

    protected $description = 'Backfill donor details, finalize traceable admin-manual reconciliations, and delete records without a resolvable donor.';

    // # Preview — rows without a traceable donor show "delete"
    // php artisan reconciliation:backfill-admin-manual --dry-run

    // # Apply — backfill donors, finalize traceable pending rows, delete unsalvageable rows
    // php artisan reconciliation:backfill-admin-manual --no-email

    public function handle(AdminManualReconciliationBackfillService $backfillService): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $finalizeLinked = ! (bool) $this->option('skip-finalize');
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

        if (! $finalizeLinked) {
            $this->warn('Finalization is disabled. Pending admin records with a campaign or pledge will remain in the queue.');
        }

        $result = $backfillService->run(
            dryRun: $dryRun,
            finalizeLinked: $finalizeLinked,
            suppressEmails: $suppressEmails,
            chunkSize: $chunkSize,
            transactionUuid: $transactionUuid,
            previewLimit: $previewLimit,
        );

        if ($previewLimit > 0 && $result['preview'] !== []) {
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
            'Scanned: %d | Cleared awaiting verification: %d | Donor backfilled: %d | %s: %d | Deleted: %d | Unchanged: %d',
            $result['scanned'],
            $result['cleared_awaiting_verification'],
            $result['donor_backfilled'],
            $dryRun ? 'Would finalize' : 'Finalized',
            $result['finalized'],
            $result['deleted'],
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
