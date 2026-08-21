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
        $query = $this->listQuery($recipient, $validated);

        return $query->paginate(perPage: $perPage, page: $page, pageName: 'page');
    }

    /**
     * @param  Model&object{notifications(): mixed}  $recipient
     * @param  array<string, mixed>  $validated
     * @return array{unread_count: int, read_count: int}
     */
    public function inboxCounts(Model $recipient, array $validated = []): array
    {
        $counts = $this->scopedQuery($recipient, $validated)
            ->reorder()
            ->toBase()
            ->selectRaw('COALESCE(SUM(CASE WHEN read_at IS NULL THEN 1 ELSE 0 END), 0) as unread_count')
            ->selectRaw('COALESCE(SUM(CASE WHEN read_at IS NOT NULL THEN 1 ELSE 0 END), 0) as read_count')
            ->first();

        return [
            'unread_count' => (int) ($counts->unread_count ?? 0),
            'read_count' => (int) ($counts->read_count ?? 0),
        ];
    }

    /**
     * @param  Model&object{notifications(): mixed}  $recipient
     * @param  array<string, mixed>  $validated
     */
    public function countUnread(Model $recipient, array $validated = []): int
    {
        return $this->inboxCounts($recipient, $validated)['unread_count'];
    }

    /**
     * @param  Model&object{notifications(): mixed}  $recipient
     * @param  array<string, mixed>  $validated
     */
    public function countRead(Model $recipient, array $validated = []): int
    {
        return $this->inboxCounts($recipient, $validated)['read_count'];
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
        $notification = $this->findForRecipient($recipient, $id);
        $notification->delete();
    }
}
