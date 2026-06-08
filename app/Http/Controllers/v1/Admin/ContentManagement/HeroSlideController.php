<?php

namespace App\Http\Controllers\v1\Admin\ContentManagement;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentManagement\CreateHeroSlideRequest;
use App\Http\Requests\Admin\ContentManagement\UpdateHeroSlideRequest;
use App\Http\Resources\HeroSlideResource;
use App\Responser\JsonResponser;
use App\Services\Admin\ContentManagement\HeroSlideService;

class HeroSlideController extends Controller
{
    public function __construct(
        private readonly HeroSlideService $heroSlideService,
    ) {}

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

    public function show(?string $slideId = null)
    {
        try {
            $slide = $slideId !== null
                ? $this->heroSlideService->findSlide($slideId)
                : $this->heroSlideService->current();

            if ($slide !== null) {
                $slide->loadMissing('updatedByAdmin');
            }

            return JsonResponser::send(
                false,
                'Hero slide retrieved.',
                $slide !== null ? HeroSlideResource::make($slide)->resolve() : null
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\HeroSlideController@show');
        }
    }

    public function update(UpdateHeroSlideRequest $request, ?string $slideId = null)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $slide = $slideId !== null
                ? $this->heroSlideService->update($slideId, $request->validated(), $adminUuid)
                : $this->heroSlideService->updateCurrent($request->validated(), $adminUuid);
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
}
