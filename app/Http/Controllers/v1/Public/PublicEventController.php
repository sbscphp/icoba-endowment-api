<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\PublicEventListRequest;
use App\Http\Resources\PublicEventDetailResource;
use App\Http\Resources\PublicEventListResource;
use App\Responser\JsonResponser;
use App\Services\Public\PublicEventService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicEventController extends Controller
{
    public function __construct(
        private readonly PublicEventService $publicEventService,
    ) {}

    public function index(PublicEventListRequest $request)
    {
        try {
            $paginator = $this->publicEventService->list($request->validated());

            return JsonResponser::send(
                false,
                'Events retrieved.',
                $this->paginatedPayload($paginator, PublicEventListResource::class)
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicEventController@index');
        }
    }

    public function show(string $eventUuid)
    {
        try {
            $event = $this->publicEventService->find($eventUuid);

            return JsonResponser::send(false, 'Event retrieved.', PublicEventDetailResource::make($event)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicEventController@show');
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
