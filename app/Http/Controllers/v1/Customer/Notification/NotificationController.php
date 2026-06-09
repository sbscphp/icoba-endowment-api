<?php

namespace App\Http\Controllers\v1\Customer\Notification;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Notification\NotificationListRequest;
use App\Http\Resources\DatabaseNotificationResource;
use App\Models\User;
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
            $user = $this->requireCustomer($request);
            $validated = $request->validated();

            $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));
            $page = max(1, (int) ($validated['page'] ?? 1));

            $paginator = $this->inbox->paginate($user, $perPage, $page, $validated);

            return JsonResponser::send(
                false,
                'Notifications retrieved.',
                $this->paginatedPayload($paginator, $validated, $user)
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

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function paginatedPayload(LengthAwarePaginator $paginator, array $validated, User $user): array
    {
        $payload = $paginator->toArray();
        $payload['data'] = DatabaseNotificationResource::collection($paginator)->resolve();

        return array_merge($payload, $this->inbox->inboxCounts($user, $validated));
    }
}
