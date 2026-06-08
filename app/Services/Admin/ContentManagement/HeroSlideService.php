<?php

namespace App\Services\Admin\ContentManagement;

use App\Exceptions\ApiException;
use App\Helpers\FileUploadHelper;
use App\Models\HeroSlide;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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

    public function current(): ?HeroSlide
    {
        return HeroSlide::query()
            ->with('updatedByAdmin')
            ->orderBy('sort_order')
            ->orderBy('title')
            ->first();
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
