<?php

namespace App\Http\Controllers\v1\Admin\CertificateTemplate;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CertificateTemplate\CertificateTemplateListRequest;
use App\Http\Requests\Admin\CertificateTemplate\CreateCertificateTemplateRequest;
use App\Http\Requests\Admin\CertificateTemplate\PreviewCertificateTemplateRequest;
use App\Http\Requests\Admin\CertificateTemplate\UpdateCertificateTemplateRequest;
use App\Http\Requests\Admin\DateRangeStatsRequest;
use App\Http\Resources\CertificateTemplateListResource;
use App\Http\Resources\CertificateTemplateResource;
use App\Responser\JsonResponser;
use App\Services\Admin\CertificateTemplate\CertificateTemplateService;
use App\Services\Recognition\CertificatePdfService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class CertificateTemplateController extends Controller
{
    public function __construct(
        private readonly CertificateTemplateService $certificateTemplateService,
        private readonly CertificatePdfService $certificatePdfService,
    ) {}

    public function index(CertificateTemplateListRequest $request)
    {
        try {
            $paginator = $this->certificateTemplateService->list($request->validated());

            return JsonResponser::send(false, 'Certificate templates retrieved.', $this->paginatedPayload($paginator, CertificateTemplateListResource::class));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\CertificateTemplate\CertificateTemplateController@index');
        }
    }

    public function stats(DateRangeStatsRequest $request)
    {
        try {
            return JsonResponser::send(false, 'Certificate template stats retrieved.', $this->certificateTemplateService->stats($request->validated()));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\CertificateTemplate\CertificateTemplateController@stats');
        }
    }

    public function store(CreateCertificateTemplateRequest $request)
    {
        try {
            $template = $this->certificateTemplateService->create($request->validated());

            return JsonResponser::send(false, 'Certificate template created successfully.', CertificateTemplateResource::make($template)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\CertificateTemplate\CertificateTemplateController@store');
        }
    }

    public function show(string $templateId)
    {
        try {
            $template = $this->certificateTemplateService->findTemplate($templateId);

            return JsonResponser::send(false, 'Certificate template retrieved.', CertificateTemplateResource::make($template)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\CertificateTemplate\CertificateTemplateController@show');
        }
    }

    public function preview(PreviewCertificateTemplateRequest $request, string $templateId)
    {
        try {
            $template = $this->certificateTemplateService->findTemplate($templateId);
            $awardeeName = trim((string) ($request->validated()['awardee_name'] ?? 'Sample Donor'));
            if ($awardeeName === '') {
                $awardeeName = 'Sample Donor';
            }

            return $this->certificatePdfService->streamTemplatePreview($template, $awardeeName);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\CertificateTemplate\CertificateTemplateController@preview');
        }
    }

    public function update(UpdateCertificateTemplateRequest $request, string $templateId)
    {
        try {
            $template = $this->certificateTemplateService->update($templateId, $request->validated());

            return JsonResponser::send(false, 'Certificate template updated.', CertificateTemplateResource::make($template)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\CertificateTemplate\CertificateTemplateController@update');
        }
    }

    public function toggleStatus(string $templateId)
    {
        try {
            $template = $this->certificateTemplateService->toggleActiveStatus($templateId);
            $message = (bool) $template->is_active ? 'Certificate template activated.' : 'Certificate template deactivated.';

            return JsonResponser::send(false, $message, CertificateTemplateResource::make($template)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\CertificateTemplate\CertificateTemplateController@toggleStatus');
        }
    }

    public function destroy(string $templateId)
    {
        try {
            $this->certificateTemplateService->delete($templateId);

            return JsonResponser::send(false, 'Certificate template deleted successfully.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\CertificateTemplate\CertificateTemplateController@destroy');
        }
    }

    public function dropdown(?string $status = null)
    {
        try {
            $templates = $this->certificateTemplateService->dropdown($status ?? 'active');
            $payload = $templates->map(fn ($template) => [
                'template_id' => $template->uuid,
                'name' => $template->name,
                'is_active' => (bool) $template->is_active,
            ])->values()->all();

            return JsonResponser::send(false, 'Certificate template dropdown retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\CertificateTemplate\CertificateTemplateController@dropdown');
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
