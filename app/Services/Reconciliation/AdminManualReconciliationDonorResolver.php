<?php

namespace App\Services\Reconciliation;

use App\Models\GivingIdentity;
use App\Models\Transaction;
use App\Models\User;

final class AdminManualReconciliationDonorResolver
{
    /**
     * @param  array<string, mixed>  $draft
     */
    public function isAnonymousDonation(Transaction $transaction, array $draft = []): bool
    {
        return (bool) $transaction->is_anonymous
            || (bool) ($draft['is_anonymous'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array<string, mixed>  $donorUpdates
     */
    public function canTraceDonor(Transaction $transaction, array $draft, array $donorUpdates = []): bool
    {
        if ($this->isAnonymousDonation($transaction, $draft)) {
            return true;
        }

        $userUuid = $this->firstFilled(
            $transaction->user_uuid,
            $donorUpdates['user_uuid'] ?? null,
            $draft['user_uuid'] ?? null,
        );
        if ($userUuid !== null && User::query()->where('uuid', $userUuid)->exists()) {
            return true;
        }

        $identityUuid = $this->firstFilled(
            $transaction->giving_identity_uuid,
            $donorUpdates['giving_identity_uuid'] ?? null,
            $draft['user_identity'] ?? null,
            $draft['giving_identity_uuid'] ?? null,
        );
        if ($identityUuid !== null && GivingIdentity::query()->where('uuid', $identityUuid)->exists()) {
            return true;
        }

        $email = $this->firstFilled(
            $transaction->donor_email,
            $donorUpdates['donor_email'] ?? null,
            isset($draft['donor_email']) ? strtolower(trim((string) $draft['donor_email'])) : null,
        );
        $name = $this->firstFilled(
            $transaction->donor_name,
            $donorUpdates['donor_name'] ?? null,
            $this->donorNameFromDraft($draft),
        );

        return $email !== null && $name !== null;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array{
     *     donor_email: ?string,
     *     donor_phone: ?string,
     *     donor_name: ?string,
     *     donor_type_uuid: ?string,
     * }  $profileSnapshot
     */
    public function canTraceDonorFromPayload(array $payload, array $profileSnapshot): bool
    {
        if ((bool) ($payload['is_anonymous'] ?? false)) {
            return true;
        }

        $userUuid = isset($payload['user_uuid']) ? trim((string) $payload['user_uuid']) : '';
        if ($userUuid !== '' && User::query()->where('uuid', $userUuid)->exists()) {
            return true;
        }

        $identityUuid = isset($payload['giving_identity_uuid']) ? trim((string) $payload['giving_identity_uuid']) : '';
        if ($identityUuid !== '' && GivingIdentity::query()->where('uuid', $identityUuid)->exists()) {
            return true;
        }

        return filled($profileSnapshot['donor_email'] ?? null)
            && filled($profileSnapshot['donor_name'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function resolveDonorUpdates(Transaction $transaction, array $draft): array
    {
        if ($draft === []) {
            return [];
        }

        $resolved = [];

        $identityUuid = trim((string) ($draft['user_identity'] ?? $draft['giving_identity_uuid'] ?? ''));
        $userUuid = trim((string) ($draft['user_uuid'] ?? ''));
        $user = $userUuid !== ''
            ? User::query()->where('uuid', $userUuid)->first()
            : null;
        $identity = $identityUuid !== ''
            ? GivingIdentity::query()->with('user')->where('uuid', $identityUuid)->first()
            : null;

        if ($user !== null) {
            $resolved = [
                'user_uuid' => $user->uuid,
                'donor_email' => $user->email,
                'donor_phone' => $user->phone_number,
                'donor_name' => trim(($user->firstname ?? '').' '.($user->lastname ?? '')) ?: null,
                'donor_type_uuid' => $user->donor_type_uuid,
            ];
        } elseif ($identity !== null) {
            $resolved = [
                'giving_identity_uuid' => $identity->uuid,
                'user_uuid' => $identity->user_uuid,
                'donor_email' => $identity->user?->email ?? $identity->email_lower,
                'donor_phone' => $identity->user?->phone_number,
                'donor_name' => filled($identity->organization_name)
                    ? trim((string) $identity->organization_name)
                    : (trim(trim((string) ($identity->firstname ?? '')).' '.trim((string) ($identity->lastname ?? ''))) ?: null),
                'donor_type_uuid' => $identity->donor_type_uuid,
            ];
        } else {
            $resolved = [
                'donor_email' => isset($draft['donor_email']) ? strtolower(trim((string) $draft['donor_email'])) : null,
                'donor_phone' => isset($draft['donor_phone']) ? trim((string) $draft['donor_phone']) : null,
                'donor_name' => $this->donorNameFromDraft($draft),
            ];
        }

        if (filled($draft['campaign_uuid'] ?? null)) {
            $resolved['campaign_uuid'] = (string) $draft['campaign_uuid'];
        }
        if (filled($draft['pledge_uuid'] ?? null)) {
            $resolved['pledge_uuid'] = (string) $draft['pledge_uuid'];
        }

        $updates = [];
        foreach ($resolved as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $current = $transaction->{$field};
            if ($current === null || $current === '') {
                $updates[$field] = $value;
            }
        }

        return $updates;
    }

    public function isMissingDonorLinkage(Transaction $transaction): bool
    {
        if ((bool) $transaction->is_anonymous) {
            return false;
        }

        return blank($transaction->user_uuid)
            && blank($transaction->giving_identity_uuid)
            && (blank($transaction->donor_email) || blank($transaction->donor_name));
    }

    /**
     * @param  array<string, mixed>  $draft
     */
    private function donorNameFromDraft(array $draft): ?string
    {
        $organizationName = trim((string) ($draft['organization_name'] ?? ''));
        if ($organizationName !== '') {
            return $organizationName;
        }

        $personName = trim(trim((string) ($draft['firstname'] ?? '')).' '.trim((string) ($draft['lastname'] ?? '')));

        return $personName !== '' ? $personName : null;
    }

    private function firstFilled(mixed ...$values): ?string
    {
        foreach ($values as $value) {
            if ($value === null || $value === '') {
                continue;
            }

            $normalized = is_string($value) ? trim($value) : (string) $value;
            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }
}
