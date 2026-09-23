<?php

namespace App\Services\GivingIdentity;

use App\Enums\DonorTypeSlug;
use App\Enums\House;
use App\Models\GivingIdentity;

/**
 * Builds transaction/pledge guest_donor_profile metadata from a canonical giving identity.
 */
final class GivingIdentityGuestProfileSnapshot
{
    /**
     * @return array<string, mixed>
     */
    public static function fromIdentity(GivingIdentity $identity): array
    {
        $identity->loadMissing(['donorType', 'graduationSet', 'corporateCategory', 'affiliatedGraduationSet']);

        $slug = $identity->donorType?->slug;
        $profile = array_filter([
            'donor_type_slug' => $slug,
            'donor_type_label' => $identity->donorType?->label,
        ], fn ($value) => $value !== null && $value !== '');

        if ($slug === DonorTypeSlug::ICOBA_ALUMNI->value) {
            $profile['firstname'] = GivingIdentityNormalizer::text($identity->firstname);
            $profile['lastname'] = GivingIdentityNormalizer::text($identity->lastname);
            $profile['alumni_identifier'] = filled($identity->alumni_identifier)
                ? (string) $identity->alumni_identifier
                : null;

            if ($identity->graduationSet !== null) {
                $profile['graduation_set_uuid'] = $identity->graduation_set_uuid;
                $profile['graduation_set_name'] = $identity->graduationSet->name;
                $profile['set_number'] = $identity->graduationSet->set_number;
            }
        } elseif ($slug === DonorTypeSlug::CORPORATE_DONOR->value) {
            $profile['organization_name'] = GivingIdentityNormalizer::text($identity->organization_name);
            $profile['rc_number'] = GivingIdentityNormalizer::text($identity->rc_number);
            $profile['tin'] = GivingIdentityNormalizer::text($identity->tin);
            $profile['corporate_category_uuid'] = $identity->corporate_category_uuid;

            if ($identity->corporateCategory !== null) {
                $profile['corporate_category_name'] = $identity->corporateCategory->name;
            }
        } elseif (in_array($slug, [DonorTypeSlug::FRIENDS_OF_ICOBA->value, DonorTypeSlug::RELATIVES_OF_ICOBA->value, DonorTypeSlug::WIVES_OF_ICOBA->value], true)) {
            $profile['firstname'] = GivingIdentityNormalizer::text($identity->firstname);
            $profile['lastname'] = GivingIdentityNormalizer::text($identity->lastname);
        }

        if (filled($identity->house) && ($house = House::tryFrom((string) $identity->house)) !== null) {
            $profile['house'] = $house->value;
            $profile['house_label'] = $house->label();
        }

        if ($identity->affiliated_graduation_set_uuid !== null) {
            $profile['affiliated_graduation_set_uuid'] = $identity->affiliated_graduation_set_uuid;
            if ($identity->affiliatedGraduationSet !== null) {
                $profile['affiliated_set_number'] = $identity->affiliatedGraduationSet->set_number;
                $profile['affiliated_graduation_set_name'] = $identity->affiliatedGraduationSet->name;
            }
        }

        if ($identity->is_igbobian_owned) {
            $profile['is_igbobian_owned'] = true;
        }

        return array_filter($profile, fn ($value) => $value !== null && $value !== '');
    }
}
