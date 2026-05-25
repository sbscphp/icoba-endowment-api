<?php

namespace App\Services\Donation;

use App\Enums\Currency;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Helpers\GeneralHelper;
use App\Models\Campaign;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use App\Repositories\Contracts\User\UserRepositoryInterface;
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
            'idLength' => 7,
            'idType' => 'numalpha',
        ]);
        if (is_array($transactionId)) {
            $transactionId = 'TRN-'.strtoupper(bin2hex(random_bytes(4)));
        }

        $linkedUser = ! empty($clean['user_uuid'])
            ? User::query()->where('uuid', $clean['user_uuid'])->first(['uuid', 'donor_type_uuid', 'firstname', 'lastname'])
            : null;

        if ($donorName === null && $linkedUser !== null) {
            $donorName = trim(implode(' ', array_filter([
                (string) ($linkedUser->firstname ?? ''),
                (string) ($linkedUser->lastname ?? ''),
            ]))) ?: null;
        }

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
            ->first(['uuid', 'email', 'firstname', 'lastname', 'phone_number']);

        if ($user === null) {
            return $data;
        }

        if (blank($data['donor_email'] ?? null) && filled($user->email)) {
            $data['donor_email'] = $user->email;
        }

        if (blank(trim((string) ($data['donor_name'] ?? '')))) {
            $donorName = trim(implode(' ', array_filter([
                (string) ($user->firstname ?? ''),
                (string) ($user->lastname ?? ''),
            ])));

            if ($donorName !== '') {
                $data['donor_name'] = $donorName;
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
