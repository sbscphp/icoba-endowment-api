<?php

namespace App\Services\Public;

use App\Enums\EventStatus;
use App\Models\Event;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PublicEventService
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 15), 100));
        $sortBy = (string) ($filters['sort_by'] ?? 'event_date');
        $sortDirection = strtolower((string) ($filters['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        if (! in_array($sortBy, ['title', 'event_date', 'created_at'], true)) {
            $sortBy = 'event_date';
        }

        return $this->baseQuery($filters)
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);
    }

    public function find(string $uuid): Event
    {
        $event = Event::query()
            ->where('uuid', $uuid)
            ->where('status', EventStatus::PUBLISHED)
            ->with('images')
            ->first();

        if ($event === null) {
            throw (new ModelNotFoundException)->setModel(Event::class, [$uuid]);
        }

        return $event;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Event>
     */
    private function baseQuery(array $filters): Builder
    {
        $query = Event::query()->where('status', EventStatus::PUBLISHED);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$this->escapeLike($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('title', 'like', $like)
                    ->orWhere('short_description', 'like', $like);
            });
        }

        return $query;
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
