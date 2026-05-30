<?php

namespace App\Services\Recognition;

use App\Enums\IssuedCertificateStatus;
use App\Jobs\GenerateCertificateImageJob;
use App\Models\CertificateTemplate;
use App\Models\DonorRecognition;
use App\Models\TierConfiguration;
use Illuminate\Database\Eloquent\Builder;

final class DonorRecognitionSnapshotService
{
    public function __construct(
        private readonly DonorRecognitionService $recognitionService,
        private readonly CertificateImageService $certificateImageService,
    ) {}

    /**
     * @param  array{
     *     template?: string|null,
     *     tier?: string|null,
     *     recognition?: string|null,
     *     dry_run?: bool,
     *     force?: bool,
     *     regenerate_images?: bool,
     *     sync_images?: bool,
     *     chunk?: int|null,
     * }  $options
     * @return array{scanned: int, updated: int, unchanged: int, skipped: int, failed: int}
     */
    public function regenerate(array $options): array
    {
        $forcedTemplate = $this->resolveTemplateFilter($options['template'] ?? null);
        $forcedTier = $this->resolveTierFilter($options['tier'] ?? null);
        $recognitionFilter = $this->normalizeFilter($options['recognition'] ?? null);

        if ($forcedTemplate === null && $forcedTier !== null) {
            $forcedTemplate = $this->recognitionService->resolveActiveTemplateForTier($forcedTier);
            if ($forcedTemplate === null) {
                throw new \InvalidArgumentException('No active certificate template is available for the selected tier.');
            }
        }
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $force = (bool) ($options['force'] ?? false);
        $regenerateImages = (bool) ($options['regenerate_images'] ?? false);
        $syncImages = (bool) ($options['sync_images'] ?? false);
        $chunkSize = max(25, min((int) ($options['chunk'] ?? config('recognitions.backfill_chunk_size', 500)), 500));

        if ($forcedTemplate === null && $forcedTier === null && $recognitionFilter === null) {
            throw new \InvalidArgumentException('Specify at least one filter: --template, --tier, or --recognition.');
        }

        $query = $this->baseQuery($forcedTemplate, $forcedTier, $recognitionFilter);

        $stats = [
            'scanned' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'skipped' => 0,
            'failed' => 0,
        ];

        $query->with(['tier', 'certificateTemplate'])
            ->orderBy('id')
            ->chunkById($chunkSize, function ($recognitions) use (
                $forcedTemplate,
                $dryRun,
                $force,
                $regenerateImages,
                $syncImages,
                &$stats,
            ): void {
                foreach ($recognitions as $recognition) {
                    $stats['scanned']++;

                    try {
                        $result = $this->regenerateOne(
                            $recognition,
                            $forcedTemplate,
                            $dryRun,
                            $force,
                            $regenerateImages,
                            $syncImages,
                        );

                        $stats[$result]++;
                    } catch (\Throwable) {
                        $stats['failed']++;
                    }
                }
            });

        return $stats;
    }

    /**
     * @return 'updated'|'unchanged'|'skipped'
     */
    public function regenerateOne(
        DonorRecognition $recognition,
        ?CertificateTemplate $forcedTemplate = null,
        bool $dryRun = false,
        bool $force = false,
        bool $regenerateImages = false,
        bool $syncImages = false,
    ): string {
        if ($recognition->status === IssuedCertificateStatus::REVOKED) {
            return 'skipped';
        }

        $template = $this->resolveTemplateForRecognition($recognition, $forcedTemplate);
        if ($template === null) {
            return 'skipped';
        }

        $tier = $recognition->tier;
        if ($tier === null) {
            return 'skipped';
        }

        $newSnapshot = $this->buildSnapshotFromTemplate($recognition, $template, $tier);

        if (! $force && ! $this->snapshotDiffers($recognition->snapshot, $newSnapshot)) {
            return 'unchanged';
        }

        if ($dryRun) {
            return 'updated';
        }

        $recognition->forceFill([
            'snapshot' => $newSnapshot,
            'certificate_template_uuid' => $template->uuid,
        ])->save();

        if ($regenerateImages) {
            if ($syncImages) {
                $this->certificateImageService->ensureCertificateImageUrl($recognition->fresh(), force: true);
            } else {
                GenerateCertificateImageJob::dispatch($recognition->uuid, force: true);
            }
        }

        return 'updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function buildSnapshotFromTemplate(
        DonorRecognition $recognition,
        CertificateTemplate $template,
        TierConfiguration $tier,
    ): array {
        $existing = is_array($recognition->snapshot) ? $recognition->snapshot : [];
        $design = is_array($template->design) ? $template->design : [];

        $snapshot = [
            'tier_name' => $tier->name,
            'template_name' => $template->name,
            'design' => $design,
        ];

        foreach (['initial_amount', 'initial_currency', 'reissued_at'] as $key) {
            if (array_key_exists($key, $existing)) {
                $snapshot[$key] = $existing[$key];
            }
        }

        if ($recognition->initial_amount !== null && ! array_key_exists('initial_amount', $snapshot)) {
            $snapshot['initial_amount'] = (string) $recognition->initial_amount;
        }

        if ($recognition->initial_currency !== null && ! array_key_exists('initial_currency', $snapshot)) {
            $snapshot['initial_currency'] = $recognition->initial_currency;
        }

        return $snapshot;
    }

    public function resolveTemplateForRecognition(
        DonorRecognition $recognition,
        ?CertificateTemplate $forcedTemplate = null,
    ): ?CertificateTemplate {
        if ($forcedTemplate !== null) {
            return $forcedTemplate;
        }

        $recognition->loadMissing('certificateTemplate', 'tier');

        if ($recognition->certificateTemplate !== null) {
            return $recognition->certificateTemplate;
        }

        $tier = $recognition->tier;
        if ($tier === null) {
            return null;
        }

        return $this->recognitionService->resolveActiveTemplateForTier($tier);
    }

    public function resolveTemplateFilter(?string $filter): ?CertificateTemplate
    {
        $filter = $this->normalizeFilter($filter);
        if ($filter === null) {
            return null;
        }

        return CertificateTemplate::query()
            ->where(function (Builder $builder) use ($filter): void {
                $builder->where('uuid', $filter)
                    ->orWhere('name', $filter);
            })
            ->first();
    }

    public function resolveTierFilter(?string $filter): ?TierConfiguration
    {
        $filter = $this->normalizeFilter($filter);
        if ($filter === null) {
            return null;
        }

        return TierConfiguration::query()
            ->where(function (Builder $builder) use ($filter): void {
                $builder->where('uuid', $filter)
                    ->orWhere('name', $filter);
            })
            ->first();
    }

    /**
     * @param  mixed  $existing
     * @param  array<string, mixed>  $candidate
     */
    private function snapshotDiffers(mixed $existing, array $candidate): bool
    {
        if (! is_array($existing)) {
            return true;
        }

        foreach (['tier_name', 'template_name'] as $key) {
            if (($existing[$key] ?? null) !== ($candidate[$key] ?? null)) {
                return true;
            }
        }

        return $this->normalizeJson($existing['design'] ?? null)
            !== $this->normalizeJson($candidate['design'] ?? null);
    }

    private function normalizeJson(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function baseQuery(
        ?CertificateTemplate $template,
        ?TierConfiguration $tier,
        ?string $recognitionFilter,
    ): Builder {
        $query = DonorRecognition::query()
            ->where('status', '!=', IssuedCertificateStatus::REVOKED);

        if ($recognitionFilter !== null) {
            $query->where(function (Builder $builder) use ($recognitionFilter): void {
                $builder->where('uuid', $recognitionFilter)
                    ->orWhere('recognition_number', strtoupper($recognitionFilter));

                if (is_numeric($recognitionFilter)) {
                    $builder->orWhere('id', (int) $recognitionFilter);
                }
            });
        }

        if ($template !== null) {
            $query->where('certificate_template_uuid', $template->uuid);
        }

        if ($tier !== null) {
            $query->where('tier_uuid', $tier->uuid);
        }

        return $query;
    }

    private function normalizeFilter(?string $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
