<?php

namespace App\Http\Resources\Admin;

use App\Enums\TransactionStatus;
use App\Models\GivingIdentity;
use App\Models\Transaction;
use App\Services\Bank\BankAccountRegistry;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class ReconciliationQueueResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $registry = app(BankAccountRegistry::class);
        $metadata = is_array($this->metadata) ? $this->metadata : [];

        $account = $this->paid_into_account_number !== null
            ? $registry->resolveByAccountNumber($this->paid_into_account_number)
            : null;

        return [
            'transaction_uuid' => $this->uuid,
            'transaction_id' => $this->transaction_id,
            'bank_transfer_reference' => $this->bank_transfer_reference,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'amount_in_naira' => $this->amount_in_naira !== null ? (string) $this->amount_in_naira : null,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'reconciliation_status' => $this->resolveReconciliationStatus(),
            'application_type' => $this->application_type instanceof \BackedEnum ? $this->application_type->value : $this->application_type,
            'user_uuid' => $this->user_uuid ?? $this->givingIdentity?->user_uuid,
            'user_identity' => $this->giving_identity_uuid ?? $this->givingIdentity?->uuid,
            'giving_identity_uuid' => $this->giving_identity_uuid,
            'user' => $this->resolveLinkedUser(),
            'giving_identity' => $this->resolveGivingIdentityPayload(),
            'donor_name' => $this->resolveDonorName(),
            'donor_email' => $this->donor_email ?? $this->donor?->email ?? $this->givingIdentity?->user?->email ?? $this->givingIdentity?->email_lower,
            'campaign' => $this->campaign !== null ? [
                'campaign_uuid' => $this->campaign->uuid,
                'name' => $this->campaign->name,
            ] : null,
            'pledge' => $this->pledge !== null ? [
                'pledge_uuid' => $this->pledge->uuid,
                'amount' => (string) $this->pledge->amount,
                'currency' => $this->pledge->currency,
            ] : null,
            'paid_into_account_number' => $this->paid_into_account_number,
            'paid_into_account_key' => $account['account_key'] ?? null,
            'paid_into' => $account !== null ? $registry->paidIntoLabel($account['account_key']).' '.$account['currency'] : null,
            'fcmb_statement_reference' => $this->fcmb_statement_reference,
            'awaiting_bank_verification_at' => $this->awaiting_bank_verification_at,
            'reconciled_at' => $this->reconciled_at,
            'reconciled_by_admin_uuid' => $this->reconciled_by_admin_uuid,
            'reconciled_by_admin_name' => $this->reconciledByAdmin !== null
                ? trim(($this->reconciledByAdmin->firstname ?? '').' '.($this->reconciledByAdmin->lastname ?? ''))
                : null,
            'reconciliation_note' => $this->reconciliation_note,
            'narration' => $this->narration ?? $metadata['narration'] ?? $metadata['bank_narration'] ?? null,
            'source' => $metadata['source'] ?? null,
            'created_at' => $this->created_at,
            'paid_at' => $this->paid_at,
        ];
    }

    private function resolveReconciliationStatus(): string
    {
        if ($this->status === TransactionStatus::SUCCESSFUL && $this->reconciled_at !== null) {
            return 'reconciled';
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $source = $metadata['source'] ?? null;
        if ($this->status === TransactionStatus::PENDING && in_array($source, ['fcmb_import', 'fcmb_webhook'], true) && $this->bank_transfer_reference === null) {
            return 'unmatched';
        }

        if ($this->status === TransactionStatus::PENDING && $this->awaiting_bank_verification_at !== null) {
            return 'awaiting_verification';
        }

        if ($this->status === TransactionStatus::PENDING) {
            return 'awaiting_payment';
        }

        return (string) ($this->status instanceof \BackedEnum ? $this->status->value : $this->status);
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

        return $this->donor_name;
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
}
