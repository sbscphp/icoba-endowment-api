<?php

namespace App\Http\Controllers\v1\Admin\Event;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Event\CreateEventRequest;
use App\Http\Requests\Admin\Event\EventListRequest;
use App\Http\Requests\Admin\Event\UpdateEventRequest;
use App\Http\Requests\Admin\Event\SyncEventImagesRequest;
use App\Http\Requests\Admin\Event\UpdateEventStatusRequest;
use App\Http\Resources\Admin\EventListResource;
use App\Http\Resources\Admin\EventResource;
use App\Responser\JsonResponser;
use App\Services\Admin\Event\EventService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class EventController extends Controller
{
    public function __construct(
        private readonly EventService $eventService,
    ) {}

    public function index(EventListRequest $request)
    {
        try {
            $paginator = $this->eventService->list($request->validated());

            return JsonResponser::send(false, 'Events retrieved.', $this->paginatedPayload($paginator, EventListResource::class));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Event\EventController@index');
        }
    }

    public function store(CreateEventRequest $request)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $event = $this->eventService->create($request->validated(), $adminUuid);

            return JsonResponser::send(false, 'Event created successfully.', EventResource::make($event)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Event\EventController@store');
        }
    }

    public function show(string $eventId)
    {
        try {
            $event = $this->eventService->findEvent($eventId);

            return JsonResponser::send(false, 'Event retrieved.', EventResource::make($event)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Event\EventController@show');
        }
    }

    public function update(UpdateEventRequest $request, string $eventId)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $event = $this->eventService->update($eventId, $request->validated(), $adminUuid);

            return JsonResponser::send(false, 'Event updated.', EventResource::make($event)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Event\EventController@update');
        }
    }

    public function updateStatus(UpdateEventStatusRequest $request, string $eventId)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $status = (string) $request->validated('status');
            $event = $this->eventService->setStatus($eventId, $status, $adminUuid);

            $messages = [
                'draft' => 'Event moved to draft.',
                'published' => 'Event published.',
                'archived' => 'Event archived.',
            ];

            return JsonResponser::send(false, $messages[$status] ?? 'Event status updated.', EventResource::make($event)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Event\EventController@updateStatus');
        }
    }

    public function destroy(string $eventId)
    {
        try {
            $this->eventService->delete($eventId);

            return JsonResponser::send(false, 'Event deleted successfully.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Event\EventController@destroy');
        }
    }

    public function syncImages(SyncEventImagesRequest $request, string $eventId)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $event = $this->eventService->syncImages($eventId, (array) $request->validated('images'), $adminUuid);

            return JsonResponser::send(false, 'Event images updated.', EventResource::make($event)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Event\EventController@syncImages');
        }
    }

    public function destroyImage(Request $request, string $eventId, string $imageId)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $event = $this->eventService->deleteImage($eventId, $imageId, $adminUuid);

            return JsonResponser::send(false, 'Event image deleted.', EventResource::make($event)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Event\EventController@destroyImage');
        }
    }

    /**
     * @param  class-string<JsonResource>  $resourceClass
     * @return array<string, mixed>
     */
    private function paginatedPayload(LengthAwarePaginator $paginator, string $resourceClass): array
    {
        $payload = $paginator->toArray();
        /** @var AnonymousResourceCollection $resource */
        $resource = $resourceClass::collection($paginator);
        $payload['data'] = $resource->resolve();

        return $payload;
    }
}
