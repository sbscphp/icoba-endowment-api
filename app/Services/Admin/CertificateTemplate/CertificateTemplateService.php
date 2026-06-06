<?php

namespace App\Services\Admin\CertificateTemplate;

use App\Exceptions\ApiException;
use App\Helpers\FileUploadHelper;
use App\Jobs\BackfillDonorRecognitionForTierJob;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\CertificateTemplate;
use App\Models\TierConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class CertificateTemplateService
{
    private const UPLOAD_FOLDER = 'certificate-templates';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): CertificateTemplate
    {
        $tierUuid = $this->resolveTierUuid($payload['tier_id'] ?? null);
        $design = $this->normalizeDesign((array) ($payload['design'] ?? []));
        $isActive = (bool) ($payload['is_active'] ?? false);

        return DB::transaction(function () use ($payload, $tierUuid, $design, $isActive): CertificateTemplate {
            $template = CertificateTemplate::query()->create([
                'name' => (string) $payload['name'],
                'tier_uuid' => $tierUuid,
                'design' => $design,
                'is_active' => $isActive,
            ]);

            if ($isActive && $tierUuid !== null) {
                $this->deactivateSiblingsOnTier($tierUuid, $template->id);
            }

            $fresh = $template->fresh() ?? $template;
            if ($isActive) {
                $this->queueRecognitionBackfillForTemplate($fresh);
            }

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(string $templateId, array $payload): CertificateTemplate
    {
        $template = $this->resolveTemplate($templateId);

        $updates = [];

        if (array_key_exists('name', $payload)) {
            $updates['name'] = (string) $payload['name'];
        }

        if (array_key_exists('tier_id', $payload)) {
            $updates['tier_uuid'] = $this->resolveTierUuid($payload['tier_id']);
        }

        if (array_key_exists('design', $payload)) {
            $existingDesign = is_array($template->design) ? $template->design : [];
            $incomingDesign = $this->normalizeDesign((array) $payload['design']);
            $updates['design'] = array_replace_recursive($existingDesign, $incomingDesign);
        }

        if (array_key_exists('is_active', $payload)) {
            $updates['is_active'] = (bool) $payload['is_active'];
        }

        return DB::transaction(function () use ($template, $updates): CertificateTemplate {
            if ($updates !== []) {
                $template->fill($updates)->save();
            }

            $finalIsActive = (bool) $template->is_active;
            $finalTierUuid = $template->tier_uuid;

            if ($finalIsActive && $finalTierUuid !== null) {
                $this->deactivateSiblingsOnTier($finalTierUuid, $template->id);
            }

            $fresh = $template->fresh() ?? $template;
            if ($finalIsActive) {
                $this->queueRecognitionBackfillForTemplate($fresh);
            }

            return $fresh;
        });
    }

    public function toggleActiveStatus(string $templateId): CertificateTemplate
    {
        $template = $this->resolveTemplate($templateId);
        $nextActive = ! ((bool) $template->is_active);

        return DB::transaction(function () use ($template, $nextActive): CertificateTemplate {
            $template->forceFill(['is_active' => $nextActive])->save();

            if ($nextActive && $template->tier_uuid !== null) {
                $this->deactivateSiblingsOnTier($template->tier_uuid, $template->id);
            }

            $fresh = $template->fresh() ?? $template;
            if ($nextActive) {
                $this->queueRecognitionBackfillForTemplate($fresh);
            }

            return $fresh;
        });
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{total:int,active:int,inactive:int}
     */
    public function stats(array $validated): array
    {
        $query = CertificateTemplate::query();
        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        return array_merge(ListingFilterRules::periodMeta($validated), [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('is_active', true)->count(),
            'inactive' => (clone $query)->where('is_active', false)->count(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(array $validated): LengthAwarePaginator
    {
        $sortBy = (string) ($validated['sort_by'] ?? 'updated_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        $query = CertificateTemplate::query()->with('tier:uuid,name');

        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('uuid', 'like', '%'.$search.'%');
            });
        }

        $status = data_get($validated, 'filters.status');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $tierUuid = data_get($validated, 'filters.tier_id');
        if (is_string($tierUuid) && $tierUuid !== '') {
            $query->where('tier_uuid', $tierUuid);
        }

        if (! in_array($sortBy, ['name', 'is_active', 'created_at', 'updated_at'], true)) {
            $sortBy = 'updated_at';
        }

        return $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);
    }

    public function findTemplate(string $templateId): CertificateTemplate
    {
        $template = $this->resolveTemplate($templateId);
        $template->load('tier:uuid,name');

        return $template;
    }

    public function delete(string $templateId): void
    {
        $template = $this->resolveTemplate($templateId);
        $template->delete();
    }

    /**
     * @return Collection<int, CertificateTemplate>
     */
    public function dropdown(string $status = 'active'): Collection
    {
        $query = CertificateTemplate::query();

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        return $query
            ->orderBy('name')
            ->get(['uuid', 'name', 'is_active']);
    }

    /**
     * Replace any UploadedFile / base64 / URL values inside design payload with stored Cloudinary URLs.
     *
     * @param  array<string, mixed>  $design
     * @return array<string, mixed>
     */
    private function normalizeDesign(array $design): array
    {
        return $this->normalizeImageFieldsRecursively($design);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalizeImageFieldsRecursively(array $payload): array
    {
        foreach ($payload as $key => $value) {
            if (is_array($value)) {
                $payload[$key] = $this->normalizeImageFieldsRecursively($value);

                continue;
            }

            if (! is_string($key) || ! $this->isImageFieldKey($key)) {
                continue;
            }

            $payload[$key] = $this->uploadIfPresent($value);
        }

        return $payload;
    }

    private function isImageFieldKey(string $key): bool
    {
        if (! str_ends_with($key, '_url')) {
            return false;
        }

        $normalized = strtolower($key);
        foreach (['image', 'icon', 'seal', 'signature'] as $needle) {
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function uploadIfPresent(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return FileUploadHelper::smartSingleFileUpload($value, self::UPLOAD_FOLDER);
        } catch (InvalidArgumentException $e) {
            throw new ApiException('File upload failed: '.$e->getMessage(), 422);
        }
    }

    private function queueRecognitionBackfillForTemplate(CertificateTemplate $template): void
    {
        $tierUuid = $template->tier_uuid;
        if (! is_string($tierUuid) || $tierUuid === '' || ! (bool) $template->is_active) {
            return;
        }

        BackfillDonorRecognitionForTierJob::dispatch($tierUuid);
    }

    // Deactivate all siblings on the same tier
    private function deactivateSiblingsOnTier(string $tierUuid, int $templateId): void
    {
        CertificateTemplate::query()
            ->where('tier_uuid', $tierUuid)
            ->where('id', '!=', $templateId)
            ->where('is_active', true)
            ->update(['is_active' => false]);
    }

    private function resolveTierUuid(mixed $tierId): ?string
    {
        if ($tierId === null || $tierId === '') {
            return null;
        }

        $tier = TierConfiguration::query()
            ->where('uuid', (string) $tierId)
            ->first();

        if ($tier === null) {
            throw new ApiException('Tier configuration not found.', 422);
        }

        return $tier->uuid;
    }

    private function resolveTemplate(string $templateId): CertificateTemplate
    {
        $template = CertificateTemplate::query()
            ->where(function (Builder $builder) use ($templateId): void {
                $builder->where('uuid', $templateId);
                if (is_numeric($templateId)) {
                    $builder->orWhere('id', (int) $templateId);
                }
            })
            ->first();

        if ($template === null) {
            throw (new ModelNotFoundException)->setModel(CertificateTemplate::class, [$templateId]);
        }

        return $template;
    }

}
