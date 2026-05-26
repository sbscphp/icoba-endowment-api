<?php

namespace App\Services\Donation;

use App\Enums\Currency;
use App\Enums\PaymentGateway;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Helpers\GeneralHelper;
use App\Models\Campaign;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Contracts\User\UserRepositoryInterface;
use App\Services\Bank\BankAccountRegistry;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Pledge\PledgeScheduleService;
use App\Services\Transaction\TransactionNgnSnapshotService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class DonationIntentService
{
    public function __construct(
        private readonly PledgeBalanceService $pledgeBalance,
        private readonly PledgeScheduleService $pledgeSchedule,
        private readonly TransactionNgnSnapshotService $transactionNgnSnapshot,
        private readonly GuestDonorProfileSnapshotService $guestDonorProfileSnapshot,
        private readonly DonationCurrencyValidator $donationCurrencyValidator,
        private readonly UserRepositoryInterface $userRepository,
        private readonly DonorNameRequirement $donorNameRequirement,
        private readonly BankTransferReferenceService $bankTransferReference,
        private readonly BankAccountRegistry $bankAccountRegistry,
    ) {}

    /**
     * Create a pending donation/pledge-payment transaction (before gateway capture).
     *
     * @param  array<string, mixed>  $data
     */
    public function createPendingIntent(array $data): Transaction
    {
        $pledgeUuid = $data['pledge_uuid'] ?? null;
        if (
            is_string($pledgeUuid)
            && $pledgeUuid !== ''
            && (! isset($data['currency']) || $data['currency'] === null || $data['currency'] === '')
        ) {
            $pledge = Pledge::query()->where('uuid', $pledgeUuid)->first();
            if ($pledge !== null) {
                $data['currency'] = $pledge->currency;
            }
        }

        $data = $this->applyPledgeDonorDefaults($data);
        $data = $this->linkGuestDonorToExistingUser($data);
        $data = $this->applyLinkedUserDefaults($data);

        $guestSnapshot = $this->shouldBuildGuestDonorProfile($data)
            ? $this->guestDonorProfileSnapshot->build($data)
            : null;

        $v = Validator::make($data, [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', Rule::in(Currency::values())],
            'campaign_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:campaigns,uuid'],
            'pledge_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:pledges,uuid'],
            'user_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:users,uuid'],
            'donor_name' => ['sometimes', 'nullable', 'string', 'max:190'],
            'donor_email' => ['sometimes', 'nullable', 'email', 'max:190'],
            'donor_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'organization_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'rc_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'tin' => ['sometimes', 'nullable', 'string', 'max:64'],
            'donor_type_uuid' => ['sometimes', 'nullable', 'uuid', 'exists:donor_types,uuid'],
            'is_anonymous' => ['sometimes', 'boolean'],
            'gateway' => ['sometimes', 'nullable', 'string', 'max:64'],
            'application_type' => ['sometimes', 'nullable', 'string', 'max:48'],
            'schedule_item_id' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        $clean = $v->validated();
        $amount = (float) $clean['amount'];
        $currency = strtoupper((string) $clean['currency']);
        $explicitRate = array_key_exists('exchange_rate_to_naira', $data) && $data['exchange_rate_to_naira'] !== null
            ? (float) $data['exchange_rate_to_naira']
            : null;
        $ngnSnapshot = $this->transactionNgnSnapshot->resolve($amount, $currency, $explicitRate);
        $rate = $ngnSnapshot['exchange_rate_to_naira'];
        $amountNgn = $ngnSnapshot['amount_in_naira'];

        $pledgeUuid = $clean['pledge_uuid'] ?? null;
        $metadata = [];

        if ($pledgeUuid !== null) {
            $pledge = Pledge::query()->where('uuid', $pledgeUuid)->firstOrFail();
            if ($pledge->currency !== $currency) {
                throw ValidationException::withMessages([
                    'currency' => ['Currency must match pledge currency.'],
                ]);
            }

            $linkedUserUuid = $clean['user_uuid'] ?? null;
            if ($linkedUserUuid !== null && $pledge->user_uuid !== null && $pledge->user_uuid !== $linkedUserUuid) {
                throw ValidationException::withMessages([
                    'pledge_uuid' => ['This pledge is not linked to your account.'],
                ]);
            }

            $scheduleItemId = isset($clean['schedule_item_id']) ? (string) $clean['schedule_item_id'] : null;
            if ($scheduleItemId === '') {
                $scheduleItemId = null;
            }

            $this->pledgeSchedule->assertPaymentAllowed($pledge, $amount, $scheduleItemId);

            $allocationMeta = $this->pledgeBalance->buildOverpaymentMetadata($pledge, $amount, $amountNgn);
            $metadata = array_merge($metadata, $allocationMeta);

            if ($scheduleItemId !== null) {
                $metadata['schedule_item_id'] = $scheduleItemId;
            }

            $applicationType = $scheduleItemId !== null
                ? TransactionApplicationType::SCHEDULED_INSTALLMENT
                : TransactionApplicationType::VOLUNTARY_CONTRIBUTION;
        } else {
            $applicationType = TransactionApplicationType::INSTANT_DONATION;
        }

        if (isset($clean['application_type'])) {
            $applicationType = TransactionApplicationType::tryFrom((string) $clean['application_type']) ?? $applicationType;
        }

        $organizationName = isset($clean['organization_name']) ? trim((string) $clean['organization_name']) : null;
        if ($organizationName === '') {
            $organizationName = null;
        }

        $donorName = isset($clean['donor_name']) ? trim((string) $clean['donor_name']) : null;
        if ($donorName === '' && $organizationName !== null) {
            $donorName = $organizationName;
        }

        $rcNumber = isset($clean['rc_number']) ? trim((string) $clean['rc_number']) : null;
        if ($rcNumber === '') {
            $rcNumber = null;
        }

        $tin = isset($clean['tin']) ? trim((string) $clean['tin']) : null;
        if ($tin === '') {
            $tin = null;
        }

        if ($guestSnapshot !== null) {
            $donorName = $guestSnapshot['donor_name'] ?? $donorName;
            $organizationName = $guestSnapshot['organization_name'] ?? $organizationName;
            $rcNumber = $guestSnapshot['rc_number'] ?? $rcNumber;
            $tin = $guestSnapshot['tin'] ?? $tin;
            $metadata['guest_donor_profile'] = $guestSnapshot['guest_donor_profile'];
        }

        $campaignUuid = $clean['campaign_uuid'] ?? null;
        if ($campaignUuid === null && $pledgeUuid !== null) {
            $pledge = $pledge ?? Pledge::query()->where('uuid', $pledgeUuid)->firstOrFail();
            $campaignUuid = $pledge->campaign_uuid;
        }

        $this->donationCurrencyValidator->assertAllowed($currency, $campaignUuid);

        $transactionId = GeneralHelper::getModelUniqueRandomId([
            'modelNamespace' => Transaction::class,
            'modelField' => 'transaction_id',
            'prefix' => 'TRN-',
            'idLength' => 12,
            'idType' => 'numalpha',
        ]);
        if (is_array($transactionId)) {
            $transactionId = 'TRN-'.strtoupper(bin2hex(random_bytes(4)));
        }

        $linkedUser = ! empty($clean['user_uuid'])
            ? User::query()
                ->where('uuid', $clean['user_uuid'])
                ->first(['uuid', 'donor_type_uuid', 'firstname', 'lastname', 'organization_name'])
            : null;

        if ($donorName === null) {
            $donorName = $this->donorNameRequirement->resolveFromPayload([
                'donor_name' => null,
                'organization_name' => $organizationName,
            ], $linkedUser);
        }

        $this->donorNameRequirement->assertPresent([
            'donor_name' => $donorName,
            'organization_name' => $organizationName,
        ], $linkedUser);

        return Transaction::query()->create([
            'transaction_id' => $transactionId,
            'campaign_uuid' => $campaignUuid ?? Campaign::defaultCampaign()->uuid,
            'pledge_uuid' => $pledgeUuid,
            'user_uuid' => $clean['user_uuid'] ?? null,
            'donor_type_uuid' => $guestSnapshot['donor_type_uuid'] ?? ($clean['donor_type_uuid'] ?? $linkedUser?->donor_type_uuid),
            'donor_name' => $donorName,
            'organization_name' => $organizationName,
            'rc_number' => $rcNumber,
            'tin' => $tin,
            'donor_email' => $clean['donor_email'] ?? null,
            'donor_phone' => $guestSnapshot['donor_phone'] ?? ($clean['donor_phone'] ?? null),
            'is_anonymous' => (bool) ($clean['is_anonymous'] ?? false),
            'amount' => $amount,
            'currency' => $currency,
            'exchange_rate_to_naira' => $rate,
            'amount_in_naira' => $amountNgn,
            'status' => TransactionStatus::PENDING,
            'gateway' => $clean['gateway'] ?? null,
            'application_type' => $applicationType,
            'metadata' => array_merge((array) ($data['metadata'] ?? []), $metadata),
        ]);
    }

    /**
     * Create a pending offline bank-transfer intent with a unique narration reference.
     *
     * The customer pays externally from any bank app into one of the configured ICOBA accounts;
     * the reference is what we use later to auto-match an incoming credit to this transaction.
     *
     * @param  array<string, mixed>  $data
     */
    public function createBankTransferIntent(array $data): Transaction
    {
        $currency = isset($data['currency']) && $data['currency'] !== ''
            ? strtoupper(trim((string) $data['currency']))
            : null;

        $providedAccountNumber = isset($data['paid_into_account_number']) && $data['paid_into_account_number'] !== ''
            ? trim((string) $data['paid_into_account_number'])
            : null;
        $providedAccountKey = isset($data['paid_into_account_key']) && $data['paid_into_account_key'] !== ''
            ? trim((string) $data['paid_into_account_key'])
            : null;

        $targetAccount = null;
        if ($providedAccountNumber !== null) {
            $targetAccount = $this->bankAccountRegistry->resolveByAccountNumber($providedAccountNumber);
            if ($targetAccount === null) {
                throw ValidationException::withMessages([
                    'paid_into_account_number' => ['The selected ICOBA bank account is not recognized.'],
                ]);
            }
        } elseif ($providedAccountKey !== null) {
            $targetAccount = $this->bankAccountRegistry->resolveByAccountKey($providedAccountKey);
            if ($targetAccount === null) {
                throw ValidationException::withMessages([
                    'paid_into_account_key' => ['The selected ICOBA bank account key is not recognized.'],
                ]);
            }
        } elseif ($currency !== null) {
            $targetAccount = $this->bankAccountRegistry->resolveByCurrency($currency);
            if ($targetAccount === null) {
                throw ValidationException::withMessages([
                    'currency' => ['No ICOBA bank account is configured for currency '.$currency.'.'],
                ]);
            }
        }

        if ($targetAccount === null) {
            throw ValidationException::withMessages([
                'currency' => ['A donation currency is required to select a bank account.'],
            ]);
        }

        $accountCurrency = $targetAccount['currency'];
        if ($currency !== null && $currency !== $accountCurrency) {
            throw ValidationException::withMessages([
                'currency' => ['Donation currency must match the selected ICOBA bank account currency ('.$accountCurrency.').'],
            ]);
        }

        $data['currency'] = $accountCurrency;
        $data['gateway'] = PaymentGateway::Fcmb->value;
        $data['application_type'] = TransactionApplicationType::BANK_TRANSFER->value;

        $metadata = is_array($data['metadata'] ?? null) ? $data['metadata'] : [];
        $metadata['payment_method'] = 'bank_transfer';
        $metadata['payment_channel'] = 'offline_bank_app';
        $metadata['paid_into_account_number'] = $targetAccount['account_number'];
        $metadata['paid_into_account_key'] = $targetAccount['account_key'];
        $data['metadata'] = $metadata;

        unset($data['paid_into_account_number'], $data['paid_into_account_key']);

        $transaction = $this->createPendingIntent($data);

        $bankReference = $this->bankTransferReference->generateUniqueReference();

        $transaction->forceFill([
            'bank_transfer_reference' => $bankReference,
            'paid_into_account_number' => $targetAccount['account_number'],
        ])->save();

        return $transaction->refresh();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyPledgeDonorDefaults(array $data): array
    {
        $pledgeUuid = $data['pledge_uuid'] ?? null;
        if (! is_string($pledgeUuid) || $pledgeUuid === '') {
            return $data;
        }

        $pledge = Pledge::query()->where('uuid', $pledgeUuid)->first();
        if ($pledge === null) {
            return $data;
        }

        if (! array_key_exists('is_anonymous', $data)) {
            $data['is_anonymous'] = (bool) $pledge->is_anonymous;
        }

        if (blank($data['donor_email'] ?? null) && filled($pledge->donor_email)) {
            $data['donor_email'] = $pledge->donor_email;
        }

        if (blank(trim((string) ($data['donor_name'] ?? ''))) && filled($pledge->donor_name)) {
            $data['donor_name'] = $pledge->donor_name;
        }

        if (blank($data['donor_phone'] ?? null) && filled($pledge->donor_phone)) {
            $data['donor_phone'] = $pledge->donor_phone;
        }

        if (blank($data['donor_type_uuid'] ?? null) && filled($pledge->donor_type_uuid)) {
            $data['donor_type_uuid'] = $pledge->donor_type_uuid;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyLinkedUserDefaults(array $data): array
    {
        if (empty($data['user_uuid'])) {
            return $data;
        }

        $user = User::query()
            ->where('uuid', $data['user_uuid'])
            ->first(['uuid', 'email', 'firstname', 'lastname', 'organization_name', 'phone_number']);

        if ($user === null) {
            return $data;
        }

        if (blank($data['donor_email'] ?? null) && filled($user->email)) {
            $data['donor_email'] = $user->email;
        }

        if (blank(trim((string) ($data['donor_name'] ?? ''))) && blank(trim((string) ($data['organization_name'] ?? '')))) {
            if (filled($user->organization_name)) {
                $data['organization_name'] = trim((string) $user->organization_name);
            } else {
                $donorName = $this->donorNameRequirement->resolveFromUser($user);
                if ($donorName !== null) {
                    $data['donor_name'] = $donorName;
                }
            }
        }

        if (blank($data['donor_phone'] ?? null) && filled($user->phone_number)) {
            $data['donor_phone'] = $user->phone_number;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function linkGuestDonorToExistingUser(array $data): array
    {
        if (! empty($data['user_uuid'])) {
            return $data;
        }

        $email = isset($data['donor_email']) ? strtolower(trim((string) $data['donor_email'])) : '';
        if ($email === '') {
            return $data;
        }

        $user = $this->userRepository->findByEmail($email);
        if ($user === null) {
            return $data;
        }

        $data['user_uuid'] = $user->uuid;
        $data['donor_email'] = $email;

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function shouldBuildGuestDonorProfile(array $data): bool
    {
        if (! empty($data['user_uuid'])) {
            return false;
        }

        return ! empty($data['donor_type'])
            || ! empty($data['donor_type_uuid'])
            || ! empty($data['firstname'])
            || ! empty($data['organization_name']);
    }
}
