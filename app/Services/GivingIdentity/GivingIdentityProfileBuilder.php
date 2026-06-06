<?php

namespace App\Services\GivingIdentity;

use App\Enums\DonorTypeSlug;
use App\Models\DonorType;
use App\Models\GivingIdentity;
use App\Models\GraduationSet;
use App\Models\Pledge;
use App\Models\User;

final class GivingIdentityProfileBuilder
{
    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>|null  $guestSnapshot
     */
    public static function fromGuestPayload(array $data, ?array $guestSnapshot = null): GivingIdentityProfile
    {
        $guestProfile = is_array($guestSnapshot['guest_donor_profile'] ?? null)
            ? $guestSnapshot['guest_donor_profile']
            : [];

        $donorTypeUuid = $guestSnapshot['donor_type_uuid']
            ?? ($data['donor_type_uuid'] ?? null);

        $slug = $guestProfile['donor_type_slug']
            ?? self::resolveSlugFromPayload($data);

        if ($slug === null && is_string($donorTypeUuid) && $donorTypeUuid !== '') {
            $slug = DonorType::query()->where('uuid', $donorTypeUuid)->value('slug');
        }

        $graduationSetUuid = $guestProfile['graduation_set_uuid']
            ?? ($data['graduation_set_uuid'] ?? null);

        if ($graduationSetUuid === null && filled($data['set_number'] ?? null)) {
            $graduationSetUuid = GraduationSet::query()
                ->where('set_number', (string) $data['set_number'])
                ->value('uuid');
        }

        return new GivingIdentityProfile(
            donorTypeUuid: is_string($donorTypeUuid) ? $donorTypeUuid : null,
            donorTypeSlug: is_string($slug) ? $slug : null,
            graduationSetUuid: is_string($graduationSetUuid) ? $graduationSetUuid : null,
            corporateCategoryUuid: is_string($guestProfile['corporate_category_uuid'] ?? $data['corporate_category_uuid'] ?? null)
                ? (string) ($guestProfile['corporate_category_uuid'] ?? $data['corporate_category_uuid'])
                : null,
            organizationName: GivingIdentityNormalizer::text($guestSnapshot['organization_name'] ?? $data['organization_name'] ?? null),
            rcNumber: GivingIdentityNormalizer::text($guestSnapshot['rc_number'] ?? $data['rc_number'] ?? null),
            tin: GivingIdentityNormalizer::text($guestSnapshot['tin'] ?? $data['tin'] ?? null),
            firstname: GivingIdentityNormalizer::text($guestProfile['firstname'] ?? $data['firstname'] ?? null),
            lastname: GivingIdentityNormalizer::text($guestProfile['lastname'] ?? $data['lastname'] ?? null),
            alumniIdentifier: filled($guestProfile['alumni_identifier'] ?? $data['alumni_identifier'] ?? null)
                ? (string) ($guestProfile['alumni_identifier'] ?? $data['alumni_identifier'])
                : null,
        );
    }

    public static function fromUser(User $user): GivingIdentityProfile
    {
        $user->loadMissing('donorType');

        return new GivingIdentityProfile(
            donorTypeUuid: $user->donor_type_uuid,
            donorTypeSlug: $user->donorType?->slug,
            graduationSetUuid: $user->graduation_set_uuid,
            corporateCategoryUuid: $user->corporate_category_uuid,
            organizationName: GivingIdentityNormalizer::text($user->organization_name),
            rcNumber: GivingIdentityNormalizer::text($user->rc_number),
            tin: GivingIdentityNormalizer::text($user->tin),
            firstname: GivingIdentityNormalizer::text($user->firstname),
            lastname: GivingIdentityNormalizer::text($user->lastname),
            alumniIdentifier: filled($user->alumni_identifier) ? (string) $user->alumni_identifier : null,
        );
    }

    public static function fromPledge(Pledge $pledge): GivingIdentityProfile
    {
        $pledge->loadMissing('donorType');

        $metadata = is_array($pledge->metadata) ? $pledge->metadata : [];
        $guestProfile = is_array($metadata['guest_donor_profile'] ?? null) ? $metadata['guest_donor_profile'] : [];

        $firstname = null;
        $lastname = null;

        if ($pledge->donor_name !== null && trim((string) $pledge->donor_name) !== '') {
            $parts = preg_split('/\s+/u', trim((string) $pledge->donor_name), 2) ?: [];
            $firstname = $parts[0] ?? null;
            $lastname = $parts[1] ?? null;
        }

        return new GivingIdentityProfile(
            donorTypeUuid: $pledge->donor_type_uuid,
            donorTypeSlug: $pledge->donorType?->slug ?? ($guestProfile['donor_type_slug'] ?? null),
            graduationSetUuid: $pledge->graduation_set_uuid ?? ($guestProfile['graduation_set_uuid'] ?? null),
            corporateCategoryUuid: is_string($guestProfile['corporate_category_uuid'] ?? null)
                ? $guestProfile['corporate_category_uuid']
                : null,
            organizationName: GivingIdentityNormalizer::text($guestProfile['organization_name'] ?? null),
            rcNumber: null,
            tin: null,
            firstname: GivingIdentityNormalizer::text($guestProfile['firstname'] ?? $firstname),
            lastname: GivingIdentityNormalizer::text($guestProfile['lastname'] ?? $lastname),
            alumniIdentifier: filled($guestProfile['alumni_identifier'] ?? null)
                ? (string) $guestProfile['alumni_identifier']
                : null,
        );
    }

    public static function fromIdentity(GivingIdentity $identity): GivingIdentityProfile
    {
        $identity->loadMissing('donorType');

        return new GivingIdentityProfile(
            donorTypeUuid: $identity->donor_type_uuid,
            donorTypeSlug: $identity->donorType?->slug,
            graduationSetUuid: $identity->graduation_set_uuid,
            corporateCategoryUuid: $identity->corporate_category_uuid,
            organizationName: $identity->organization_name,
            rcNumber: $identity->rc_number,
            tin: $identity->tin,
            firstname: $identity->firstname,
            lastname: $identity->lastname,
            alumniIdentifier: $identity->alumni_identifier,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function resolveSlugFromPayload(array $data): ?string
    {
        if (isset($data['donor_type']) && is_string($data['donor_type']) && trim($data['donor_type']) !== '') {
            return strtolower(trim($data['donor_type']));
        }

        return null;
    }
}
