<?php

namespace App\Http\Controllers\v1\Admin\Reconciliation;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reconciliation\CompleteReconciliationRequest;
use App\Http\Requests\Admin\Reconciliation\LinkDonationToPledgeRequest;
use App\Http\Requests\Admin\Reconciliation\ReconciliationQueueListRequest;
use App\Http\Requests\Admin\Reconciliation\UpdateReconciliationBankRequest;
use App\Http\Resources\ReconciliationQueueResource;
use App\Http\Resources\TransactionResource;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\Admin;
use App\Models\Transaction;
use App\Responser\JsonResponser;
use App\Services\Admin\Transaction\TransactionService;
use App\Services\Bank\BankAccountRegistry;
use App\Services\Pledge\PledgeReconciliationService;
use App\Services\Reconciliation\DonationReconciliationService;
use Illuminate\Http\Request;

class ReconciliationController extends Controller
{
    public function __construct(
        private readonly PledgeReconciliationService $reconciliationService,
        private readonly TransactionService $transactionService,
        private readonly DonationReconciliationService $donationReconciliation,
        private readonly BankAccountRegistry $bankAccountRegistry,
    ) {}

    public function stats()
    {
        try {
            return JsonResponser::send(false, 'Reconciliation stats.', $this->donationReconciliation->stats());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@stats');
        }
    }

    public function queue(ReconciliationQueueListRequest $request)
    {
        try {
            $validated = $request->validated();
            $dateWindow = ListingFilterRules::resolveDateWindow($validated);
            $validated['date_range'] = $dateWindow;

            $page = $this->donationReconciliation->queue($validated);

            return JsonResponser::send(false, 'Reconciliation queue.', [
                'items' => ReconciliationQueueResource::collection($page->items())->resolve(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                    'last_page' => $page->lastPage(),
                ],
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Reconciliation\ReconciliationController@queue');
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
