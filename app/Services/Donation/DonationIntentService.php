<?php

namespace App\Services\Donation;

use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Helpers\GeneralHelper;
use App\Models\Campaign;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Services\Pledge\PledgeBalanceService;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class DonationIntentService
{
    public function __construct(
        private readonly PledgeBalanceService $pledgeBalance,
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

        $v = Validator::make($data, [
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['required', 'string', 'max:8'],
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
        ]);

        if ($v->fails()) {
            throw new ValidationException($v);
        }

        $clean = $v->validated();
        $amount = (float) $clean['amount'];
        $currency = strtoupper((string) $clean['currency']);
        $rate = isset($clean['exchange_rate_to_naira']) ? (float) $clean['exchange_rate_to_naira'] : null;
        $amountNgn = $rate !== null ? round($amount * $rate, 2) : null;

        $pledgeUuid = $clean['pledge_uuid'] ?? null;
        $metadata = [];

        if ($pledgeUuid !== null) {
            $pledge = Pledge::query()->where('uuid', $pledgeUuid)->firstOrFail();
            if ($pledge->currency !== $currency) {
                throw ValidationException::withMessages([
                    'currency' => ['Currency must match pledge currency.'],
                ]);
            }
            $remaining = (float) $this->pledgeBalance->remainingAmount($pledge);
            if ($remaining <= 0) {
                throw ValidationException::withMessages([
                    'pledge_uuid' => ['This pledge is already fulfilled.'],
                ]);
            }

            $allocationMeta = $this->pledgeBalance->buildOverpaymentMetadata($pledge, $amount, $amountNgn);
            $metadata = array_merge($metadata, $allocationMeta);

            $applicationType = TransactionApplicationType::VOLUNTARY_CONTRIBUTION;
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

        $campaignUuid = $clean['campaign_uuid'] ?? null;
        if ($campaignUuid === null && $pledgeUuid !== null) {
            $pledge = $pledge ?? Pledge::query()->where('uuid', $pledgeUuid)->firstOrFail();
            $campaignUuid = $pledge->campaign_uuid;
        }

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

        return Transaction::query()->create([
            'transaction_id' => $transactionId,
            'campaign_uuid' => $campaignUuid ?? Campaign::defaultCampaign()->uuid,
            'pledge_uuid' => $pledgeUuid,
            'user_uuid' => $clean['user_uuid'] ?? null,
            'donor_type_uuid' => $clean['donor_type_uuid'] ?? null,
            'donor_name' => $donorName,
            'organization_name' => $organizationName,
            'rc_number' => $rcNumber,
            'tin' => $tin,
            'donor_email' => $clean['donor_email'] ?? null,
            'donor_phone' => $clean['donor_phone'] ?? null,
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
}
