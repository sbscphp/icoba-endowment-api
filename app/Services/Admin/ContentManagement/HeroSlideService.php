<?php

namespace App\Services\Admin\ContentManagement;

use App\Exceptions\ApiException;
use App\Helpers\FileUploadHelper;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\HeroSlide;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

class HeroSlideService
{
    private const UPLOAD_FOLDER = 'hero-slides';

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, ?string $adminUuid = null): HeroSlide
    {
        $slide = HeroSlide::query()->create([
            'title' => (string) $payload['title'],
            'banner_url' => $this->uploadBanner($payload['banner_url']),
            'primary_cta_url' => (string) $payload['primary_cta_url'],
            'primary_cta_text' => (string) $payload['primary_cta_text'],
            'secondary_cta_url' => (string) $payload['secondary_cta_url'],
            'secondary_cta_text' => (string) $payload['secondary_cta_text'],
            'sort_order' => (int) ($payload['sort_order'] ?? $this->nextSortOrder()),
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'is_deletable' => true,
            'created_by_admin_uuid' => $adminUuid,
            'updated_by_admin_uuid' => $adminUuid,
        ]);

        return $slide->fresh(['updatedByAdmin']) ?? $slide;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(array $validated): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        return $this->baseListQuery($validated)->paginate($perPage);
    }

    public function findSlide(string $slideId): HeroSlide
    {
        return $this->resolveSlide($slideId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(string $slideId, array $payload, ?string $adminUuid = null): HeroSlide
    {
        $slide = $this->resolveSlide($slideId);

        $updates = [];
        foreach (['title', 'primary_cta_url', 'primary_cta_text', 'secondary_cta_url', 'secondary_cta_text', 'sort_order'] as $key) {
            if (array_key_exists($key, $payload)) {
                $updates[$key] = $payload[$key];
            }
        }

        if (array_key_exists('banner_url', $payload)) {
            $updates['banner_url'] = $this->uploadBanner($payload['banner_url']);
        }

        if (array_key_exists('is_active', $payload)) {
            $updates['is_active'] = (bool) $payload['is_active'];
        }

        if ($updates !== []) {
            $updates['updated_by_admin_uuid'] = $adminUuid;
            $slide->fill($updates)->save();
        }

        return $slide->fresh(['updatedByAdmin']) ?? $slide;
    }

    public function toggleActiveStatus(string $slideId, ?string $adminUuid = null): HeroSlide
    {
        $slide = $this->resolveSlide($slideId);
        $slide->forceFill([
            'is_active' => ! ((bool) $slide->is_active),
            'updated_by_admin_uuid' => $adminUuid,
        ])->save();

        return $slide->fresh(['updatedByAdmin']) ?? $slide;
    }

    public function delete(string $slideId): void
    {
        $slide = $this->resolveSlide($slideId);

        if (! (bool) $slide->is_deletable) {
            throw new ApiException('This hero slide cannot be deleted. You can deactivate it instead.', 422);
        }

        $slide->delete();
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(array $validated): Builder
    {
        $sortBy = (string) ($validated['sort_by'] ?? 'sort_order');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = HeroSlide::query()->with('updatedByAdmin');

        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('title', 'like', '%'.$search.'%')
                    ->orWhere('uuid', 'like', '%'.$search.'%');
            });
        }

        $status = data_get($validated, 'filters.status');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if (! in_array($sortBy, ['title', 'sort_order', 'is_active', 'created_at', 'updated_at'], true)) {
            $sortBy = 'sort_order';
        }

        return $query->orderBy($sortBy, $sortDirection);
    }

    private function resolveSlide(string $slideId): HeroSlide
    {
        $slide = HeroSlide::query()
            ->where(function (Builder $builder) use ($slideId): void {
                $builder->where('uuid', $slideId);
                if (is_numeric($slideId)) {
                    $builder->orWhere('id', (int) $slideId);
                }
            })
            ->first();

        if ($slide === null) {
            throw (new ModelNotFoundException)->setModel(HeroSlide::class, [$slideId]);
        }

        return $slide;
    }

    private function uploadBanner(mixed $value): string
    {
        if ($value === null || $value === '') {
            throw new ApiException('Banner image is required.', 422);
        }

        try {
            $uploaded = FileUploadHelper::smartSingleFileUpload($value, self::UPLOAD_FOLDER);
        } catch (InvalidArgumentException $e) {
            throw new ApiException('Banner upload failed: '.$e->getMessage(), 422);
        }

        if ($uploaded === null || $uploaded === '') {
            throw new ApiException('Banner image is required.', 422);
        }

        return $uploaded;
    }

    private function nextSortOrder(): int
    {
        $maxSortOrder = HeroSlide::query()->max('sort_order');

        return $maxSortOrder !== null ? ((int) $maxSortOrder + 1) : 0;
    }
}
