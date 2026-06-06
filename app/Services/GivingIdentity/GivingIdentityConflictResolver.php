<?php

namespace App\Services\GivingIdentity;

use App\Enums\GivingIdentityStatus;
use App\Models\GivingIdentity;
use App\Models\Pledge;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

final class GivingIdentityConflictResolver
{
    public function __construct(
        private readonly GivingIdentityConflictAnalyzer $analyzer,
    ) {}

    /**
     * Align all transactions and pledges for an email to the existing giving identity profile.
     *
     * @return array{transactions: int, pledges: int, identity_updated: bool}
     */
    public function resolveForEmail(string $emailLower, bool $dryRun = false): array
    {
        $emailLower = GivingIdentityNormalizer::email($emailLower) ?? $emailLower;

        $identity = $this->analyzer->identityForEmail($emailLower);
        if ($identity === null) {
            throw new \InvalidArgumentException("No giving identity exists for email [{$emailLower}]. Run giving-identities:reconcile first.");
        }

        return $this->resolveForIdentity($identity, $dryRun);
    }

    /**
     * @return array{transactions: int, pledges: int, identity_updated: bool}
     */
    public function resolveForIdentity(GivingIdentity $identity, bool $dryRun = false): array
    {
        if ($identity->email_lower === null || $identity->email_lower === '') {
            throw new \InvalidArgumentException('Giving identity must have an email_lower value to resolve conflicts.');
        }

        $emailLower = $identity->email_lower;
        $identity->loadMissing(['donorType', 'graduationSet', 'corporateCategory']);

        $canonical = GivingIdentityProfileBuilder::fromIdentity($identity);
        $guestProfile = GivingIdentityGuestProfileSnapshot::fromIdentity($identity);
        $donorName = $canonical->displayLabel();

        if ($dryRun) {
            return [
                'transactions' => Transaction::query()
                    ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
                    ->count(),
                'pledges' => Pledge::query()
                    ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
                    ->count(),
                'identity_updated' => $identity->status === GivingIdentityStatus::CONFLICT,
            ];
        }

        return DB::transaction(function () use ($identity, $emailLower, $guestProfile, $donorName): array {
            $transactionCount = 0;
            $pledgeCount = 0;

            Transaction::query()
                ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
                ->orderBy('id')
                ->chunkById(100, function ($transactions) use ($identity, $guestProfile, $donorName, &$transactionCount): void {
                    foreach ($transactions as $transaction) {
                        $this->syncTransaction($transaction, $identity, $guestProfile, $donorName);
                        $transactionCount++;
                    }
                });

            Pledge::query()
                ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
                ->orderBy('id')
                ->chunkById(100, function ($pledges) use ($identity, $guestProfile, $donorName, &$pledgeCount): void {
                    foreach ($pledges as $pledge) {
                        $this->syncPledge($pledge, $identity, $guestProfile, $donorName);
                        $pledgeCount++;
                    }
                });

            $identityUpdated = false;
            if ($identity->status === GivingIdentityStatus::CONFLICT) {
                $identity->forceFill(['status' => GivingIdentityStatus::ACTIVE])->save();
                $identityUpdated = true;
            }

            return [
                'transactions' => $transactionCount,
                'pledges' => $pledgeCount,
                'identity_updated' => $identityUpdated,
            ];
        });
    }

    private function syncTransaction(
        Transaction $transaction,
        GivingIdentity $identity,
        array $guestProfile,
        string $donorName,
    ): void {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $metadata['guest_donor_profile'] = $guestProfile;
        $metadata['giving_identity_override_at'] = now()->toIso8601String();

        $transaction->forceFill([
            'giving_identity_uuid' => $identity->uuid,
            'donor_type_uuid' => $identity->donor_type_uuid,
            'organization_name' => $identity->organization_name,
            'rc_number' => $identity->rc_number,
            'tin' => $identity->tin,
            'donor_name' => $donorName !== 'Donor' ? $donorName : $transaction->donor_name,
            'metadata' => $metadata,
        ])->save();
    }

    private function syncPledge(
        Pledge $pledge,
        GivingIdentity $identity,
        array $guestProfile,
        string $donorName,
    ): void {
        $metadata = is_array($pledge->metadata) ? $pledge->metadata : [];
        $metadata['guest_donor_profile'] = $guestProfile;
        $metadata['giving_identity_override_at'] = now()->toIso8601String();

        $pledge->forceFill([
            'giving_identity_uuid' => $identity->uuid,
            'donor_type_uuid' => $identity->donor_type_uuid,
            'graduation_set_uuid' => $identity->graduation_set_uuid,
            'donor_name' => $donorName !== 'Donor' ? $donorName : $pledge->donor_name,
            'metadata' => $metadata,
        ])->save();
    }
}
