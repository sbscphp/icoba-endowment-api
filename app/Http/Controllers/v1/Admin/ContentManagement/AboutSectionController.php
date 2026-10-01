<?php

namespace App\Http\Controllers\v1\Admin\ContentManagement;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentManagement\UpdateAboutSectionRequest;
use App\Http\Resources\Admin\AboutSectionResource;
use App\Responser\JsonResponser;
use App\Services\Admin\ContentManagement\AboutSectionService;
use Illuminate\Http\Request;

class AboutSectionController extends Controller
{
    public function __construct(
        private readonly AboutSectionService $aboutSectionService,
    ) {}

    public function index()
    {
        try {
            $sections = $this->aboutSectionService->list()
                ->map(fn ($section) => AboutSectionResource::make($section)->withoutContent()->resolve())
                ->values()
                ->all();

            return JsonResponser::send(false, 'Sections retrieved.', $sections);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AboutSectionController@index');
        }
    }

    public function show(string $sectionKey)
    {
        try {
            $section = $this->aboutSectionService->find($sectionKey);

            return JsonResponser::send(false, 'Section retrieved.', AboutSectionResource::make($section)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AboutSectionController@show');
        }
    }

    public function update(UpdateAboutSectionRequest $request, string $sectionKey)
    {
        try {
            $section = $this->aboutSectionService->update($sectionKey, $request->validated(), $request->user(), $request);

            return JsonResponser::send(false, 'Section updated successfully.', AboutSectionResource::make($section)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AboutSectionController@update');
        }
    }

    public function toggleStatus(Request $request, string $sectionKey)
    {
        try {
            $section = $this->aboutSectionService->toggleActiveStatus($sectionKey, $request->user(), $request);
            $message = (bool) $section->is_active
                ? 'Content block reactivated successfully.'
                : 'Content block deactivated successfully.';

            return JsonResponser::send(false, $message, AboutSectionResource::make($section)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\AboutSectionController@toggleStatus');
        }
    }
}
