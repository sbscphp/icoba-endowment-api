<?php

namespace App\Http\Controllers\v1\Admin\Reconciliation;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reconciliation\FcmbImportRequest;
use App\Responser\JsonResponser;
use App\Services\Reconciliation\FcmbCsvImportService;

class FcmbImportController extends Controller
{
    public function __construct(
        private readonly FcmbCsvImportService $importer,
    ) {}

    public function store(FcmbImportRequest $request)
    {
        try {
            $summary = $this->importer->importFromUploadedFile($request->file('statement'));

            return JsonResponser::send(false, 'FCMB statement processed.', $summary);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\FcmbImportController@store');
        }
    }
}
