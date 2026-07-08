<?php

namespace App\Services\Notifications;

use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotification;

class NotificationInboxService
{
    /**
     * @param  Model&object{notifications(): mixed}  $recipient
     * @param  array<string, mixed>  $validated
     */
    public function paginate(Model $recipient, int $perPage = 15, int $page = 1, array $validated = []): LengthAwarePaginator
    {
        return $this->listQuery($recipient, $validated)
            ->paginate(perPage: $perPage, page: $page, pageName: 'page');
    }

    /**
     * @param  Model&object{notifications(): mixed}  $recipient
     * @param  array<string, mixed>  $validated
     * @return array{unread_count: int, read_count: int}
     */
    public function inboxCounts(Model $recipient, array $validated = []): array
    {
        $query = $this->scopedQuery($recipient, $validated);

        return [
            'unread_count' => (clone $query)->whereNull('read_at')->count(),
            'read_count' => (clone $query)->whereNotNull('read_at')->count(),
        ];
    }

    /**
     * @param  Model&object{notifications(): mixed}  $recipient
     */
    public function findForRecipient(Model $recipient, string $id): DatabaseNotification
    {
        /** @var DatabaseNotification $notification */
        $notification = $recipient->notifications()->whereKey($id)->firstOrFail();

        return $notification;
    }

    /**
     * @param  Model&object{unreadNotifications: mixed}  $recipient
     */
    public function markAllRead(Model $recipient): void
    {
        $recipient->unreadNotifications->markAsRead();
    }

    /**
     * @param  Model&object{notifications(): mixed}  $recipient
     */
    public function markRead(Model $recipient, string $id): DatabaseNotification
    {
        $notification = $this->findForRecipient($recipient, $id);
        $notification->markAsRead();

        return $notification->fresh();
    }

    /**
     * @param  Model&object{notifications(): mixed}  $recipient
     */
    public function markUnread(Model $recipient, string $id): DatabaseNotification
    {
        $notification = $this->findForRecipient($recipient, $id);
        $notification->markAsUnread();

        return $notification->fresh();
    }

    /**
     * @param  Model&object{notifications(): mixed}  $recipient
     */
    public function dismiss(Model $recipient, string $id): void
    {
        $this->findForRecipient($recipient, $id)->delete();
    }

    /**
     * @param  Model&object{notifications(): mixed}  $recipient
     * @param  array<string, mixed>  $validated
     */
    private function listQuery(Model $recipient, array $validated): Builder|Relation
    {
        $query = $this->scopedQuery($recipient, $validated)
            ->with('notifiable')
            ->latest();
        $this->applyReadStatusFilter($query, $validated);

        return $query;
    }

    /**
     * @param  Model&object{notifications(): mixed}  $recipient
     * @param  array<string, mixed>  $validated
     */
    private function scopedQuery(Model $recipient, array $validated): Builder|Relation
    {
        $query = $recipient->notifications();
        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        return $query;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyReadStatusFilter(Builder|Relation $query, array $validated): void
    {
        $status = strtolower((string) data_get($validated, 'filters.read_status', 'all'));

        match ($status) {
            'read' => $query->whereNotNull('read_at'),
            'unread' => $query->whereNull('read_at'),
            default => null,
        };
    }
}
