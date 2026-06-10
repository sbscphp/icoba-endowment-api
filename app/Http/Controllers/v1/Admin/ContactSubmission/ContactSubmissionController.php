<?php

namespace App\Http\Controllers\v1\Admin\ContactSubmission;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ContactSubmission\ContactSubmissionListRequest;
use App\Http\Requests\Admin\ContactSubmission\UpdateContactSubmissionStatusRequest;
use App\Http\Requests\Admin\DateRangeStatsRequest;
use App\Http\Resources\Admin\ContactSubmissionListResource;
use App\Http\Resources\Admin\ContactSubmissionResource;
use App\Models\Admin;
use App\Responser\JsonResponser;
use App\Services\Contact\ContactSubmissionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class ContactSubmissionController extends Controller
{
    public function __construct(
        private readonly ContactSubmissionService $contactSubmissionService,
    ) {}

    public function stats(DateRangeStatsRequest $request)
    {
        try {
            return JsonResponser::send(
                false,
                'Contact submission stats retrieved.',
                $this->contactSubmissionService->stats($request->validated()),
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContactSubmission\ContactSubmissionController@stats');
        }
    }

    public function index(ContactSubmissionListRequest $request)
    {
        try {
            $paginator = $this->contactSubmissionService->list($request->validated());

            return JsonResponser::send(
                false,
                'Contact submissions retrieved.',
                $this->paginatedPayload($paginator, ContactSubmissionListResource::class)
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContactSubmission\ContactSubmissionController@index');
        }
    }

    public function show(string $submissionUuid)
    {
        try {
            $submission = $this->contactSubmissionService->find($submissionUuid);

            return JsonResponser::send(
                false,
                'Contact submission retrieved.',
                ContactSubmissionResource::make($submission)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContactSubmission\ContactSubmissionController@show');
        }
    }

    public function updateStatus(UpdateContactSubmissionStatusRequest $request, string $submissionUuid)
    {
        try {
            $admin = $this->requireAdmin($request);
            $submission = $this->contactSubmissionService->updateStatus(
                $submissionUuid,
                $request->validated(),
                $admin->uuid
            );

            return JsonResponser::send(
                false,
                'Contact submission status updated.',
                ContactSubmissionResource::make($submission)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\ContactSubmission\ContactSubmissionController@updateStatus');
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
