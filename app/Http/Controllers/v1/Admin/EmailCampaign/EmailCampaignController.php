<?php

namespace App\Http\Controllers\v1\Admin\EmailCampaign;

use App\Enums\AuditActionEnum;
use App\Enums\BulkEmailAudience;
use App\Enums\EmailDesignTemplate;
use App\Enums\ModuleEnums;
use App\Enums\UserTypeEnum;
use App\Helpers\GeneralHelper;
use App\Helpers\PDFReportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EmailCampaign\BulkEmailListRequest;
use App\Http\Requests\Admin\EmailCampaign\BulkEmailSendRequest;
use App\Http\Requests\Admin\EmailCampaign\CreateBulkEmailRequest;
use App\Http\Requests\Admin\EmailCampaign\UpdateBulkEmailRequest;
use App\Http\Resources\BulkEmailListResource;
use App\Http\Resources\BulkEmailResource;
use App\Models\Admin;
use App\Models\CampaignEmail;
use App\Responser\JsonResponser;
use App\Services\Admin\EmailCampaign\BulkEmailService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmailCampaignController extends Controller
{
    public function __construct(
        private readonly BulkEmailService $bulkEmailService,
        private readonly PDFReportHelper $pdfReportHelper,
    ) {}

    public function index(BulkEmailListRequest $request)
    {
        try {
            $listing = $request->validated();
            $export = $listing['export'] ?? null;

            return match ($export) {
                'csv' => $this->respondListCsv($listing),
                'pdf' => $this->respondListPdf($listing),
                default => $this->respondListPaginated($listing),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\EmailCampaign\EmailCampaignController@index');
        }
    }

    public function designTemplateOptions()
    {
        try {
            $payload = collect(EmailDesignTemplate::cases())->map(fn (EmailDesignTemplate $t) => [
                'value' => $t->value,
                'label' => ucfirst($t->value),
            ])->values()->all();

            return JsonResponser::send(false, 'Email design templates retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\EmailCampaign\EmailCampaignController@designTemplateOptions');
        }
    }

    public function audienceOptions()
    {
        try {
            $payload = collect(BulkEmailAudience::cases())->map(fn (BulkEmailAudience $a) => [
                'value' => $a->value,
                'label' => $a->label(),
            ])->values()->all();

            return JsonResponser::send(false, 'Recipient audiences retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\EmailCampaign\EmailCampaignController@audienceOptions');
        }
    }

    public function dropdown(?string $status = null)
    {
        try {
            $rows = $this->bulkEmailService->dropdown($status ?? 'all');
            $payload = $rows->map(fn (CampaignEmail $e) => [
                'email_id' => $e->uuid,
                'title' => $e->title,
                'campaign_uuid' => $e->campaign_uuid,
                'status' => $e->status->value,
                'is_active' => (bool) $e->is_active,
            ])->values()->all();

            return JsonResponser::send(false, 'Email campaign dropdown retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\EmailCampaign\EmailCampaignController@dropdown');
        }
    }

    public function store(CreateBulkEmailRequest $request)
    {
        try {
            $admin = $this->requireAdmin($request);
            $email = $this->bulkEmailService->create($request->validated(), $admin, $request);

            return JsonResponser::send(false, 'Email campaign saved.', BulkEmailResource::make($email)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\EmailCampaign\EmailCampaignController@store');
        }
    }

    public function show(Request $request, string $emailId)
    {
        try {
            $this->requireAdmin($request);
            $email = $this->bulkEmailService->findEmail($emailId);

            return JsonResponser::send(false, 'Email campaign retrieved.', BulkEmailResource::make($email)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\EmailCampaign\EmailCampaignController@show');
        }
    }

    public function update(UpdateBulkEmailRequest $request, string $emailId)
    {
        try {
            $admin = $this->requireAdmin($request);
            $email = $this->bulkEmailService->update($emailId, $request->validated(), $admin, $request);

            return JsonResponser::send(false, 'Email campaign updated.', BulkEmailResource::make($email)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\EmailCampaign\EmailCampaignController@update');
        }
    }

    public function destroy(Request $request, string $emailId)
    {
        try {
            $admin = $this->requireAdmin($request);
            $result = $this->bulkEmailService->delete($emailId);
            if ((int) $result['blocked'] === 1) {
                return JsonResponser::send(true, 'Email campaign cannot be deleted in its current state.', null, 422);
            }

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::BULK_EMAIL_DELETED,
                $request,
                $admin->uuid,
                ['campaign_email_uuid' => $result['uuid'] ?? $emailId],
                'Bulk email deleted.',
                CampaignEmail::class,
                (string) ($result['uuid'] ?? $emailId),
                ModuleEnums::email_campaigns,
                200,
            );

            return JsonResponser::send(false, 'Email campaign deleted.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\EmailCampaign\EmailCampaignController@destroy');
        }
    }

    public function setActive(Request $request, string $emailId)
    {
        try {
            $admin = $this->requireAdmin($request);
            $email = $this->bulkEmailService->setActive($emailId, $admin, $request);

            return JsonResponser::send(false, 'Active email template updated for this campaign.', BulkEmailResource::make($email)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\EmailCampaign\EmailCampaignController@setActive');
        }
    }

    public function send(BulkEmailSendRequest $request, string $emailId)
    {
        try {
            $admin = $this->requireAdmin($request);
            $email = $this->bulkEmailService->send($emailId, $admin, $request);

            return JsonResponser::send(false, 'Email campaign queued for delivery.', BulkEmailResource::make($email)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\EmailCampaign\EmailCampaignController@send');
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
     * @param  array<string, mixed>  $listing
     */
    private function respondListPaginated(array $listing)
    {
        $paginator = $this->bulkEmailService->list($listing);

        return JsonResponser::send(false, 'Email campaigns retrieved.', $this->paginatedPayload($paginator, BulkEmailListResource::class));
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListCsv(array $listing): StreamedResponse
    {
        [$collection, $truncated] = $this->bulkEmailService->exportCollection($listing);
        $filename = 'email-campaigns-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($collection): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['UUID', 'Title', 'Campaign', 'Audience', 'Status', 'Active', 'Sent at']);
            foreach ($collection as $row) {
                /** @var CampaignEmail $row */
                $row->loadMissing('campaign:uuid,name');
                fputcsv($out, [
                    $row->uuid,
                    $row->title,
                    $row->campaign?->name ?? '',
                    implode(', ', is_array($row->recipient_audience) ? $row->recipient_audience : []),
                    $row->status->value,
                    $row->is_active ? '1' : '0',
                    $row->sent_at?->toIso8601String() ?? '',
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Truncated' => $truncated ? '1' : '0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListPdf(array $listing)
    {
        [$collection, $truncated] = $this->bulkEmailService->exportCollection($listing);
        $collection->loadMissing('campaign:uuid,name');
        $filename = 'email-campaigns-'.now()->format('Y-m-d-His').'.pdf';
        $periodStart = ! empty($listing['start_date']) ? (string) $listing['start_date'] : 'All dates';
        $periodEnd = ! empty($listing['end_date']) ? (string) $listing['end_date'] : 'All dates';

        $headings = ['UUID', 'Title', 'Campaign', 'Audience', 'Status', 'Active'];
        $rows = $collection->map(fn (CampaignEmail $row): array => [
            $row->uuid,
            $row->title,
            $row->campaign?->name ?? '',
            implode(', ', is_array($row->recipient_audience) ? $row->recipient_audience : []),
            $row->status->value,
            $row->is_active ? 'Yes' : 'No',
        ]);

        return $this->pdfReportHelper->download(
            rows: $rows,
            headings: $headings,
            title: 'Email campaigns',
            filename: $filename,
            orientation: 'landscape',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            truncated: $truncated,
            includedRows: $rows->count(),
        );
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
