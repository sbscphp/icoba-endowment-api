<?php

namespace App\Http\Controllers\v1\Admin\PaymentFeeRule;

use App\Enums\Currency;
use App\Helpers\GeneralHelper;
use App\Helpers\PDFReportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DateRangeStatsRequest;
use App\Http\Requests\Admin\PaymentFeeRule\CreatePaymentFeeRuleRequest;
use App\Http\Requests\Admin\PaymentFeeRule\PaymentFeeRuleListRequest;
use App\Http\Requests\Admin\PaymentFeeRule\PaymentFeeRulePreviewRequest;
use App\Http\Requests\Admin\PaymentFeeRule\UpdatePaymentFeeRuleRequest;
use App\Http\Resources\Admin\PaymentFeeRuleResource;
use App\Models\PaymentFeeRule;
use App\Responser\JsonResponser;
use App\Services\Admin\PaymentFeeRule\PaymentFeeRuleService;
use App\Services\Payment\Fee\PaymentFeeQuoteService;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PaymentFeeRuleController extends Controller
{
    public function __construct(
        private readonly PaymentFeeRuleService $feeRuleService,
        private readonly PaymentFeeQuoteService $feeQuoteService,
        private readonly PDFReportHelper $pdfReportHelper,
    ) {}

    public function options()
    {
        try {
            return JsonResponser::send(false, 'Payment fee rule options retrieved.', [
                'charges_enabled' => $this->feeQuoteService->enabled(),
                'bank_transfer_applies_fee' => (bool) config('platform_charges.bank_transfer_applies_fee', false),
                'currencies' => Currency::values(),
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\PaymentFeeRule\PaymentFeeRuleController@options');
        }
    }

    public function stats(DateRangeStatsRequest $request)
    {
        try {
            return JsonResponser::send(false, 'Payment fee rule stats retrieved.', $this->feeRuleService->stats($request->validated()));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\PaymentFeeRule\PaymentFeeRuleController@stats');
        }
    }

    /**
     * Same pricing the donor sees, for checking a rule before switching it on.
     */
    public function preview(PaymentFeeRulePreviewRequest $request)
    {
        try {
            $validated = $request->validated();
            $quote = $this->feeQuoteService->quote((float) $validated['amount'], (string) $validated['currency']);

            return JsonResponser::send(false, 'Fee preview retrieved.', $quote->toArray());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\PaymentFeeRule\PaymentFeeRuleController@preview');
        }
    }

    public function index(PaymentFeeRuleListRequest $request)
    {
        try {
            $listing = $request->validated();

            return match ($listing['export'] ?? null) {
                'csv' => $this->respondListCsv($listing),
                'pdf' => $this->respondListPdf($listing),
                default => $this->respondListPaginated($listing),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\PaymentFeeRule\PaymentFeeRuleController@index');
        }
    }

    public function store(CreatePaymentFeeRuleRequest $request)
    {
        try {
            $rule = $this->feeRuleService->create($request->validated(), $request->user()?->uuid);

            return JsonResponser::send(false, 'Payment fee rule created successfully.', PaymentFeeRuleResource::make($rule)->resolve(), 201);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\PaymentFeeRule\PaymentFeeRuleController@store');
        }
    }

    public function show(string $ruleId)
    {
        try {
            $rule = $this->feeRuleService->find($ruleId);

            return JsonResponser::send(false, 'Payment fee rule retrieved.', PaymentFeeRuleResource::make($rule)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\PaymentFeeRule\PaymentFeeRuleController@show');
        }
    }

    public function update(UpdatePaymentFeeRuleRequest $request, string $ruleId)
    {
        try {
            $rule = $this->feeRuleService->update($ruleId, $request->validated(), $request->user()?->uuid);

            return JsonResponser::send(false, 'Payment fee rule updated.', PaymentFeeRuleResource::make($rule)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\PaymentFeeRule\PaymentFeeRuleController@update');
        }
    }

    public function toggleStatus(Request $request, string $ruleId)
    {
        try {
            $rule = $this->feeRuleService->toggleActiveStatus($ruleId, $request->user()?->uuid);
            $message = (bool) $rule->is_active ? 'Payment fee rule activated.' : 'Payment fee rule deactivated.';

            return JsonResponser::send(false, $message, PaymentFeeRuleResource::make($rule)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\PaymentFeeRule\PaymentFeeRuleController@toggleStatus');
        }
    }

    public function destroy(Request $request, string $ruleId)
    {
        try {
            $this->feeRuleService->delete($ruleId, $request->user()?->uuid);

            return JsonResponser::send(false, 'Payment fee rule deleted successfully.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\PaymentFeeRule\PaymentFeeRuleController@destroy');
        }
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListPaginated(array $listing)
    {
        $paginator = $this->feeRuleService->list($listing);

        return JsonResponser::send(false, 'Payment fee rules retrieved.', $this->paginatedPayload($paginator));
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListCsv(array $listing): StreamedResponse
    {
        [$collection, $truncated] = $this->feeRuleService->exportCollection($listing);
        $filename = 'payment-fee-rules-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($collection): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $this->headings());

            $rowNumber = 0;
            foreach ($collection as $rule) {
                /** @var PaymentFeeRule $rule */
                $rowNumber++;
                fputcsv($out, $this->tabularRow($rule, $rowNumber));
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
        [$collection, $truncated] = $this->feeRuleService->exportCollection($listing);
        $filename = 'payment-fee-rules-'.now()->format('Y-m-d-His').'.pdf';
        $periodStart = ! empty($listing['start_date']) ? (string) $listing['start_date'] : 'All dates';
        $periodEnd = ! empty($listing['end_date']) ? (string) $listing['end_date'] : 'All dates';

        $rows = $collection->values()->map(fn (PaymentFeeRule $rule, int $index): array => $this->tabularRow($rule, $index + 1));

        return $this->pdfReportHelper->download(
            rows: $rows,
            headings: $this->headings(),
            title: 'Payment fee rules',
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
     * @return list<string>
     */
    private function headings(): array
    {
        return ['ID', 'Name', 'Currency', 'Min amount', 'Max amount', 'Percentage', 'Fixed amount', 'Cap amount', 'Status', 'Date created', 'Last updated'];
    }

    /**
     * @return list<int|string|null>
     */
    private function tabularRow(PaymentFeeRule $rule, int $rowNumber): array
    {
        return [
            $rowNumber,
            $rule->name,
            $rule->currency,
            (string) $rule->min_amount,
            $rule->max_amount !== null ? (string) $rule->max_amount : '',
            (string) $rule->percentage,
            (string) $rule->fixed_amount,
            $rule->cap_amount !== null ? (string) $rule->cap_amount : '',
            $rule->is_active ? 'active' : 'inactive',
            $rule->created_at?->toIso8601String() ?? '',
            $rule->updated_at?->toIso8601String() ?? '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function paginatedPayload(LengthAwarePaginator $paginator): array
    {
        $payload = $paginator->toArray();
        $payload['data'] = PaymentFeeRuleResource::collection($paginator)->resolve();

        return $payload;
    }
}
