<?php

namespace App\Http\Controllers\v1\Admin\Reconciliation;

use App\Helpers\GeneralHelper;
use App\Helpers\PDFReportHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DateRangeStatsRequest;
use App\Http\Requests\Admin\Reconciliation\CompleteReconciliationRequest;
use App\Http\Requests\Admin\Reconciliation\CreateReconciliationQueueRequest;
use App\Http\Requests\Admin\Reconciliation\LinkDonationToPledgeRequest;
use App\Http\Requests\Admin\Reconciliation\ReconciliationPledgeSearchRequest;
use App\Http\Requests\Admin\Reconciliation\ReconciliationQueueListRequest;
use App\Http\Requests\Admin\Reconciliation\UpdateReconciliationBankRequest;
use App\Http\Resources\Admin\ReconciliationQueueResource;
use App\Http\Resources\TransactionResource;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Enums\TransactionStatus;
use App\Models\Admin;
use App\Models\Transaction;
use App\Responser\JsonResponser;
use App\Services\Admin\Transaction\TransactionService;
use App\Services\Bank\BankAccountRegistry;
use App\Services\Pledge\PledgeReconciliationService;
use App\Services\Reconciliation\DonationReconciliationService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ReconciliationController extends Controller
{
    public function __construct(
        private readonly PledgeReconciliationService $reconciliationService,
        private readonly TransactionService $transactionService,
        private readonly DonationReconciliationService $donationReconciliation,
        private readonly BankAccountRegistry $bankAccountRegistry,
        private readonly PDFReportHelper $pdfReportHelper,
    ) {}

    public function stats(DateRangeStatsRequest $request)
    {
        try {
            return JsonResponser::send(false, 'Reconciliation stats.', $this->donationReconciliation->stats($request->validated()));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@stats');
        }
    }

    public function queue(ReconciliationQueueListRequest $request)
    {
        try {
            $validated = $this->validatedQueueListing($request);
            $export = $validated['export'] ?? null;

            return match ($export) {
                'csv' => $this->respondQueueCsv($validated),
                'pdf' => $this->respondQueuePdf($validated),
                default => $this->respondQueuePaginated($validated),
            };
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@queue');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedQueueListing(ReconciliationQueueListRequest $request): array
    {
        $validated = $request->validated();
        $validated['date_range'] = ListingFilterRules::resolveDateWindow($validated);

        return $validated;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function respondQueuePaginated(array $validated)
    {
        $page = $this->donationReconciliation->queue($validated);

        $payload = $page->toArray();
        $payload['data'] = ReconciliationQueueResource::collection($page)->resolve();

        return JsonResponser::send(false, 'Reconciliation queue.', $payload);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function respondQueueCsv(array $validated): StreamedResponse
    {
        [$collection, $truncated] = $this->donationReconciliation->exportCollection($validated);
        $filename = 'reconciliation-queue-'.now()->format('Y-m-d-His').'.csv';

        return response()->streamDownload(function () use ($collection): void {
            $out = fopen('php://output', 'w');
            if ($out === false) {
                return;
            }

            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, [
                'ID',
                'Transaction ID',
                'Bank transfer reference',
                'Amount',
                'Currency',
                'Amount (NGN)',
                'Status',
                'Reconciliation status',
                'Donor name',
                'Donor email',
                'Campaign',
                'Paid into',
                'FCMB statement reference',
                'Reconciled at',
                'Reconciled by',
                'Source',
                'Created at',
                'Paid at',
            ]);

            $rowNumber = 0;
            foreach ($collection as $transaction) {
                $rowNumber++;
                $row = ReconciliationQueueResource::make($transaction)->resolve();
                fputcsv($out, [
                    $rowNumber,
                    $row['transaction_id'] ?? '',
                    $row['bank_transfer_reference'] ?? '',
                    $row['amount'] ?? '',
                    $row['currency'] ?? '',
                    $row['amount_in_naira'] ?? '',
                    $row['status'] ?? '',
                    $row['reconciliation_status'] ?? '',
                    $row['donor_name'] ?? '',
                    $row['donor_email'] ?? '',
                    $row['campaign']['name'] ?? '',
                    $row['paid_into'] ?? '',
                    $row['fcmb_statement_reference'] ?? '',
                    $this->formatExportDateTime($row['reconciled_at'] ?? null),
                    $row['reconciled_by_admin_name'] ?? '',
                    $row['source'] ?? '',
                    $this->formatExportDateTime($row['created_at'] ?? null),
                    $this->formatExportDateTime($row['paid_at'] ?? null),
                ]);
            }

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'X-Export-Truncated' => $truncated ? '1' : '0',
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function respondQueuePdf(array $validated)
    {
        [$collection, $truncated] = $this->donationReconciliation->exportCollection($validated);
        $filename = 'reconciliation-queue-'.now()->format('Y-m-d-His').'.pdf';
        $periodStart = ! empty($validated['start_date']) ? (string) $validated['start_date'] : 'All dates';
        $periodEnd = ! empty($validated['end_date']) ? (string) $validated['end_date'] : 'All dates';

        $headings = [
            'Transaction ID',
            'Reference',
            'Amount',
            'Currency',
            'Amount (NGN)',
            'Reconciliation status',
            'Donor',
            'Campaign',
            'Paid into',
            'Reconciled at',
            'Created at',
        ];

        $rows = $collection->map(function (Transaction $transaction): array {
            $row = ReconciliationQueueResource::make($transaction)->resolve();

            return [
                $row['transaction_id'] ?? '',
                $row['bank_transfer_reference'] ?? '',
                $row['amount'] ?? '',
                $row['currency'] ?? '',
                $row['amount_in_naira'] ?? '',
                $row['reconciliation_status'] ?? '',
                $row['donor_name'] ?? '',
                $row['campaign']['name'] ?? '',
                $row['paid_into'] ?? '',
                $this->formatExportDateTime($row['reconciled_at'] ?? null, 'Y-m-d H:i'),
                $this->formatExportDateTime($row['created_at'] ?? null, 'Y-m-d H:i'),
            ];
        });

        return $this->pdfReportHelper->download(
            rows: $rows,
            headings: $headings,
            title: 'Reconciliation queue',
            filename: $filename,
            orientation: 'landscape',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            truncated: $truncated,
            includedRows: $rows->count(),
        );
    }

    private function formatExportDateTime(mixed $value, string $format = 'Y-m-d H:i:s'): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format($format);
        }

        if (is_string($value)) {
            try {
                return \Carbon\Carbon::parse($value)->format($format);
            } catch (\Throwable) {
                return $value;
            }
        }

        return (string) $value;
    }

    public function store(CreateReconciliationQueueRequest $request)
    {
        try {
            $admin = $request->user();
            if (! $admin instanceof Admin) {
                return JsonResponser::send(true, 'Admin authentication required.', null, 401);
            }

            $transaction = $this->donationReconciliation->createManual($request->validated(), $admin->uuid);

            $message = $transaction->status === TransactionStatus::SUCCESSFUL
                ? 'Reconciliation transaction created and completed.'
                : 'Reconciliation transaction created.';

            return JsonResponser::send(false, $message, [
                'transaction' => TransactionResource::make($transaction)->resolve(),
            ], 201);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@store');
        }
    }

    public function show(string $uuid)
    {
        try {
            $transaction = $this->donationReconciliation->findQueueItem($uuid);

            return JsonResponser::send(false, 'Reconciliation transaction.', [
                'transaction' => TransactionResource::make($transaction)->resolve(),
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@show');
        }
    }

    public function donorSearch(Request $request)
    {
        try {
            $query = (string) $request->query('q', '');
            $donors = $this->donationReconciliation->searchDonors($query);

            return JsonResponser::send(false, 'Donor search results.', [
                'items' => $donors->values()->all(),
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@donorSearch');
        }
    }

    public function pledgeSearch(ReconciliationPledgeSearchRequest $request)
    {
        try {
            $validated = $request->validated();
            $currency = isset($validated['currency']) && $validated['currency'] !== ''
                ? (string) $validated['currency']
                : null;
            $campaignUuid = isset($validated['campaign_uuid']) && $validated['campaign_uuid'] !== ''
                ? (string) $validated['campaign_uuid']
                : null;
            $pledges = $this->donationReconciliation->searchPendingPledges(
                (string) $validated['user_identity'],
                $currency,
                $campaignUuid,
            );

            return JsonResponser::send(false, 'Pending pledge search results.', [
                'items' => $pledges->values()->all(),
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@pledgeSearch');
        }
    }

    public function bankAccounts()
    {
        try {
            return JsonResponser::send(false, 'Reconciliation bank accounts.', [
                'accounts' => $this->bankAccountRegistry->accountsForAdmin(),
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@bankAccounts');
        }
    }

    public function tierPreview(string $uuid)
    {
        try {
            $transaction = $this->donationReconciliation->findQueueItem($uuid);

            return JsonResponser::send(false, 'Tier preview.', $this->donationReconciliation->tierPreview($transaction));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@tierPreview');
        }
    }

    public function updateBank(UpdateReconciliationBankRequest $request, string $uuid)
    {
        try {
            $transaction = $this->donationReconciliation->findQueueItem($uuid);
            $updated = $this->donationReconciliation->updateBankAccount($transaction, $request->validated());
            $preview = $this->donationReconciliation->tierPreview($updated);

            return JsonResponser::send(false, 'Reconciliation bank account updated.', [
                'transaction' => TransactionResource::make($updated)->resolve(),
                'tier_preview' => $preview,
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@updateBank');
        }
    }

    public function complete(CompleteReconciliationRequest $request, string $uuid)
    {
        try {
            $admin = $request->user();
            if (! $admin instanceof Admin) {
                return JsonResponser::send(true, 'Admin authentication required.', null, 401);
            }

            $transaction = $this->donationReconciliation->findQueueItem($uuid);
            $finalized = $this->donationReconciliation->completeManual($transaction, $request->validated(), $admin->uuid);

            return JsonResponser::send(false, 'Reconciliation completed.', [
                'transaction' => TransactionResource::make($finalized)->resolve(),
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@complete');
        }
    }

    public function linkDonationToPledge(LinkDonationToPledgeRequest $request)
    {
        try {
            $v = $request->validated();
            $payment = Transaction::query()->where('uuid', $v['payment_transaction_uuid'])->firstOrFail();
            $placeholder = isset($v['supersede_transaction_uuid'])
                ? Transaction::query()->where('uuid', $v['supersede_transaction_uuid'])->first()
                : null;

            $this->reconciliationService->linkDonationToPledge(
                $payment,
                $v['pledge_uuid'],
                $placeholder,
            );

            $payment = $this->transactionService->findTransaction($payment->uuid);

            return JsonResponser::send(false, 'Payment linked to pledge.', TransactionResource::make($payment)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@linkDonationToPledge');
        }
    }
}
