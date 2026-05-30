<?php

namespace App\Console\Commands;

use App\Services\Recognition\DonorRecognitionSnapshotService;
use Illuminate\Console\Command;

class RegenerateDonorRecognitionSnapshotsCommand extends Command
{
    protected $signature = 'recognitions:regenerate-snapshots
                            {--template= : Certificate template UUID or name}
                            {--tier= : Tier UUID or name}
                            {--recognition= : Recognition UUID or number}
                            {--dry-run : Preview updates without saving}
                            {--force : Update even when snapshot already matches the template design}
                            {--images : Regenerate certificate images after updating snapshots}
                            {--sync : Process certificate images inline (requires --images)}
                            {--chunk= : Recognitions per batch (default from config)}';

    protected $description = 'Refresh donor recognition snapshot design from the linked or active certificate template.';

    // # Preview changes for one template
    // php artisan recognitions:regenerate-snapshots --template="Bronze Contributor Certificate" --dry-run
    //
    // # Apply snapshot refresh for all recognitions on a tier
    // php artisan recognitions:regenerate-snapshots --tier="Bronze Contributor"
    //
    // # Refresh one recognition and regenerate its certificate image
    // php artisan recognitions:regenerate-snapshots --recognition=<uuid-or-number> --images --sync

    public function handle(DonorRecognitionSnapshotService $snapshotService): int
    {
        $templateFilter = $this->normalizeOption('template');
        $tierFilter = $this->normalizeOption('tier');
        $recognitionFilter = $this->normalizeOption('recognition');

        if ($templateFilter !== null && $snapshotService->resolveTemplateFilter($templateFilter) === null) {
            $this->error("Certificate template not found: {$templateFilter}");

            return self::FAILURE;
        }

        if ($tierFilter !== null && $snapshotService->resolveTierFilter($tierFilter) === null) {
            $this->error("Tier not found: {$tierFilter}");

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $regenerateImages = (bool) $this->option('images');
        $syncImages = (bool) $this->option('sync');

        if ($syncImages && ! $regenerateImages) {
            $this->warn('--sync has no effect without --images.');
        }

        if ($dryRun) {
            $this->info('Dry run — snapshots will not be saved and images will not be regenerated.');
        }

        try {
            $stats = $snapshotService->regenerate([
                'template' => $templateFilter,
                'tier' => $tierFilter,
                'recognition' => $recognitionFilter,
                'dry_run' => $dryRun,
                'force' => (bool) $this->option('force'),
                'regenerate_images' => $regenerateImages,
                'sync_images' => $syncImages,
                'chunk' => $this->option('chunk') !== null ? (int) $this->option('chunk') : null,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(
            ['Metric', 'Count'],
            [
                ['Scanned', (string) $stats['scanned']],
                ['Updated', (string) $stats['updated']],
                ['Unchanged', (string) $stats['unchanged']],
                ['Skipped', (string) $stats['skipped']],
                ['Failed', (string) $stats['failed']],
            ],
        );

        if ($stats['scanned'] === 0) {
            $this->warn('No matching recognitions found.');
        } elseif ($dryRun) {
            $this->info('Dry run complete.');
        } else {
            $suffix = $regenerateImages
                ? ($syncImages ? ' Certificate images regenerated inline.' : ' Certificate image jobs queued.')
                : '';
            $this->info("Snapshot regeneration complete.{$suffix}");
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function normalizeOption(string $name): ?string
    {
        $value = $this->option($name);

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
