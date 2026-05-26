<?php

namespace App\Http\Controllers\v1\Customer\Donation;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Donation\BankTransferIntentRequest;
use App\Models\Transaction;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Bank\BankAccountRegistry;
use App\Services\Donation\BankTransferService;
use Illuminate\Http\Request;

class BankTransferController extends Controller
{
    public function __construct(
        private readonly BankTransferService $bankTransferService,
        private readonly BankAccountRegistry $bankAccountRegistry,
    ) {}

    public function intent(BankTransferIntentRequest $request)
    {
        try {
            $transaction = $this->bankTransferService->createIntentForCustomer(
                $request->validated(),
                $this->customer($request),
            );

            return JsonResponser::send(
                false,
                'Bank transfer intent created.',
                $this->intentPayload($transaction),
                201,
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\BankTransferController@intent');
        }
    }

    public function confirmPayment(Request $request, string $transactionUuid)
    {
        try {
            $transaction = $this->bankTransferService->confirmPaymentForCustomer(
                $transactionUuid,
                $this->customer($request),
            );

            return JsonResponser::send(
                false,
                'Bank transfer marked as awaiting verification.',
                $this->intentPayload($transaction),
                200,
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\BankTransferController@confirmPayment');
        }
    }

    private function customer(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function intentPayload(Transaction $transaction): array
    {
        $targetAccount = $this->bankAccountRegistry->resolveByAccountNumber($transaction->paid_into_account_number)
            ?? $this->bankAccountRegistry->resolveByCurrency((string) $transaction->currency);

        return [
            'transaction_uuid' => $transaction->uuid,
            // 'transaction_id' => $transaction->transaction_id,
            'amount' => (string) $transaction->amount,
            'currency' => $transaction->currency,
            'amount_in_naira' => $transaction->amount_in_naira !== null ? (string) $transaction->amount_in_naira : null,
            'status' => $transaction->status?->value,
            'paid_at' => $transaction->paid_at,
            'is_anonymous' => (bool) $transaction->is_anonymous,
            'bank_transfer_reference' => $transaction->bank_transfer_reference,
            'paid_into_account_number' => $transaction->paid_into_account_number,
            'awaiting_bank_verification_at' => $transaction->awaiting_bank_verification_at,
            'target_account' => $targetAccount !== null ? [
                'account_key' => $targetAccount['account_key'],
                'currency' => $targetAccount['currency'],
                'currency_symbol' => $targetAccount['currency_symbol'],
                'account_number' => $targetAccount['account_number'],
            ] : null,
            'instructions' => 'Transfer from any bank app into the account below. Put the reference in your payment narration so we can match it.',
        ];
    }
}
