<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Notifications\DatabaseNotification;

/** @mixin DatabaseNotification */
class DatabaseNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DatabaseNotification $notification */
        $notification = $this->resource;
        $data = is_array($notification->data) ? $notification->data : [];
        $recipient = $notification->notifiable;
        $emailNotificationsEnabled = (bool) data_get($recipient, 'email_notifications_enabled', true);
        $pushNotificationsEnabled = (bool) data_get($recipient, 'push_notifications_enabled', true);

        return [
            'id' => $notification->id,
            'type' => (string) ($data['event'] ?? class_basename((string) $notification->type)),
            'title' => (string) ($data['title'] ?? 'Notification'),
            'message' => (string) ($data['message'] ?? ''),
            'read_at' => $notification->read_at?->toIso8601String(),
            'email_notifications_enabled' => $emailNotificationsEnabled,
            'push_notifications_enabled' => $pushNotificationsEnabled,
            'created_at' => $notification->created_at?->toIso8601String(),
            'updated_at' => $notification->updated_at?->toIso8601String(),
            // 'data' => $data,
        ];
    }
}
