<?php

namespace App\Services\Admin\ContentManagement;

use App\Exceptions\ApiException;
use App\Helpers\FileUploadHelper;
use App\Helpers\GeneralHelper;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\Ad;
use App\Models\AdImage;
use App\Models\AdSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class AdService
{
    private const UPLOAD_FOLDER = 'ads';

    public const MAX_IMAGES = 10;

    public const MAX_INTERVAL_SECONDS = 300;

    public const DEFAULT_IMAGE_INTERVAL_SECONDS = 3;

    public const DEFAULT_TRANSITION_SECONDS = 5;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(array $validated): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        return $this->baseListQuery($validated)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, ?string $adminUuid = null): Ad
    {
        $this->assertValidWindow((string) $payload['starts_at'], (string) $payload['ends_at']);

        return DB::transaction(function () use ($payload, $adminUuid): Ad {
            $ad = Ad::query()->create([
                'ad_code' => $this->generateAdCode(),
                'title' => (string) $payload['title'],
                'target_url' => $payload['target_url'] ?? null,
                'image_interval_seconds' => (int) ($payload['image_interval_seconds'] ?? self::DEFAULT_IMAGE_INTERVAL_SECONDS),
                'starts_at' => (string) $payload['starts_at'],
                'ends_at' => (string) $payload['ends_at'],
                'is_active' => $this->resolveIsActive($payload, true),
                'sort_order' => (int) ($payload['sort_order'] ?? 0),
                'created_by_admin_uuid' => $adminUuid,
                'updated_by_admin_uuid' => $adminUuid,
            ]);

            $this->reconcileImages($ad, (array) $payload['images']);

            return $ad->fresh(['images']) ?? $ad;
        });
    }

    public function findAd(string $adId): Ad
    {
        return $this->resolveAd($adId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(string $adId, array $payload, ?string $adminUuid = null): Ad
    {
        $ad = $this->resolveAd($adId);

        $effectiveStartsAt = array_key_exists('starts_at', $payload) ? (string) $payload['starts_at'] : $ad->starts_at;
        $effectiveEndsAt = array_key_exists('ends_at', $payload) ? (string) $payload['ends_at'] : $ad->ends_at;
        $this->assertValidWindow($effectiveStartsAt, $effectiveEndsAt);

        return DB::transaction(function () use ($ad, $payload, $adminUuid): Ad {
            $updates = [];
            foreach (['title', 'target_url', 'image_interval_seconds', 'starts_at', 'ends_at', 'is_active', 'sort_order'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $updates[$key] = $payload[$key];
                }
            }

            // `status` is a convenience alias over `is_active`: "archived" archives the ad,
            // any other value (live/scheduled/expired) un-archives it — the actual label
            // shown back is still derived from is_active + the start/end window.
            if (array_key_exists('status', $payload) && $payload['status'] !== null) {
                $updates['is_active'] = $payload['status'] !== 'archived';
            }

            $imagesChanged = array_key_exists('images', $payload);
            if ($imagesChanged) {
                $this->reconcileImages($ad, (array) $payload['images']);
            }

            if ($updates !== [] || $imagesChanged) {
                $updates['updated_by_admin_uuid'] = $adminUuid;
                $ad->fill($updates)->save();
            }

            return $ad->fresh(['images']) ?? $ad;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveIsActive(array $payload, bool $default): bool
    {
        if (array_key_exists('status', $payload) && $payload['status'] !== null) {
            return $payload['status'] !== 'archived';
        }

        return (bool) ($payload['is_active'] ?? $default);
    }

    public function archive(string $adId, ?string $adminUuid = null): Ad
    {
        return $this->setActiveState($adId, false, $adminUuid);
    }

    public function reactivate(string $adId, ?string $adminUuid = null): Ad
    {
        return $this->setActiveState($adId, true, $adminUuid);
    }

    private function setActiveState(string $adId, bool $isActive, ?string $adminUuid = null): Ad
    {
        $ad = $this->resolveAd($adId);
        $ad->forceFill([
            'is_active' => $isActive,
            'updated_by_admin_uuid' => $adminUuid,
        ])->save();

        return $ad->fresh(['images']) ?? $ad;
    }

    public function delete(string $adId): void
    {
        $ad = $this->resolveAd($adId);
        $ad->delete();
    }

    /**
     * Reconciles an ad's gallery to exactly match the given list: existing Cloudinary
     * URLs are kept as-is (no re-upload), new base64/file entries are uploaded and inserted,
     * and any image not present in the list is removed. Order of $inputs becomes sort_order,
     * which is the order images rotate on the guest end.
     *
     * @param  array<int, mixed>  $inputs
     */
    public function syncImages(string $adId, array $inputs, ?string $adminUuid = null): Ad
    {
        $ad = $this->resolveAd($adId);

        DB::transaction(function () use ($ad, $inputs): void {
            $this->reconcileImages($ad, $inputs);
        });

        $ad->forceFill(['updated_by_admin_uuid' => $adminUuid])->save();

        return $ad->fresh(['images']) ?? $ad;
    }

    public function deleteImage(string $adId, string $imageId, ?string $adminUuid = null): Ad
    {
        $ad = $this->resolveAd($adId);

        $image = AdImage::query()
            ->where('ad_uuid', $ad->uuid)
            ->where(function (Builder $builder) use ($imageId): void {
                $builder->where('uuid', $imageId);
                if (is_numeric($imageId)) {
                    $builder->orWhere('id', (int) $imageId);
                }
            })
            ->first();

        if ($image === null) {
            throw (new ModelNotFoundException)->setModel(AdImage::class, [$imageId]);
        }

        if ($ad->images()->count() <= 1) {
            throw new ApiException('An ad must have at least one image. Add a replacement image before deleting this one.', 422);
        }

        $image->delete();
        $ad->forceFill(['updated_by_admin_uuid' => $adminUuid])->save();

        return $ad->fresh(['images']) ?? $ad;
    }

    public function settings(): AdSetting
    {
        return AdSetting::query()->first()
            ?? AdSetting::query()->create(['ads_transition_seconds' => self::DEFAULT_TRANSITION_SECONDS]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateSettings(array $payload, ?string $adminUuid = null): AdSetting
    {
        $settings = $this->settings();
        $settings->forceFill([
            'ads_transition_seconds' => (int) $payload['ads_transition_seconds'],
            'updated_by_admin_uuid' => $adminUuid,
        ])->save();

        return $settings->fresh() ?? $settings;
    }

    /**
     * @param  array<int, mixed>  $inputs
     */
    private function reconcileImages(Ad $ad, array $inputs): void
    {
        if (count($inputs) > self::MAX_IMAGES) {
            throw new ApiException(
                'You may have at most '.self::MAX_IMAGES.' images for an ad.',
                422
            );
        }

        try {
            // Existing http(s) URLs pass through unchanged; base64/file entries are uploaded.
            $finalUrls = array_values(array_unique(FileUploadHelper::smartMultipleFileUpload($inputs, self::UPLOAD_FOLDER)));
        } catch (InvalidArgumentException $e) {
            throw new ApiException('Image upload failed: '.$e->getMessage(), 422);
        }

        if ($finalUrls === []) {
            throw new ApiException('An ad must have at least one valid image.', 422);
        }

        $existingByUrl = $ad->images()->get()->keyBy('image_url');

        AdImage::query()
            ->where('ad_uuid', $ad->uuid)
            ->whereNotIn('image_url', $finalUrls)
            ->delete();

        foreach ($finalUrls as $index => $url) {
            $existing = $existingByUrl->get($url);
            if ($existing !== null) {
                if ((int) $existing->sort_order !== $index) {
                    $existing->forceFill(['sort_order' => $index])->save();
                }

                continue;
            }

            AdImage::query()->create([
                'ad_uuid' => $ad->uuid,
                'image_url' => $url,
                'sort_order' => $index,
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(array $validated): Builder
    {
        $query = Ad::query()->withCount('images');

        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.strtolower($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(ad_code) LIKE ?', [$like]);
            });
        }

        $isActive = data_get($validated, 'filters.is_active');
        if ($isActive !== null && $isActive !== '') {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        $status = data_get($validated, 'filters.status');
        if (is_string($status) && $status !== '') {
            $this->applyStatusFilter($query, $status);
        }

        $sortBy = (string) ($validated['sort_by'] ?? 'sort_order');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        if (! in_array($sortBy, ['title', 'starts_at', 'ends_at', 'is_active', 'sort_order', 'created_at', 'updated_at'], true)) {
            $sortBy = 'sort_order';
        }

        return $query->orderBy($sortBy, $sortDirection)->orderBy('created_at', 'desc');
    }

    private function applyStatusFilter(Builder $query, string $status): void
    {
        $now = Carbon::now();

        match ($status) {
            'archived' => $query->where('is_active', false),
            'scheduled' => $query->where('is_active', true)->where('starts_at', '>', $now),
            'live' => $query->where('is_active', true)
                ->where('starts_at', '<=', $now)
                ->where('ends_at', '>=', $now),
            'expired' => $query->where('is_active', true)->where('ends_at', '<', $now),
            default => null,
        };
    }

    private function resolveAd(string $adId): Ad
    {
        $ad = Ad::query()
            ->where(function (Builder $builder) use ($adId): void {
                $builder->where('uuid', $adId);
                if (is_numeric($adId)) {
                    $builder->orWhere('id', (int) $adId);
                }
                $builder->orWhere('ad_code', $adId);
            })
            ->first();

        if ($ad === null) {
            throw (new ModelNotFoundException)->setModel(Ad::class, [$adId]);
        }

        return $ad;
    }

    private function generateAdCode(): string
    {
        $result = GeneralHelper::getModelUniqueRandomId([
            'modelNamespace' => Ad::class,
            'modelField' => 'ad_code',
            'prefix' => 'ICBAD-',
            'idLength' => 4,
            'idType' => 'numalpha_upper',
        ]);

        if (is_array($result) && isset($result['error'])) {
            throw new ApiException('Could not generate unique advertisement code.', 422);
        }

        return (string) $result;
    }

    private function assertValidWindow(mixed $startsAt, mixed $endsAt): void
    {
        $start = $startsAt instanceof Carbon ? $startsAt : Carbon::parse((string) $startsAt);
        $end = $endsAt instanceof Carbon ? $endsAt : Carbon::parse((string) $endsAt);

        if ($end->lessThanOrEqualTo($start)) {
            throw new ApiException('Ad end date and time must be after the start date and time.', 422);
        }
    }
}
