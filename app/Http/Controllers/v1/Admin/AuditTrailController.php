<?php

namespace App\Http\Controllers\v1\Admin;

use App\Enums\UserTypeEnum;
use App\Helpers\GeneralHelper;
use App\Helpers\PDFReportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\AuditTrailListingRequest;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Responser\JsonResponser;
use App\Services\Audit\AuditTrailQueryService;
use App\Support\ListingQuery;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AuditTrailController extends Controller
{
    public function __construct(
        private readonly AuditTrailQueryService $auditTrailQuery,
        private readonly PDFReportHelper $pdfReportHelper,
    ) {}

    public function index(AuditTrailListingRequest $request): SymfonyResponse
    {
        try {
            $listing = ListingQuery::fromValidated($request->validated());
            $export = $request->validated('export');

            return match ($export) {
                'csv' => $this->respondCsv($listing),
                'pdf' => $this->respondPdf($listing),
                default => JsonResponser::send(
                    false,
                    'Audit logs retrieved.',
                    AuditLogResource::collection(
                        $this->auditTrailQuery
                            ->queryForListing($listing)
                            ->paginate(perPage: $listing->perPage, page: $listing->page)
                    )->resolve(),
                ),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'AuditTrailController@index');
        }
    }

    private function respondCsv(ListingQuery $listing): StreamedResponse|SymfonyResponse
    {
        /** @var Collection<int, AuditLog> $collection */
        [$collection, $truncated] = $this->auditTrailQuery->exportCollection($listing);

        $filename = 'audit-trail-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($collection): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, [
                'UUID',
                'Created at',
                'User type',
                'Actor email',
                'Actor name',
                'Action module',
                'Action',
                'Model',
                'Model ID',
                'Description',
                'IP address',
                'HTTP status',
                'User agent',
            ]);

            foreach ($collection as $log) {
                fputcsv($out, $this->tabularRow($log));
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Truncated' => $truncated ? '1' : '0',
        ]);
    }

    private function respondPdf(ListingQuery $listing): SymfonyResponse
    {
        /** @var Collection<int, AuditLog> $collection */
        [$collection, $truncated] = $this->auditTrailQuery->exportCollection($listing);

        $filename = 'audit-trail-'.now()->format('Y-m-d-His').'.pdf';

        $periodStart = $listing->startDate?->toDateString() ?? 'All dates';
        $periodEnd = $listing->endDate?->toDateString() ?? 'All dates';

        $headings = [
            'UUID',
            'Created',
            'User type',
            'Actor',
            'Module',
            'Action',
            'Model',
            'Description',
            'IP',
            'HTTP',
            'User agent',
        ];

        $rows = $collection->map(fn (AuditLog $log): array => [
            (string) $log->uuid,
            $log->created_at?->format('Y-m-d H:i') ?? '',
            $log->user_type->value,
            $this->actorSummary($log),
            $log->action_module->value,
            $log->action->value,
            $log->model !== null ? class_basename((string) $log->model) : '',
            $this->truncatePdfCell((string) ($log->description ?? '')),
            (string) ($log->ip_address ?? ''),
            $log->http_status !== null ? (string) $log->http_status : '',
            $this->truncatePdfCell((string) ($log->user_agent ?? ''), 80),
        ]);

        return $this->pdfReportHelper->download(
            rows: $rows,
            headings: $headings,
            title: 'Audit trail',
            filename: $filename,
            orientation: 'landscape',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            generatedAt: now((string) config('app.timezone')),
            truncated: $truncated,
            includedRows: $rows->count(),
        );
    }

    /**
     * @return list<int|string|null>
     */
    private function tabularRow(AuditLog $log): array
    {
        [$email, $name] = $this->actorEmailAndName($log);

        return [
            $log->uuid,
            $log->created_at?->toIso8601String() ?? '',
            $log->user_type->value,
            $email,
            $name,
            $log->action_module->value,
            $log->action->value,
            $log->model ?? '',
            $log->model_id ?? '',
            $log->description ?? '',
            $log->ip_address ?? '',
            $log->http_status,
            $log->user_agent ?? '',
        ];
    }

    private function actorSummary(AuditLog $log): string
    {
        [$email, $name] = $this->actorEmailAndName($log);

        if ($email !== '' && $name !== '') {
            return $name.' <'.$email.'>';
        }

        return $email !== '' ? $email : $name;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function actorEmailAndName(AuditLog $log): array
    {
        if ($log->user_type === UserTypeEnum::CUSTOMER && $log->relationLoaded('customerUser') && $log->customerUser !== null) {
            $user = $log->customerUser;
            $name = trim(implode(' ', array_filter([$user->firstname ?? '', $user->lastname ?? ''])));

            return [$user->email ?? '', $name];
        }

        if ($log->user_type === UserTypeEnum::ADMIN && $log->relationLoaded('adminUser') && $log->adminUser !== null) {
            $admin = $log->adminUser;

            return [$admin->email ?? '', $admin->name ?? ''];
        }

        return ['', ''];
    }

    private function truncatePdfCell(string $text, int $max = 120): string
    {
        if (strlen($text) <= $max) {
            return $text;
        }

        return substr($text, 0, $max).'…';
    }
}
