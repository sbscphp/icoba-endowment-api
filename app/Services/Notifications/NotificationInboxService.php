<?php

namespace App\Services\Notifications;

use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Notifications\DatabaseNotification;

class NotificationInboxService
{
    /**
     * @param  Model&object{notifications(): mixed}  $recipient
     * @param  array<string, mixed>  $validated
     */
    public function paginate(Model $recipient, int $perPage = 15, int $page = 1, array $validated = []): LengthAwarePaginator
    {
        $query = $recipient->notifications()->latest();

        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        return $query->paginate(perPage: $perPage, page: $page, pageName: 'page');
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
