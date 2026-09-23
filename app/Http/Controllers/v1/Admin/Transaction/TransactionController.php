<?php

namespace App\Http\Controllers\v1\Admin\Transaction;

use App\Enums\House;
use App\Helpers\GeneralHelper;
use App\Helpers\PDFReportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DateRangeStatsRequest;
use App\Http\Requests\Admin\Transaction\TransactionListRequest;
use App\Http\Resources\Admin\TransactionListResource;
use App\Http\Resources\TransactionResource;
use App\Http\Resources\Admin\TransactionStatsResource;
use App\Models\Transaction;
use App\Responser\JsonResponser;
use App\Services\Admin\Transaction\TransactionGatewayVerificationService;
use App\Services\Admin\Transaction\TransactionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TransactionController extends Controller
{
    public function __construct(
        private readonly TransactionService $transactionService,
        private readonly TransactionGatewayVerificationService $gatewayVerification,
        private readonly PDFReportHelper $pdfReportHelper,
    ) {}

    public function houseOptions()
    {
        try {
            return JsonResponser::send(false, 'Houses retrieved.', House::options());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Transaction\TransactionController@houseOptions');
        }
    }

    public function stats(DateRangeStatsRequest $request)
    {
        try {
            $v = $request->validated();
            $payload = $this->transactionService->stats(
                $v['start_date'] ?? null,
                $v['end_date'] ?? null,
            );

            return JsonResponser::send(false, 'Transaction stats retrieved.', TransactionStatsResource::make($payload)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Transaction\TransactionController@stats');
        }
    }

    public function index(TransactionListRequest $request)
    {
        try {
            $listing = $request->validated();
            $export = $listing['export'] ?? null;

            if ($export !== null && $export !== '') {
                $this->ensureCanExport($request);
            }

            return match ($export) {
                'csv' => $this->respondListCsv($listing),
                'pdf' => $this->respondListPdf($listing),
                default => $this->respondListPaginated($listing),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Transaction\TransactionController@index');
        }
    }

    public function show(Request $request, string $transactionId)
    {
        try {
            $transaction = $this->transactionService->findTransaction($transactionId);

            $tier = $this->transactionService->resolveTierForAmount(
                $transaction->amount_in_naira !== null ? (float) $transaction->amount_in_naira : null
            );
            $transaction->setAttribute('matched_tier', $tier);

            return JsonResponser::send(false, 'Transaction retrieved.', TransactionResource::make($transaction)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Transaction\TransactionController@show');
        }
    }

    /**
     * Re-query the payment gateway for a hosted-checkout transaction and apply
     * the resulting state transition (finalize / mark failed) if it is still pending.
     */
    public function verify(Request $request, string $transactionId)
    {
        try {
            $transaction = $this->transactionService->findTransaction($transactionId);
            $result = $this->gatewayVerification->verify($transaction);

            $verified = $this->transactionService->findTransaction($transaction->uuid);
            $tier = $this->transactionService->resolveTierForAmount(
                $verified->amount_in_naira !== null ? (float) $verified->amount_in_naira : null
            );
            $verified->setAttribute('matched_tier', $tier);

            return JsonResponser::send(false, 'Transaction verified with gateway.', [
                'payment_gateway' => $result['payment_gateway'],
                'checkout_session_id' => $result['checkout_session_id'],
                'payment_status' => $result['payment_status'],
                'session_status' => $result['session_status'],
                'sync_action' => $result['sync_action'],
                'receipt_number' => $result['receipt_number'] ?? null,
                'transaction' => TransactionResource::make($verified)->resolve(),
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Transaction\TransactionController@verify');
        }
    }

    private function ensureCanExport(Request $request): void
    {
        $admin = $request->user();
        if ($admin === null || ! method_exists($admin, 'hasPermissionTo') || ! $admin->hasPermissionTo('transactions.export')) {
            abort(403, 'You do not have permission to export transactions.');
        }
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListPaginated(array $listing)
    {
        $paginator = $this->transactionService->list($listing);

        return JsonResponser::send(false, 'Transactions retrieved.', $this->paginatedPayload($paginator, TransactionListResource::class));
    }

    /**
     * @param  array<string, mixed>  $listing
     */
    private function respondListCsv(array $listing): StreamedResponse
    {
        [$collection, $truncated] = $this->transactionService->exportCollection($listing);
        $filename = 'transactions-' . now()->format('Y-m-d-His') . '.csv';

        return response()->streamDownload(function () use ($collection): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID',
                'Transaction ID',
                'Date',
                'Donor name',
                'Donor email',
                'Set',
                'Affiliated set',
                'House',
                'Anonymous',
                'Campaign',
                'Campaign code',
                'Amount',
                'Currency',
                'Amount (NGN)',
                'Gateway',
                'Reference',
                'Status',
                'Paid at',
            ]);
            $rowNumber = 0;
            foreach ($collection as $tx) {
                /** @var Transaction $tx */
                $rowNumber++;
                $tx->loadMissing(
                    'campaign:uuid,name,campaign_id',
                    'donor:uuid,firstname,lastname,email,graduation_set_uuid,house,affiliated_graduation_set_uuid',
                    'donor.graduationSet:uuid,name,set_number',
                    'donor.affiliatedGraduationSet:uuid,name,set_number',
                    'givingIdentity:uuid,house,affiliated_graduation_set_uuid',
                    'givingIdentity.affiliatedGraduationSet:uuid,name,set_number',
                    'pledge:uuid,graduation_set_uuid',
                    'pledge.graduationSet:uuid,name,set_number',
                );
                fputcsv($out, [
                    $rowNumber,
                    $tx->transaction_id,
                    $tx->created_at?->toIso8601String() ?? '',
                    $this->donorNameForRow($tx),
                    (string) ($tx->donor_email ?? $tx->donor?->email ?? ''),
                    $this->setNumberForRow($tx),
                    $this->affiliatedSetNumberForRow($tx),
                    $this->houseLabelForRow($tx),
                    $tx->is_anonymous ? '1' : '0',
                    $tx->campaign?->name ?? '',
                    $tx->campaign?->campaign_id ?? '',
                    (string) $tx->amount,
                    $tx->currency,
                    $tx->amount_in_naira !== null ? (string) $tx->amount_in_naira : '',
                    (string) ($tx->gateway ?? ''),
                    (string) ($tx->gateway_reference ?? ''),
                    $tx->status->value,
                    $tx->paid_at?->toIso8601String() ?? '',
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
        [$collection, $truncated] = $this->transactionService->exportCollection($listing);
        $collection->loadMissing(
            'campaign:uuid,name,campaign_id',
            'donor:uuid,firstname,lastname,email,graduation_set_uuid,house,affiliated_graduation_set_uuid',
            'donor.graduationSet:uuid,name,set_number',
            'donor.affiliatedGraduationSet:uuid,name,set_number',
            'givingIdentity:uuid,house,affiliated_graduation_set_uuid',
            'givingIdentity.affiliatedGraduationSet:uuid,name,set_number',
            'pledge:uuid,graduation_set_uuid',
            'pledge.graduationSet:uuid,name,set_number',
        );
        $filename = 'transactions-' . now()->format('Y-m-d-His') . '.pdf';
        $periodStart = ! empty($listing['start_date']) ? (string) $listing['start_date'] : 'All dates';
        $periodEnd = ! empty($listing['end_date']) ? (string) $listing['end_date'] : 'All dates';

        $headings = ['Transaction ID', 'Date', 'Donor', 'Set', 'Affiliated set', 'House', 'Campaign', 'Amount', 'Currency', 'Amount (NGN)', 'Gateway', 'Status'];
        $rows = $collection->map(fn(Transaction $tx): array => [
            $tx->transaction_id,
            $tx->created_at?->format('Y-m-d H:i') ?? '',
            $this->donorNameForRow($tx),
            $this->setNumberForRow($tx),
            $this->affiliatedSetNumberForRow($tx),
            $this->houseLabelForRow($tx),
            $tx->campaign?->name ?? '',
            (string) $tx->amount,
            $tx->currency,
            $tx->amount_in_naira !== null ? (string) $tx->amount_in_naira : '',
            (string) ($tx->gateway ?? ''),
            $tx->status->value,
        ]);

        return $this->pdfReportHelper->download(
            rows: $rows,
            headings: $headings,
            title: 'Transactions',
            filename: $filename,
            orientation: 'landscape',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            truncated: $truncated,
            includedRows: $rows->count(),
        );
    }

    private function donorNameForRow(Transaction $tx): string
    {
        if ((bool) $tx->is_anonymous) {
            return 'Anonymous';
        }
        if ($tx->donor !== null) {
            $name = trim(implode(' ', array_filter([
                (string) ($tx->donor->firstname ?? ''),
                (string) ($tx->donor->lastname ?? ''),
            ])));
            if ($name !== '') {
                return $name;
            }
        }

        return (string) ($tx->donor_name ?? '');
    }

    /**
     * Own graduation set (alumni): donor's set, else the linked pledge's set.
     */
    private function setNumberForRow(Transaction $tx): string
    {
        $set = $tx->donor?->graduationSet ?? $tx->pledge?->graduationSet;

        return (string) ($set?->set_number ?? '');
    }

    /**
     * Affiliated Igbobian's set (wives of ICOBA / Igbobian-owned corporates).
     */
    private function affiliatedSetNumberForRow(Transaction $tx): string
    {
        $set = $tx->donor?->affiliatedGraduationSet ?? $tx->givingIdentity?->affiliatedGraduationSet;

        return (string) ($set?->set_number ?? '');
    }

    private function houseLabelForRow(Transaction $tx): string
    {
        $house = House::payload($tx->donor?->house ?? $tx->givingIdentity?->house);

        return (string) ($house['label'] ?? '');
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
