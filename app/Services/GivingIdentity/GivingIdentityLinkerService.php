<?php

namespace App\Services\GivingIdentity;

use App\Jobs\EvaluateDonorTierRecognitionJob;
use App\Models\DonorRecognition;
use App\Models\GivingIdentity;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Links guest giving history to a registered user using giving identities (not email alone).
 */
final class GivingIdentityLinkerService
{
    public function __construct(
        private readonly GivingIdentityResolver $identityResolver,
    ) {}

    /**
     * @return array{transactions: int, pledges: int, pledge_transactions: int, recognitions: int}
     */
    public function linkForUser(User $user): array
    {
        $email = GivingIdentityNormalizer::email($user->email);
        if ($email === null) {
            return [
                'transactions' => 0,
                'pledges' => 0,
                'pledge_transactions' => 0,
                'recognitions' => 0,
            ];
        }

        try {
            $identity = $this->identityResolver->linkRegistrationToIdentity($user);
        } catch (\Throwable $e) {
            Log::warning('Skipping guest history link due to giving identity conflict.', [
                'user_uuid' => $user->uuid,
                'email' => $email,
                'message' => $e->getMessage(),
            ]);

            return [
                'transactions' => 0,
                'pledges' => 0,
                'pledge_transactions' => 0,
                'recognitions' => 0,
            ];
        }

        $this->stampLegacyRecordsForEmail($identity, $email);

        return DB::transaction(function () use ($user, $identity, $email): array {
            $pledgeUuids = Pledge::query()
                ->whereNull('user_uuid')
                ->where('giving_identity_uuid', $identity->uuid)
                ->pluck('uuid')
                ->all();

            $pledgesLinked = 0;
            if ($pledgeUuids !== []) {
                $pledgesLinked = Pledge::query()
                    ->whereIn('uuid', $pledgeUuids)
                    ->whereNull('user_uuid')
                    ->update(['user_uuid' => $user->uuid]);
            }

            $transactionsLinked = Transaction::query()
                ->whereNull('user_uuid')
                ->where('giving_identity_uuid', $identity->uuid)
                ->update(['user_uuid' => $user->uuid]);

            $pledgeTransactionsLinked = 0;
            if ($pledgeUuids !== []) {
                $pledgeTransactionsLinked = Transaction::query()
                    ->whereNull('user_uuid')
                    ->whereIn('pledge_uuid', $pledgeUuids)
                    ->update(['user_uuid' => $user->uuid]);
            }

            $recognitionsLinked = $this->linkRecognitionsForUser($user, $identity, $email);

            if ($transactionsLinked > 0) {
                $latestTransaction = Transaction::query()
                    ->countableTowardRevenue()
                    ->where('user_uuid', $user->uuid)
                    ->orderByDesc('paid_at')
                    ->first();

                if ($latestTransaction !== null) {
                    EvaluateDonorTierRecognitionJob::dispatch($latestTransaction->uuid);
                }
            }

            return [
                'transactions' => $transactionsLinked,
                'pledges' => $pledgesLinked,
                'pledge_transactions' => $pledgeTransactionsLinked,
                'recognitions' => $recognitionsLinked,
            ];
        });
    }

    private function stampLegacyRecordsForEmail(GivingIdentity $identity, string $emailLower): void
    {
        $profile = GivingIdentityProfileBuilder::fromIdentity($identity);

        Transaction::query()
            ->whereNull('giving_identity_uuid')
            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
            ->orderBy('id')
            ->chunkById(100, function ($transactions) use ($identity, $profile): void {
                foreach ($transactions as $transaction) {
                    $txProfile = $this->profileFromTransactionMetadata($transaction);
                    if ($txProfile !== null && $profile->hardFieldsMatch($txProfile)) {
                        $transaction->forceFill(['giving_identity_uuid' => $identity->uuid])->save();
                    }
                }
            });

        Pledge::query()
            ->whereNull('giving_identity_uuid')
            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
            ->orderBy('id')
            ->chunkById(100, function ($pledges) use ($identity, $profile): void {
                foreach ($pledges as $pledge) {
                    $pledgeProfile = GivingIdentityProfileBuilder::fromPledge($pledge);
                    if ($profile->hardFieldsMatch($pledgeProfile)) {
                        $pledge->forceFill(['giving_identity_uuid' => $identity->uuid])->save();
                    }
                }
            });
    }

    private function profileFromTransactionMetadata(Transaction $transaction): ?GivingIdentityProfile
    {
        $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
        $guestProfile = is_array($metadata['guest_donor_profile'] ?? null) ? $metadata['guest_donor_profile'] : [];

        if ($guestProfile === [] && $transaction->donor_type_uuid === null) {
            return null;
        }

        return GivingIdentityProfileBuilder::fromGuestPayload([
            'donor_type_uuid' => $transaction->donor_type_uuid,
            'organization_name' => $transaction->organization_name,
            'rc_number' => $transaction->rc_number,
            'tin' => $transaction->tin,
            'graduation_set_uuid' => $guestProfile['graduation_set_uuid'] ?? null,
            'corporate_category_uuid' => $guestProfile['corporate_category_uuid'] ?? null,
            'firstname' => $guestProfile['firstname'] ?? null,
            'lastname' => $guestProfile['lastname'] ?? null,
            'alumni_identifier' => $guestProfile['alumni_identifier'] ?? null,
        ], [
            'donor_type_uuid' => $transaction->donor_type_uuid,
            'guest_donor_profile' => $guestProfile,
        ]);
    }

    private function linkRecognitionsForUser(User $user, GivingIdentity $identity, string $email): int
    {
        $recognitions = DonorRecognition::query()
            ->whereNull('user_uuid')
            ->where(function ($query) use ($identity, $email): void {
                $query->where('donor_key', $identity->uuid)
                    ->orWhere('donor_key', $email)
                    ->orWhereRaw('LOWER(TRIM(donor_email)) = ?', [$email]);
            })
            ->get();

        $linked = 0;

        foreach ($recognitions as $recognition) {
            $duplicate = DonorRecognition::query()
                ->where('donor_key', $user->uuid)
                ->where('tier_uuid', $recognition->tier_uuid)
                ->where('id', '!=', $recognition->id)
                ->exists();

            if ($duplicate) {
                $recognition->delete();

                continue;
            }

            $recognition->forceFill([
                'user_uuid' => $user->uuid,
                'donor_key' => $user->uuid,
            ])->save();

            $linked++;
        }

        return $linked;
    }
}
