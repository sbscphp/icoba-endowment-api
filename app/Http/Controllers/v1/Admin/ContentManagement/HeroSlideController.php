<?php

namespace App\Http\Controllers\v1\Admin\ContentManagement;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentManagement\CreateHeroSlideRequest;
use App\Http\Requests\Admin\ContentManagement\HeroSlideListRequest;
use App\Http\Requests\Admin\ContentManagement\UpdateHeroSlideRequest;
use App\Http\Resources\HeroSlideListResource;
use App\Http\Resources\HeroSlideResource;
use App\Responser\JsonResponser;
use App\Services\Admin\ContentManagement\HeroSlideService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class HeroSlideController extends Controller
{
    public function __construct(
        private readonly HeroSlideService $heroSlideService,
    ) {}

    public function index(HeroSlideListRequest $request)
    {
        try {
            $paginator = $this->heroSlideService->list($request->validated());

            return JsonResponser::send(
                false,
                'Hero slides retrieved.',
                $this->paginatedPayload($paginator, HeroSlideListResource::class)
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\HeroSlideController@index');
        }
    }

    public function store(CreateHeroSlideRequest $request)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $slide = $this->heroSlideService->create($request->validated(), $adminUuid);

            return JsonResponser::send(
                false,
                'Hero slide created successfully.',
                HeroSlideResource::make($slide)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\HeroSlideController@store');
        }
    }

    public function show(string $slideId)
    {
        try {
            $slide = $this->heroSlideService->findSlide($slideId);
            $slide->load('updatedByAdmin');

            return JsonResponser::send(
                false,
                'Hero slide retrieved.',
                HeroSlideResource::make($slide)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\HeroSlideController@show');
        }
    }

    public function update(UpdateHeroSlideRequest $request, string $slideId)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $slide = $this->heroSlideService->update($slideId, $request->validated(), $adminUuid);
            $slide->load('updatedByAdmin');

            return JsonResponser::send(
                false,
                'Hero slide updated.',
                HeroSlideResource::make($slide)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\HeroSlideController@update');
        }
    }

    public function toggleStatus(string $slideId)
    {
        try {
            $adminUuid = request()->user()?->uuid;
            $slide = $this->heroSlideService->toggleActiveStatus($slideId, $adminUuid);
            $slide->load('updatedByAdmin');
            $message = (bool) $slide->is_active ? 'Hero slide activated.' : 'Hero slide deactivated.';

            return JsonResponser::send(
                false,
                $message,
                HeroSlideResource::make($slide)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\HeroSlideController@toggleStatus');
        }
    }

    public function destroy(string $slideId)
    {
        try {
            $this->heroSlideService->delete($slideId);

            return JsonResponser::send(false, 'Hero slide deleted successfully.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\HeroSlideController@destroy');
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
