<?php

namespace App\Http\Controllers\v1\Admin\Reconciliation;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Reconciliation\LinkDonationToPledgeRequest;
use App\Http\Resources\TransactionResource;
use App\Models\Transaction;
use App\Responser\JsonResponser;
use App\Services\Admin\Transaction\TransactionService;
use App\Services\Pledge\PledgeReconciliationService;

class ReconciliationController extends Controller
{
    public function __construct(
        private readonly PledgeReconciliationService $reconciliationService,
        private readonly TransactionService $transactionService,
    ) {}

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
