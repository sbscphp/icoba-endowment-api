<?php

namespace App\Http\Controllers\v1\Admin\Notification;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Notification\NotificationListRequest;
use App\Http\Resources\DatabaseNotificationResource;
use App\Models\Admin;
use App\Responser\JsonResponser;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationInboxService $inbox) {}

    public function index(NotificationListRequest $request)
    {
        try {
            $admin = $this->requireAdmin($request);
            $validated = $request->validated();

            $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));
            $page = max(1, (int) ($validated['page'] ?? 1));

            $paginator = $this->inbox->paginate($admin, $perPage, $page, $validated);

            return JsonResponser::send(
                false,
                'Notifications retrieved.',
                $this->paginatedPayload($paginator, $validated, $admin)
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Notification\NotificationController@index');
        }
    }

    public function show(Request $request, string $id)
    {
        try {
            $admin = $this->requireAdmin($request);
            $notification = $this->inbox->findForRecipient($admin, $id);

            return JsonResponser::send(false, 'Notification retrieved.', DatabaseNotificationResource::make($notification)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Notification\NotificationController@show');
        }
    }

    public function markAllRead(Request $request)
    {
        try {
            $admin = $this->requireAdmin($request);
            $this->inbox->markAllRead($admin);

            return JsonResponser::send(false, 'All notifications marked as read.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Notification\NotificationController@markAllRead');
        }
    }

    public function markRead(Request $request, string $id)
    {
        try {
            $admin = $this->requireAdmin($request);
            $notification = $this->inbox->markRead($admin, $id);

            return JsonResponser::send(false, 'Notification marked as read.', DatabaseNotificationResource::make($notification)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Notification\NotificationController@markRead');
        }
    }

    public function markUnread(Request $request, string $id)
    {
        try {
            $admin = $this->requireAdmin($request);
            $notification = $this->inbox->markUnread($admin, $id);

            return JsonResponser::send(false, 'Notification marked as unread.', DatabaseNotificationResource::make($notification)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Notification\NotificationController@markUnread');
        }
    }

    public function dismiss(Request $request, string $id)
    {
        try {
            $admin = $this->requireAdmin($request);
            $this->inbox->dismiss($admin, $id);

            return JsonResponser::send(false, 'Notification deleted.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Notification\NotificationController@dismiss');
        }
    }

    private function requireAdmin(Request $request): Admin
    {
        $admin = $request->user();
        if (! $admin instanceof Admin) {
            abort(403, 'Forbidden.');
        }

        return $admin;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function paginatedPayload(LengthAwarePaginator $paginator, array $validated, Admin $admin): array
    {
        $payload = $paginator->toArray();
        $payload['data'] = DatabaseNotificationResource::collection($paginator)->resolve();

        return array_merge($payload, $this->inbox->inboxCounts($admin, $validated));
    }
}
