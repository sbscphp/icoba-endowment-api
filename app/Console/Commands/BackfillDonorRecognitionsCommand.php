<?php

namespace App\Console\Commands;

use App\Services\Recognition\DonorRecognitionBackfillService;
use Illuminate\Console\Command;

class BackfillDonorRecognitionsCommand extends Command
{
    protected $signature = 'recognitions:backfill
                            {--tier= : Tier UUID or name (omit for all tiers with active templates)}
                            {--dry-run : List eligible donors without creating recognitions}
                            {--chunk= : Donor keys per batch (default from config)}
                            {--limit= : Max eligible donors to process per tier}
                            {--preview=100 : Max rows to show in dry-run table per tier}
                            {--no-email : Skip recognition emails when issuing}';

    protected $description = 'Backfill donor tier recognitions for qualified donors missing a certificate.';

    // # Dry run — counts eligible donors; optional preview table (first 100)
    // php artisan recognitions:backfill --dry-run
    // php artisan recognitions:backfill --dry-run --tier="Bronze Contributor" --preview=50

    // # Issue missing recognitions (chunked, default 500 per batch)
    // php artisan recognitions:backfill
    // php artisan recognitions:backfill --tier=<uuid-or-name> --chunk=500 --no-email

    public function handle(DonorRecognitionBackfillService $backfillService): int
    {
        $tierFilter = $this->option('tier');
        $tierFilter = is_string($tierFilter) && trim($tierFilter) !== '' ? trim($tierFilter) : null;

        $tiers = $backfillService->tiersEligibleForBackfill($tierFilter);
        if ($tiers === []) {
            $this->warn('No active tiers with an active certificate template found.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $chunkSize = $this->option('chunk') !== null ? (int) $this->option('chunk') : null;
        $limit = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $previewLimit = max(1, (int) $this->option('preview'));
        $dispatchEmail = ! (bool) $this->option('no-email');

        if ($dryRun) {
            $this->info('Dry run — no recognitions will be created or emails sent.');
        }

        $grandEligible = 0;
        $grandIssued = 0;

        foreach ($tiers as $tier) {
            $this->newLine();
            $this->info("Tier: {$tier->name} (min ₦".number_format((float) $tier->min_amount, 2).')');

            $result = $backfillService->backfillTier(
                $tier,
                dryRun: $dryRun,
                chunkSize: $chunkSize,
                dispatchEmail: $dispatchEmail,
                limit: $limit,
            );

            if ($dryRun && $previewLimit > 0) {
                $preview = $backfillService->previewMissingForTier($tier, $previewLimit, $chunkSize);

                if ($preview !== []) {
                    $this->table(
                        ['donor_key', 'awardee_name', 'cumulative_ngn', 'trigger_transaction_uuid', 'tier'],
                        array_map(static fn (array $row): array => [
                            (string) $row['donor_key'],
                            (string) ($row['awardee_name'] ?? '—'),
                            number_format((float) $row['cumulative_ngn'], 2),
                            (string) ($row['trigger_transaction_uuid'] ?? '—'),
                            (string) $row['tier'],
                        ], $preview),
                    );

                    if ($result['eligible'] > count($preview)) {
                        $this->line('Showing first '.count($preview).' of '.$result['eligible'].' eligible donor(s).');
                    }
                } elseif ($result['eligible'] === 0) {
                    $this->line('No eligible donors missing recognition for this tier.');
                }
            }

            $this->line(sprintf(
                'Scanned: %d | Eligible: %d | Issued: %d | Skipped (no trigger/name): %d',
                $result['scanned'],
                $result['eligible'],
                $result['issued'],
                $result['skipped_no_trigger'],
            ));

            $grandEligible += $result['eligible'];
            $grandIssued += $result['issued'];
        }

        $this->newLine();
        if ($dryRun) {
            $this->info("Dry run complete. {$grandEligible} donor(s) would receive recognition across ".count($tiers).' tier(s).');
        } else {
            $this->info("Backfill complete. Issued {$grandIssued} recognition(s); {$grandEligible} were eligible.");
        }

        return self::SUCCESS;
    }
}
