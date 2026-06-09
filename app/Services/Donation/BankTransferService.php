<?php

namespace App\Services\Donation;

use App\Enums\Currency;
use App\Enums\ePermission;
use App\Enums\ModuleEnums;
use App\Enums\TransactionApplicationType;
use App\Exceptions\ApiException;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\GenericDatabaseNotification;
use App\Services\Admin\Transaction\TransactionService;
use App\Services\Notifications\NotificationDispatchService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing operations for the offline bank-transfer flow.
 *
 * Owns the ownership checks, member defaults, and the locked
 * "I have paid" state transition so the HTTP controller stays a
 * thin adapter and never touches Eloquent directly.
 */
class BankTransferService
{
    public function __construct(
        private readonly DonationIntentService $donationIntentService,
        private readonly TransactionService $transactionService,
        private readonly DonorNameRequirement $donorNameRequirement,
        private readonly NotificationDispatchService $notificationDispatchService,
    ) {}

    /**
     * Build a pending bank-transfer intent for a customer (member or guest).
     *
     * @param  array<string, mixed>  $validated
     */
    public function createIntentForCustomer(array $validated, ?User $user): Transaction
    {
        if ($user !== null && ! empty($validated['pledge_uuid'])) {
            $this->assertPledgeOwnership((string) $validated['pledge_uuid'], $user);
        }

        if ($user !== null) {
            $validated = $this->applyMemberDefaults($validated, $user);
        }

        $transaction = $this->donationIntentService->createBankTransferIntent($validated);

        return $this->transactionService->findTransaction($transaction->uuid);
    }

    /**
     * Mark a customer's bank-transfer intent as awaiting verification.
     * Idempotent — replaying is a no-op once the timestamp is set.
     */
    public function confirmPaymentForCustomer(string $transactionUuid, ?User $user): Transaction
    {
        $transaction = $this->resolveCustomerTransaction($transactionUuid, $user);
        $confirmed = false;

        DB::transaction(function () use ($transaction, &$confirmed): void {
            /** @var Transaction|null $locked */
            $locked = Transaction::query()
                ->whereKey($transaction->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null || $locked->awaiting_bank_verification_at !== null) {
                return;
            }

            $locked->forceFill([
                'awaiting_bank_verification_at' => now(),
            ])->save();

            $confirmed = true;
        });

        $transaction = $this->transactionService->findTransaction($transaction->uuid);

        if ($confirmed) {
            $this->notifyAdminsOfConfirmedBankTransferPayment($transaction);
        }

        return $transaction;
    }

    private function assertPledgeOwnership(string $pledgeUuid, User $user): void
    {
        $pledge = Pledge::query()->where('uuid', $pledgeUuid)->first();
        if ($pledge === null) {
            throw ValidationException::withMessages([
                'pledge_uuid' => ['The selected pledge could not be found.'],
            ]);
        }

        if ($pledge->user_uuid !== null && $pledge->user_uuid !== $user->uuid) {
            throw ValidationException::withMessages([
                'pledge_uuid' => ['This pledge is not linked to your account.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function applyMemberDefaults(array $validated, User $user): array
    {
        $validated['user_uuid'] = $user->uuid;

        if (! isset($validated['donor_email']) || ! is_string($validated['donor_email']) || trim($validated['donor_email']) === '') {
            $validated['donor_email'] = $user->email;
        }

        $resolvedName = $this->donorNameRequirement->resolveFromPayload($validated, $user);
        if ($resolvedName !== null) {
            $validated['donor_name'] = $resolvedName;
            if (filled($user->organization_name) && trim((string) ($validated['organization_name'] ?? '')) === '') {
                $validated['organization_name'] = trim((string) $user->organization_name);
            }
        }

        return $validated;
    }

    private function resolveCustomerTransaction(string $transactionUuid, ?User $user): Transaction
    {
        $query = Transaction::query()->where('uuid', $transactionUuid);

        if ($user !== null) {
            $query->where(function ($builder) use ($user): void {
                $builder->where('user_uuid', $user->uuid)
                    ->orWhereNull('user_uuid');
            });
        }

        $transaction = $query->first();
        if ($transaction === null) {
            throw new ApiException('Bank transfer transaction not found.', 404);
        }

        $applicationType = $transaction->application_type;
        $applicationTypeValue = $applicationType instanceof TransactionApplicationType
            ? $applicationType->value
            : (string) $applicationType;

        if ($applicationTypeValue !== TransactionApplicationType::BANK_TRANSFER->value) {
            throw new ApiException('This transaction is not a bank transfer intent.', 422);
        }

        return $transaction;
    }

    private function notifyAdminsOfConfirmedBankTransferPayment(Transaction $transaction): void
    {
        $reference = trim((string) $transaction->bank_transfer_reference);
        $amountLabel = $this->formatPaymentAmount((float) $transaction->amount, (string) $transaction->currency);
        $adminFrontendBase = rtrim((string) config('app.admin_frontend_url'), '/');
        $actionUrl = $adminFrontendBase !== ''
            ? $adminFrontendBase.'/reconciliation/queue/'.$transaction->uuid
            : null;

        $referenceLabel = $reference !== '' ? $reference : 'N/A';
        $message = sprintf(
            'A payment of %s with reference %s has been made. Reconcile.',
            $amountLabel,
            $referenceLabel,
        );

        $this->notificationDispatchService->notifyAdminsWithAllPermissions(
            [
                ePermission::TRANSACTIONS_READ->value,
                ePermission::RECONCILIATION_READ->value,
            ],
            new GenericDatabaseNotification(
                module: ModuleEnums::reconciliation->value,
                event: 'bank_transfer_payment_confirmed',
                title: 'New bank transfer payment',
                message: $message,
                meta: [
                    'transaction_uuid' => $transaction->uuid,
                    'amount' => (string) $transaction->amount,
                    'currency' => (string) $transaction->currency,
                    'bank_transfer_reference' => $reference !== '' ? $reference : null,
                    'confirmed_at' => now()->toIso8601String(),
                ],
                actionUrl: $actionUrl,
                mailSubject: 'New bank transfer payment requires reconciliation',
                icon: '/icons/bank-transfer-payment.png',
                severity: 'info',
                tags: ['reconciliation', 'bank_transfer', 'payment'],
                sendMail: true,
                sendPush: false,
            ),
        );
    }

    private function formatPaymentAmount(float $amount, string $currency): string
    {
        $currencyEnum = Currency::tryFrom(strtoupper($currency));
        $symbol = $currencyEnum?->symbol() ?? strtoupper($currency).' ';

        return $symbol.number_format($amount, 2);
    }
}
