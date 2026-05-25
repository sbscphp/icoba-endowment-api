<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\ContactSubmissionStoreRequest;
use App\Responser\JsonResponser;
use App\Services\Contact\ContactSubmissionService;

class ContactSubmissionController extends Controller
{
    public function __construct(
        private readonly ContactSubmissionService $contactSubmissionService,
    ) {}

    public function store(ContactSubmissionStoreRequest $request)
    {
        try {
            $submission = $this->contactSubmissionService->create($request->validated());

            return JsonResponser::send(
                false,
                'Your message has been submitted successfully. We will get back to you shortly.',
                null,
                201
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\ContactSubmissionController@store');
        }
    }
}
