<?php

namespace App\Services\Admin\Event;

use App\Enums\EventStatus;
use App\Exceptions\ApiException;
use App\Helpers\FileUploadHelper;
use App\Helpers\GeneralHelper;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\Event;
use App\Models\EventImage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EventService
{
    private const UPLOAD_FOLDER = 'events';

    public const MAX_GALLERY_IMAGES = 40;

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
    public function create(array $payload, ?string $adminUuid = null): Event
    {
        return DB::transaction(function () use ($payload, $adminUuid): Event {
            $event = Event::query()->create([
                'event_id' => $this->generateEventPublicId(),
                'title' => (string) $payload['title'],
                'short_description' => (string) $payload['short_description'],
                'long_description' => (string) $payload['long_description'],
                'event_date' => (string) $payload['event_date'],
                'banner_url' => $this->uploadBanner($payload['banner']),
                'status' => $payload['status'] ?? EventStatus::DRAFT->value,
                'created_by_admin_uuid' => $adminUuid,
                'updated_by_admin_uuid' => $adminUuid,
            ]);

            $images = (array) ($payload['images'] ?? []);
            if ($images !== []) {
                $this->reconcileImages($event, $images);
            }

            return $event->fresh(['images']) ?? $event;
        });
    }

    public function findEvent(string $eventId): Event
    {
        return $this->resolveEvent($eventId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(string $eventId, array $payload, ?string $adminUuid = null): Event
    {
        $event = $this->resolveEvent($eventId);

        return DB::transaction(function () use ($event, $payload, $adminUuid): Event {
            $updates = [];
            foreach (['title', 'short_description', 'long_description', 'event_date'] as $key) {
                if (array_key_exists($key, $payload)) {
                    $updates[$key] = $payload[$key];
                }
            }

            if (array_key_exists('banner', $payload)) {
                $updates['banner_url'] = $this->uploadBanner($payload['banner']);
            }

            if (array_key_exists('status', $payload)) {
                $updates['status'] = $payload['status'];
            }

            $imagesChanged = array_key_exists('images', $payload);
            if ($imagesChanged) {
                $this->reconcileImages($event, (array) $payload['images']);
            }

            if ($updates !== [] || $imagesChanged) {
                $updates['updated_by_admin_uuid'] = $adminUuid;
                $event->fill($updates)->save();
            }

            return $event->fresh(['images']) ?? $event;
        });
    }

    public function setStatus(string $eventId, string $status, ?string $adminUuid = null): Event
    {
        $event = $this->resolveEvent($eventId);
        $event->forceFill([
            'status' => EventStatus::from($status),
            'updated_by_admin_uuid' => $adminUuid,
        ])->save();

        return $event->fresh() ?? $event;
    }

    public function delete(string $eventId): void
    {
        $event = $this->resolveEvent($eventId);
        $event->delete();
    }

    /**
     * Reconciles an event's gallery to exactly match the given list: existing Cloudinary
     * URLs are kept as-is (no re-upload), new base64/file entries are uploaded and inserted,
     * and any image not present in the list is removed. Order of $inputs becomes sort_order.
     *
     * @param  array<int, mixed>  $inputs
     */
    public function syncImages(string $eventId, array $inputs, ?string $adminUuid = null): Event
    {
        $event = $this->resolveEvent($eventId);

        DB::transaction(function () use ($event, $inputs): void {
            $this->reconcileImages($event, $inputs);
        });

        $event->forceFill(['updated_by_admin_uuid' => $adminUuid])->save();

        return $event->fresh(['images']) ?? $event;
    }

    /**
     * @param  array<int, mixed>  $inputs
     */
    private function reconcileImages(Event $event, array $inputs): void
    {
        if (count($inputs) > self::MAX_GALLERY_IMAGES) {
            throw new ApiException(
                'You may have at most '.self::MAX_GALLERY_IMAGES.' images for an event.',
                422
            );
        }

        try {
            // Existing http(s) URLs pass through unchanged; base64/file entries are uploaded.
            $finalUrls = array_values(array_unique(FileUploadHelper::smartMultipleFileUpload($inputs, self::UPLOAD_FOLDER)));
        } catch (InvalidArgumentException $e) {
            throw new ApiException('Image upload failed: '.$e->getMessage(), 422);
        }

        if ($inputs !== [] && $finalUrls === []) {
            throw new ApiException('No valid images were uploaded.', 422);
        }

        $existingByUrl = $event->images()->get()->keyBy('image_url');

        EventImage::query()
            ->where('event_uuid', $event->uuid)
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

            EventImage::query()->create([
                'event_uuid' => $event->uuid,
                'image_url' => $url,
                'sort_order' => $index,
            ]);
        }
    }

    public function deleteImage(string $eventId, string $imageId, ?string $adminUuid = null): Event
    {
        $event = $this->resolveEvent($eventId);

        $image = EventImage::query()
            ->where('event_uuid', $event->uuid)
            ->where(function (Builder $builder) use ($imageId): void {
                $builder->where('uuid', $imageId);
                if (is_numeric($imageId)) {
                    $builder->orWhere('id', (int) $imageId);
                }
            })
            ->first();

        if ($image === null) {
            throw (new ModelNotFoundException)->setModel(EventImage::class, [$imageId]);
        }

        $image->delete();
        $event->forceFill(['updated_by_admin_uuid' => $adminUuid])->save();

        return $event->fresh(['images']) ?? $event;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(array $validated): Builder
    {
        $query = Event::query()->withCount('images');

        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.strtolower($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->whereRaw('LOWER(title) LIKE ?', [$like]);
                    // ->orWhereRaw('LOWER(event_id) LIKE ?', [$like])
                    // ->orWhereRaw('LOWER(short_description) LIKE ?', [$like]);
            });
        }

        $status = data_get($validated, 'filters.status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        if (! in_array($sortBy, ['title', 'event_date', 'status', 'created_at', 'updated_at'], true)) {
            $sortBy = 'created_at';
        }

        return $query->orderBy($sortBy, $sortDirection);
    }

    private function resolveEvent(string $eventId): Event
    {
        $event = Event::query()
            ->where(function (Builder $builder) use ($eventId): void {
                $builder->where('uuid', $eventId);
                if (is_numeric($eventId)) {
                    $builder->orWhere('id', (int) $eventId);
                }
                $builder->orWhere('event_id', $eventId);
            })
            ->first();

        if ($event === null) {
            throw (new ModelNotFoundException)->setModel(Event::class, [$eventId]);
        }

        return $event;
    }

    private function generateEventPublicId(): string
    {
        $result = GeneralHelper::getModelUniqueRandomId([
            'modelNamespace' => Event::class,
            'modelField' => 'event_id',
            'prefix' => 'EVT-',
            'idLength' => 6,
            'idType' => 'numalpha_upper',
        ]);

        if (is_array($result) && isset($result['error'])) {
            throw new ApiException('Could not generate unique event code.', 422);
        }

        return (string) $result;
    }

    private function uploadBanner(mixed $input): string
    {
        if ($input === null || $input === '') {
            throw new ApiException('Event banner is required.', 422);
        }

        try {
            $url = FileUploadHelper::smartSingleFileUpload($input, self::UPLOAD_FOLDER);
        } catch (InvalidArgumentException $e) {
            throw new ApiException('Banner upload failed: '.$e->getMessage(), 422);
        }

        if ($url === null || $url === '') {
            throw new ApiException('Banner upload failed.', 422);
        }

        return $url;
    }
}
