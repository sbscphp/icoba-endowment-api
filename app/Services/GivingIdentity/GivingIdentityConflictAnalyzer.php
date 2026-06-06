<?php

namespace App\Services\GivingIdentity;

use App\Models\GivingIdentity;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Collection;

final class GivingIdentityConflictAnalyzer
{
    /**
     * @return Collection<int, string>
     */
    public function collectDistinctEmails(?string $emailFilter = null): Collection
    {
        if ($emailFilter !== null && $emailFilter !== '') {
            $normalized = GivingIdentityNormalizer::email($emailFilter);

            return $normalized !== null ? collect([$normalized]) : collect();
        }

        $fromTransactions = Transaction::query()
            ->whereNotNull('donor_email')
            ->where('donor_email', '!=', '')
            ->selectRaw('LOWER(TRIM(donor_email)) as email_lower')
            ->distinct()
            ->pluck('email_lower');

        $fromPledges = Pledge::query()
            ->whereNotNull('donor_email')
            ->where('donor_email', '!=', '')
            ->selectRaw('LOWER(TRIM(donor_email)) as email_lower')
            ->distinct()
            ->pluck('email_lower');

        $fromIdentities = GivingIdentity::query()
            ->where('status', 'conflict')
            ->whereNotNull('email_lower')
            ->pluck('email_lower');

        return $fromTransactions
            ->merge($fromPledges)
            ->merge($fromIdentities)
            ->filter()
            ->unique()
            ->sort()
            ->values();
    }

    /**
     * @return Collection<int, GivingIdentityProfile>
     */
    public function observedProfilesForEmail(string $emailLower): Collection
    {
        $profiles = collect();

        Transaction::query()
            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
            ->orderBy('id')
            ->chunkById(200, function ($transactions) use (&$profiles): void {
                foreach ($transactions as $transaction) {
                    $profile = $this->profileFromTransaction($transaction);
                    if ($profile !== null) {
                        $profiles->push($profile);
                    }
                }
            });

        Pledge::query()
            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
            ->orderBy('id')
            ->chunkById(200, function ($pledges) use (&$profiles): void {
                foreach ($pledges as $pledge) {
                    $profiles->push(GivingIdentityProfileBuilder::fromPledge($pledge));
                }
            });

        return $this->uniqueProfiles($profiles);
    }

    public function identityForEmail(string $emailLower): ?GivingIdentity
    {
        return GivingIdentity::query()
            ->with(['donorType', 'graduationSet', 'corporateCategory', 'user'])
            ->where('email_lower', $emailLower)
            ->first();
    }

    public function userForEmail(string $emailLower): ?User
    {
        return User::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$emailLower])
            ->first();
    }

    /**
     * @param  Collection<int, GivingIdentityProfile>  $observedProfiles
     * @return Collection<int, GivingIdentityProfile>
     */
    public function conflictingProfiles(GivingIdentityProfile $canonical, Collection $observedProfiles): Collection
    {
        return $observedProfiles
            ->filter(fn (GivingIdentityProfile $profile): bool => ! $canonical->hardFieldsMatch($profile))
            ->values();
    }

    /**
     * @param  Collection<int, GivingIdentityProfile>  $observedProfiles
     */
    public function hasConflict(GivingIdentityProfile $canonical, Collection $observedProfiles): bool
    {
        return $this->conflictingProfiles($canonical, $observedProfiles)->isNotEmpty();
    }

    public function canonicalProfileForEmail(string $emailLower, ?GivingIdentity $identity = null): ?GivingIdentityProfile
    {
        $identity ??= $this->identityForEmail($emailLower);

        if ($identity !== null) {
            return GivingIdentityProfileBuilder::fromIdentity($identity);
        }

        $user = $this->userForEmail($emailLower);
        if ($user !== null) {
            return GivingIdentityProfileBuilder::fromUser($user);
        }

        $observed = $this->observedProfilesForEmail($emailLower);

        return $observed->first();
    }

    /**
     * @return array{
     *     email: string,
     *     identity_uuid: ?string,
     *     identity_status: ?string,
     *     user_uuid: ?string,
     *     canonical: ?GivingIdentityProfile,
     *     observed_profiles: Collection<int, GivingIdentityProfile>,
     *     conflicting_profiles: Collection<int, GivingIdentityProfile>,
     *     has_conflict: bool,
     *     transaction_count: int,
     *     pledge_count: int,
     * }
     */
    public function analyzeEmail(string $emailLower): array
    {
        $identity = $this->identityForEmail($emailLower);
        $user = $this->userForEmail($emailLower);
        $observedProfiles = $this->observedProfilesForEmail($emailLower);
        $canonical = $this->canonicalProfileForEmail($emailLower, $identity);

        $conflictingProfiles = $canonical !== null
            ? $this->conflictingProfiles($canonical, $observedProfiles)
            : collect();

        return [
            'email' => $emailLower,
            'identity_uuid' => $identity?->uuid,
            'identity_status' => $identity?->status?->value,
            'user_uuid' => $identity?->user_uuid ?? $user?->uuid,
            'canonical' => $canonical,
            'observed_profiles' => $observedProfiles,
            'conflicting_profiles' => $conflictingProfiles,
            'has_conflict' => $conflictingProfiles->isNotEmpty(),
            'transaction_count' => Transaction::query()
                ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
                ->count(),
            'pledge_count' => Pledge::query()
                ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$emailLower])
                ->count(),
        ];
    }

    public function formatProfileSummary(GivingIdentityProfile $profile): string
    {
        $parts = [$profile->donorTypeLabel().': '.$profile->displayLabel()];

        if ($profile->donorTypeSlug === \App\Enums\DonorTypeSlug::ICOBA_ALUMNI->value && $profile->graduationSetUuid !== null) {
            $setNumber = \App\Models\GraduationSet::query()
                ->where('uuid', $profile->graduationSetUuid)
                ->value('set_number');
            if ($setNumber !== null) {
                $parts[] = 'Set '.$setNumber;
            }
        }

        if ($profile->donorTypeSlug === \App\Enums\DonorTypeSlug::CORPORATE_DONOR->value && $profile->corporateCategoryUuid !== null) {
            $category = \App\Models\CorporateCategory::query()
                ->where('uuid', $profile->corporateCategoryUuid)
                ->value('name');
            if (is_string($category) && $category !== '') {
                $parts[] = 'Category '.$category;
            }
        }

        return implode(' | ', $parts);
    }

    /**
     * @param  Collection<int, GivingIdentityProfile>  $profiles
     * @return Collection<int, GivingIdentityProfile>
     */
    private function uniqueProfiles(Collection $profiles): Collection
    {
        return $profiles
            ->unique(fn (GivingIdentityProfile $profile): string => json_encode([
                $profile->donorTypeUuid,
                $profile->graduationSetUuid,
                $profile->corporateCategoryUuid,
                GivingIdentityNormalizer::text($profile->organizationName),
                GivingIdentityNormalizer::text($profile->firstname),
                GivingIdentityNormalizer::text($profile->lastname),
            ]))
            ->values();
    }

    private function profileFromTransaction(Transaction $transaction): ?GivingIdentityProfile
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
        ], [
            'donor_type_uuid' => $transaction->donor_type_uuid,
            'guest_donor_profile' => $guestProfile,
        ]);
    }
}
