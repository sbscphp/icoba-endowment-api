<?php

namespace App\Http\Resources;

use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\GivingIdentity;
use App\Models\TierConfiguration;
use App\Models\Transaction;
use App\Services\Bank\BankAccountRegistry;
use App\Services\Receipt\ReceiptService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $set = $this->donor?->graduationSet;
        /** @var TierConfiguration|null $tier */
        $tier = $this->resource->getAttribute('matched_tier');

        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $reconciliationDraft = is_array($metadata['reconciliation_draft'] ?? null)
            ? $metadata['reconciliation_draft']
            : [];
        $receiptLinks = $this->resolveReceiptLinks();
        $donorType = $this->donorType ?? ($this->donor?->donorType);

        return [
            'transaction_uuid' => $this->uuid,
            'transaction_id' => $this->transaction_id,
            'transaction_date' => ($this->paid_at ?? $this->created_at)?->copy()->utc()->toDateString(),
            'transaction_time' => ($this->paid_at ?? $this->created_at)?->copy()->utc()->format('H:i:s\Z'),
            'paid_at' => $this->paid_at,
            // 'created_at' => $this->created_at,
            // 'updated_at' => $this->updated_at,
            'user_uuid' => $this->user_uuid ?? $this->givingIdentity?->user_uuid ?? ($reconciliationDraft['user_uuid'] ?? null),
            'user_identity' => $this->giving_identity_uuid
                ?? $this->givingIdentity?->uuid
                ?? ($reconciliationDraft['user_identity'] ?? $reconciliationDraft['giving_identity_uuid'] ?? null),
            'giving_identity_uuid' => $this->giving_identity_uuid ?? ($reconciliationDraft['giving_identity_uuid'] ?? $reconciliationDraft['user_identity'] ?? null),
            'user' => $this->resolveLinkedUser(),
            'giving_identity' => $this->resolveGivingIdentityPayload(),
            'donor_name' => $this->resolveDonorName(),
            'donor_email' => $this->donor_email
                ?? $this->donor?->email
                ?? $this->givingIdentity?->user?->email
                ?? $this->givingIdentity?->email_lower
                ?? ($reconciliationDraft['donor_email'] ?? null),
            'donor_phone' => $this->donor_phone ?? $this->donor?->phone_number ?? ($reconciliationDraft['donor_phone'] ?? null),
            'donor_type' => $donorType?->slug ?? ($reconciliationDraft['donor_type'] ?? null),
            'donor_type_uuid' => $this->donor_type_uuid ?? ($reconciliationDraft['donor_type_uuid'] ?? null),
            'firstname' => $this->donor?->firstname ?? ($reconciliationDraft['firstname'] ?? null),
            'lastname' => $this->donor?->lastname ?? ($reconciliationDraft['lastname'] ?? null),
            'set_number' => $set?->set_number ?? ($reconciliationDraft['set_number'] ?? null),
            'alumni_identifier' => $this->donor?->alumni_identifier ?? ($reconciliationDraft['alumni_identifier'] ?? null),
            'organization_name' => $this->organization_name ?? $this->donor?->organization_name ?? ($reconciliationDraft['organization_name'] ?? null),
            'corporate_category_uuid' => $this->donor?->corporate_category_uuid ?? ($reconciliationDraft['corporate_category_uuid'] ?? null),
            'rc_number' => $this->rc_number ?? $this->donor?->rc_number ?? ($reconciliationDraft['rc_number'] ?? null),
            'tin' => $this->tin ?? $this->donor?->tin ?? ($reconciliationDraft['tin'] ?? null),
            'country_code' => $this->donor?->country_code ?? ($reconciliationDraft['country_code'] ?? null),
            'country_uuid' => $reconciliationDraft['country_uuid'] ?? null,
            'is_anonymous' => (bool) $this->is_anonymous,
            'linked_campaign' => $this->campaign !== null ? [
                'campaign_id' => $this->campaign->uuid,
                'public_campaign_code' => $this->campaign->campaign_id,
                'name' => $this->campaign->name,
            ] : null,
            'donor_tier' => $tier !== null ? [
                'tier_id' => $tier->uuid,
                'name' => $tier->name,
            ] : null,
            'donor_set' => $set !== null ? [
                'graduation_set_id' => $set->uuid,
                'name' => $set->name,
                'set_number' => $set->set_number,
            ] : null,
            'donation_type' => isset($metadata['donation_type']) && $metadata['donation_type'] !== ''
                ? (string) $metadata['donation_type']
                : 'One Time Donation',
            'payment_method' => $this->resolvePaymentMethod(),
            'payment_via' => $this->gateway,
            'gateway_reference' => $this->gateway_reference,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'amount_in_naira' => $this->amount_in_naira !== null ? (string) $this->amount_in_naira : null,
            'exchange_rate_to_naira' => $this->exchange_rate_to_naira !== null ? (string) $this->exchange_rate_to_naira : null,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'pledge_uuid' => $this->pledge_uuid,
            'application_type' => $this->application_type instanceof \BackedEnum ? $this->application_type->value : $this->application_type,
            'superseded_by_transaction_uuid' => $this->superseded_by_transaction_uuid,
            'organization_name' => $this->organization_name,
            'rc_number' => $this->rc_number,
            'tin' => $this->tin,
            'receipt_number' => $this->receipt_number,
            'receipt_download_url' => $receiptLinks['donation'] ?? null,
            'tax_receipt_download_url' => $receiptLinks['tax'] ?? null,
            'bank_transfer_reference' => $this->bank_transfer_reference,
            'paid_into_account_number' => $this->paid_into_account_number,
            'paid_into' => $this->resolvePaidIntoLabel(),
            'fcmb_statement_reference' => $this->fcmb_statement_reference,
            'narration' => $this->narration ?? $metadata['narration'] ?? $metadata['bank_narration'] ?? null,
            'awaiting_bank_verification_at' => $this->awaiting_bank_verification_at,
            'reconciled_at' => $this->reconciled_at,
            'reconciled_by_admin_uuid' => $this->reconciled_by_admin_uuid,
            'reconciliation_note' => $this->reconciliation_note ?? ($reconciliationDraft['reconciliation_note'] ?? null),
            'reconciliation_status' => $this->resolveReconciliationStatus(),
            'reconciliation_draft' => $reconciliationDraft !== [] ? $reconciliationDraft : null,
            'certificates' => $this->whenLoaded('certificates', fn () => \App\Http\Resources\Customer\CustomerRecognitionResource::collection($this->certificates)),
            'metadata' => $metadata,
        ];
    }

    private function resolveReconciliationStatus(): ?string
    {
        $applicationType = $this->application_type;
        if (! ($applicationType instanceof TransactionApplicationType) || $applicationType !== TransactionApplicationType::BANK_TRANSFER) {
            $metadata = is_array($this->metadata) ? $this->metadata : [];
            if (! isset($metadata['source']) || ! in_array($metadata['source'], ['fcmb_import', 'fcmb_webhook'], true)) {
                return null;
            }
        }

        if ($this->status === TransactionStatus::SUCCESSFUL && $this->reconciled_at !== null) {
            return 'reconciled';
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];
        if (($metadata['source'] ?? null) === 'fcmb_import' || ($metadata['source'] ?? null) === 'fcmb_webhook') {
            if ($this->status === TransactionStatus::PENDING) {
                return 'unmatched';
            }
        }

        if ($this->awaiting_bank_verification_at !== null && $this->status === TransactionStatus::PENDING) {
            return 'awaiting_verification';
        }

        if ($this->status === TransactionStatus::PENDING) {
            return 'awaiting_payment';
        }

        return null;
    }

    private function resolvePaidIntoLabel(): ?string
    {
        if ($this->paid_into_account_number === null || $this->paid_into_account_number === '') {
            return null;
        }

        $registry = app(BankAccountRegistry::class);
        $account = $registry->resolveByAccountNumber($this->paid_into_account_number);

        if ($account === null) {
            return null;
        }

        return $registry->paidIntoLabel($account['account_key']).' '.$account['currency'];
    }

    /**
     * @return array{donation?: string, tax?: string}
     */
    private function resolveReceiptLinks(): array
    {
        if ($this->status !== \App\Enums\TransactionStatus::SUCCESSFUL) {
            return [];
        }

        if ($this->receipt_number === null || $this->receipt_number === '') {
            return [];
        }

        $receiptService = app(ReceiptService::class);
        $base = rtrim((string) config('app.url'), '/').'/api/v1/receipts/'.$this->receipt_number;

        $links = [
            'donation' => $base.'/download',
        ];

        if ($receiptService->isEligibleForTaxReceipt($this->resource)) {
            $links['tax'] = rtrim((string) config('app.url'), '/')
                .'/api/v1/public/receipts/'.$this->receipt_number.'/tax/download';
        }

        return $links;
    }

    /**
     * @return array{user_uuid: string, donor_name: string|null}|null
     */
    private function resolveLinkedUser(): ?array
    {
        $userUuid = $this->user_uuid
            ?? $this->givingIdentity?->user_uuid
            ?? $this->donor?->uuid;

        if ($userUuid === null || $userUuid === '') {
            return null;
        }

        return [
            'user_uuid' => $userUuid,
            'donor_name' => $this->resolveDonorName(),
        ];
    }

    /**
     * @return array{uuid: string, name: string|null, donor_name: string|null}|null
     */
    private function resolveGivingIdentityPayload(): ?array
    {
        $identity = $this->givingIdentity;
        if (! $identity instanceof GivingIdentity) {
            return null;
        }

        $donorName = $this->donorNameFromGivingIdentity($identity);

        return [
            'uuid' => $identity->uuid,
            'name' => $donorName,
            'donor_name' => $donorName,
        ];
    }

    private function donorNameFromGivingIdentity(GivingIdentity $identity): ?string
    {
        if (filled($identity->organization_name)) {
            return trim((string) $identity->organization_name);
        }

        $name = trim(trim((string) ($identity->firstname ?? '')).' '.trim((string) ($identity->lastname ?? '')));

        return $name !== '' ? $name : null;
    }

    private function resolveDonorName(): ?string
    {
        if ((bool) $this->is_anonymous) {
            return 'Anonymous';
        }

        if ($this->donor !== null) {
            $name = trim(implode(' ', array_filter([
                (string) ($this->donor->firstname ?? ''),
                (string) ($this->donor->lastname ?? ''),
            ])));
            if ($name !== '') {
                return $name;
            }
        }

        if ($this->givingIdentity instanceof GivingIdentity) {
            $identityName = $this->donorNameFromGivingIdentity($this->givingIdentity);
            if ($identityName !== null) {
                return $identityName;
            }
        }

        return $this->donor_name ?: $this->resolveDonorNameFromDraft();
    }

    /**
     * @return array<string, mixed>
     */
    private function reconciliationDraft(): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        return is_array($metadata['reconciliation_draft'] ?? null)
            ? $metadata['reconciliation_draft']
            : [];
    }

    private function resolveDonorNameFromDraft(): ?string
    {
        $draft = $this->reconciliationDraft();
        $organizationName = trim((string) ($draft['organization_name'] ?? ''));
        if ($organizationName !== '') {
            return $organizationName;
        }

        $name = trim(trim((string) ($draft['firstname'] ?? '')).' '.trim((string) ($draft['lastname'] ?? '')));

        return $name !== '' ? $name : null;
    }
}
