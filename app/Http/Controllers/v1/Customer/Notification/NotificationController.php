<?php

namespace App\Http\Controllers\v1\Customer\Notification;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\DatabaseNotificationResource;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Notifications\NotificationInboxService;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function __construct(private readonly NotificationInboxService $inbox) {}

    public function index(Request $request)
    {
        try {
            $user = $this->requireCustomer($request);

            $perPage = min(max((int) $request->query('per_page', 15), 1), 100);

            $paginator = $this->inbox->paginate($user, $perPage);

            $notifications = DatabaseNotificationResource::collection($paginator)->resource;
    
            return JsonResponser::send(
                false,
                'Notifications retrieved.',
                $notifications
            );

        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Notification\NotificationController@index');
        }
    }

    public function show(Request $request, string $id)
    {
        try {
            $user = $this->requireCustomer($request);
            $notification = $this->inbox->findForRecipient($user, $id);

            return JsonResponser::send(false, 'Notification retrieved.', DatabaseNotificationResource::make($notification)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Notification\NotificationController@show');
        }
    }

    public function markAllRead(Request $request)
    {
        try {
            $user = $this->requireCustomer($request);
            $this->inbox->markAllRead($user);

            return JsonResponser::send(false, 'All notifications marked as read.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Notification\NotificationController@markAllRead');
        }
    }

    public function markRead(Request $request, string $id)
    {
        try {
            $user = $this->requireCustomer($request);
            $notification = $this->inbox->markRead($user, $id);

            return JsonResponser::send(false, 'Notification marked as read.', DatabaseNotificationResource::make($notification)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Notification\NotificationController@markRead');
        }
    }

    public function markUnread(Request $request, string $id)
    {
        try {
            $user = $this->requireCustomer($request);
            $notification = $this->inbox->markUnread($user, $id);

            return JsonResponser::send(false, 'Notification marked as unread.', DatabaseNotificationResource::make($notification)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Notification\NotificationController@markUnread');
        }
    }

    public function dismiss(Request $request, string $id)
    {
        try {
            $user = $this->requireCustomer($request);
            $this->inbox->dismiss($user, $id);

            return JsonResponser::send(false, 'Notification deleted.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Notification\NotificationController@dismiss');
        }
    }

    private function requireCustomer(Request $request): User
    {
        $user = $request->user();
        if (! $user instanceof User) {
            abort(403, 'Forbidden.');
        }

        return $user;
    }
}
