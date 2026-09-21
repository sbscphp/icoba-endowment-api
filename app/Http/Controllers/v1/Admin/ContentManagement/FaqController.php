<?php

namespace App\Http\Controllers\v1\Admin\ContentManagement;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContentManagement\CreateFaqRequest;
use App\Http\Requests\Admin\ContentManagement\FaqListRequest;
use App\Http\Requests\Admin\ContentManagement\UpdateFaqRequest;
use App\Http\Resources\Admin\FaqResource;
use App\Responser\JsonResponser;
use App\Services\Admin\ContentManagement\FaqService;

class FaqController extends Controller
{
    public function __construct(
        private readonly FaqService $faqService,
    ) {}

    public function index(FaqListRequest $request)
    {
        try {
            $paginator = $this->faqService->list($request->validated());

            $payload = $paginator->toArray();
            $payload['data'] = FaqResource::collection($paginator)->resolve();

            return JsonResponser::send(false, 'FAQs retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\FaqController@index');
        }
    }

    public function store(CreateFaqRequest $request)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $faq = $this->faqService->create($request->validated(), $adminUuid);

            return JsonResponser::send(false, 'FAQ created successfully.', FaqResource::make($faq)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\FaqController@store');
        }
    }

    public function show(string $faqId)
    {
        try {
            $faq = $this->faqService->findFaq($faqId);

            return JsonResponser::send(false, 'FAQ retrieved.', FaqResource::make($faq)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\FaqController@show');
        }
    }

    public function update(UpdateFaqRequest $request, string $faqId)
    {
        try {
            $adminUuid = $request->user()?->uuid;
            $faq = $this->faqService->update($faqId, $request->validated(), $adminUuid);

            return JsonResponser::send(false, 'FAQ updated.', FaqResource::make($faq)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\FaqController@update');
        }
    }

    public function toggleStatus(string $faqId)
    {
        try {
            $adminUuid = request()->user()?->uuid;
            $faq = $this->faqService->toggleActiveStatus($faqId, $adminUuid);
            $message = (bool) $faq->is_active ? 'FAQ activated.' : 'FAQ deactivated.';

            return JsonResponser::send(false, $message, FaqResource::make($faq)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\FaqController@toggleStatus');
        }
    }

    public function destroy(string $faqId)
    {
        try {
            $this->faqService->delete($faqId);

            return JsonResponser::send(false, 'FAQ deleted successfully.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContentManagement\FaqController@destroy');
        }
    }
}
