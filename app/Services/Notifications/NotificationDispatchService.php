<?php

namespace App\Services\Notifications;

use App\Enums\eRole;
use App\Models\Admin;
use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification as NotificationFacade;

class NotificationDispatchService
{
    /**
     * Active admins with the super_admin role (API guard).
     */
    public function notifySuperAdmins(Notification $notification, bool $queue = false): int
    {
        $uuids = Admin::query()
            ->role(eRole::SUPER_ADMIN->value)
            ->where('is_active', true)
            ->where('can_login', true)
            ->pluck('uuid')
            ->all();

        return $this->notifyAdminsByUuids($uuids, $notification, $queue);
    }

    /**
     * @param  list<string>  $adminUuids
     */
    public function notifyAdminsByUuids(array $adminUuids, Notification $notification, bool $queue = false): int
    {
        $adminUuids = array_values(array_filter(array_unique(array_map('strval', $adminUuids))));
        if ($adminUuids === []) {
            return 0;
        }

        try {
            $admins = Admin::query()
                ->whereIn('uuid', $adminUuids)
                ->where('is_active', true)
                ->where('can_login', true)
                ->get();

            if ($admins->isEmpty()) {
                return 0;
            }

            return $this->deliverToEach($admins, $notification, $queue, Admin::class);
        } catch (\Throwable $e) {
            Log::error('Error in notifyAdminsByUuids', [
                'admin_uuids' => $adminUuids,
                'error' => $e->getMessage(),
                'notification' => $notification::class,
                'trace' => $e->getTraceAsString(),
            ]);

            return 0;
        }
    }

    /**
     * Notify a donor by resolving the recipient from either an explicit user uuid or by donor email.
     *
     * When $userUuid is provided it takes precedence; otherwise the active User whose lowercased,
     * trimmed email matches $donorEmail is used. Returns 0 if no recipient could be resolved.
     */
    public function notifyDonor(?string $userUuid, ?string $donorEmail, Notification $notification, bool $queue = false): int
    {
        if ($userUuid !== null && $userUuid !== '') {
            return $this->notifyUsersByUuids([$userUuid], $notification, $queue);
        }

        $email = strtolower(trim((string) $donorEmail));
        if ($email === '') {
            return 0;
        }

        $uuid = User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->where('is_active', true)
            ->where('can_login', true)
            ->value('uuid');

        if ($uuid === null) {
            return 0;
        }

        return $this->notifyUsersByUuids([(string) $uuid], $notification, $queue);
    }

    /**
     * @param  list<string>  $userUuids
     */
    public function notifyUsersByUuids(array $userUuids, Notification $notification, bool $queue = false): int
    {
        $userUuids = array_values(array_filter(array_unique(array_map('strval', $userUuids))));
        if ($userUuids === []) {
            return 0;
        }

        try {
            $users = User::query()
                ->whereIn('uuid', $userUuids)
                ->where('is_active', true)
                ->where('can_login', true)
                ->get();

            if ($users->isEmpty()) {
                return 0;
            }

            return $this->deliverToEach($users, $notification, $queue, User::class);
        } catch (\Throwable $e) {
            Log::error('Error in notifyUsersByUuids', [
                'user_uuids' => $userUuids,
                'error' => $e->getMessage(),
                'notification' => $notification::class,
                'trace' => $e->getTraceAsString(),
            ]);

            return 0;
        }
    }

    /**
     * @param  Collection<int, Admin|User>  $recipients
     */
    private function deliverToEach($recipients, Notification $notification, bool $queue, string $modelLabel): int
    {
        $notifiedCount = 0;

        foreach ($recipients as $recipient) {
            try {
                if ($queue) {
                    NotificationFacade::send($recipient, clone $notification);
                } else {
                    NotificationFacade::sendNow($recipient, clone $notification);
                }
                $notifiedCount++;
            } catch (\Throwable $e) {
                Log::error('Failed to deliver notification', [
                    'recipient_model' => $modelLabel,
                    'recipient_uuid' => $recipient->uuid ?? null,
                    'error' => $e->getMessage(),
                    'notification' => $notification::class,
                    'trace' => $e->getTraceAsString(),
                ]);
            }
        }

        return $notifiedCount;
    }
}
