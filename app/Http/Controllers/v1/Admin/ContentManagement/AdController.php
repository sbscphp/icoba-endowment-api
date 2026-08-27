<?php

namespace App\Http\Controllers\v1\Admin\ContentManagement;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentManagement\AdListRequest;
use App\Http\Requests\Admin\ContentManagement\CreateAdRequest;
use App\Http\Requests\Admin\ContentManagement\SyncAdImagesRequest;
use App\Http\Requests\Admin\ContentManagement\UpdateAdRequest;
use App\Http\Requests\Admin\ContentManagement\UpdateAdSettingsRequest;
use App\Http\Resources\Admin\AdListResource;
use App\Http\Resources\Admin\AdResource;
use App\Http\Resources\Admin\AdSettingsResource;
use App\Responser\JsonResponser;
use App\Services\Admin\ContentManagement\AdService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class AdController extends Controller
{
    public function __construct(
        private readonly AdService $adService,
    ) {}

    public function index(AdListRequest $request)
    {
        try {
            $paginator = $this->adService->list($request->validated());

            return JsonResponser::send(false, 'Ads retrieved.', $this->paginatedPayload($paginator, AdListResource::class));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AdController@index');
        }
    }

    public function store(CreateAdRequest $request)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $ad = $this->adService->create($request->validated(), $adminUuid);

            return JsonResponser::send(false, 'Ad created successfully.', AdResource::make($ad)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AdController@store');
        }
    }

    public function show(string $adId)
    {
        try {
            $ad = $this->adService->findAd($adId);

            return JsonResponser::send(false, 'Ad retrieved.', AdResource::make($ad)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AdController@show');
        }
    }

    public function update(UpdateAdRequest $request, string $adId)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $ad = $this->adService->update($adId, $request->validated(), $adminUuid);

            return JsonResponser::send(false, 'Ad updated.', AdResource::make($ad)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AdController@update');
        }
    }

    public function archive(string $adId)
    {
        try {
            $adminUuid = request()->user()?->uuid;
            $ad = $this->adService->archive($adId, $adminUuid);

            return JsonResponser::send(false, 'Ad archived.', AdResource::make($ad)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AdController@archive');
        }
    }

    public function reactivate(string $adId)
    {
        try {
            $adminUuid = request()->user()?->uuid;
            $ad = $this->adService->reactivate($adId, $adminUuid);

            return JsonResponser::send(false, 'Ad reactivated.', AdResource::make($ad)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AdController@reactivate');
        }
    }

    public function destroy(string $adId)
    {
        try {
            $this->adService->delete($adId);

            return JsonResponser::send(false, 'Ad deleted successfully.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AdController@destroy');
        }
    }

    public function syncImages(SyncAdImagesRequest $request, string $adId)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $ad = $this->adService->syncImages($adId, (array) $request->validated('images'), $adminUuid);

            return JsonResponser::send(false, 'Ad images updated.', AdResource::make($ad)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AdController@syncImages');
        }
    }

    public function destroyImage(Request $request, string $adId, string $imageId)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $ad = $this->adService->deleteImage($adId, $imageId, $adminUuid);

            return JsonResponser::send(false, 'Ad image deleted.', AdResource::make($ad)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AdController@destroyImage');
        }
    }

    public function settings()
    {
        try {
            $settings = $this->adService->settings();

            return JsonResponser::send(false, 'Ad settings retrieved.', AdSettingsResource::make($settings)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AdController@settings');
        }
    }

    public function updateSettings(UpdateAdSettingsRequest $request)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $settings = $this->adService->updateSettings($request->validated(), $adminUuid);

            return JsonResponser::send(false, 'Ad settings updated.', AdSettingsResource::make($settings)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AdController@updateSettings');
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
