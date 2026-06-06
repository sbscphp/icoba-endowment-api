<?php

namespace Tests\Unit\Services\GivingIdentity;

use App\Enums\DonorTypeSlug;
use App\Services\GivingIdentity\GivingIdentityProfile;
use PHPUnit\Framework\TestCase;

class GivingIdentityProfileTest extends TestCase
{
    public function test_same_name_different_profiles_are_distinct_identities(): void
    {
        $profileA = new GivingIdentityProfile(
            donorTypeUuid: 'type-uuid',
            donorTypeSlug: DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            firstname: 'John',
            lastname: 'Smith',
        );

        $profileB = new GivingIdentityProfile(
            donorTypeUuid: 'type-uuid',
            donorTypeSlug: DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            firstname: 'John',
            lastname: 'Smith',
        );

        $this->assertTrue($profileA->hardFieldsMatch($profileB));
    }

    public function test_same_email_different_names_do_not_match(): void
    {
        $john = new GivingIdentityProfile(
            donorTypeUuid: 'type-uuid',
            donorTypeSlug: DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            firstname: 'John',
            lastname: 'Smith',
        );

        $jane = new GivingIdentityProfile(
            donorTypeUuid: 'type-uuid',
            donorTypeSlug: DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            firstname: 'Jane',
            lastname: 'Smith',
        );

        $this->assertFalse($john->hardFieldsMatch($jane));
    }

    public function test_alumni_set_mismatch_does_not_match(): void
    {
        $set1995 = new GivingIdentityProfile(
            donorTypeUuid: 'alumni-uuid',
            donorTypeSlug: DonorTypeSlug::ICOBA_ALUMNI->value,
            graduationSetUuid: 'set-1995',
            firstname: 'John',
            lastname: 'Doe',
        );

        $set2005 = new GivingIdentityProfile(
            donorTypeUuid: 'alumni-uuid',
            donorTypeSlug: DonorTypeSlug::ICOBA_ALUMNI->value,
            graduationSetUuid: 'set-2005',
            firstname: 'John',
            lastname: 'Doe',
        );

        $this->assertFalse($set1995->hardFieldsMatch($set2005));
    }

    public function test_corporate_identity_requires_organization_and_category(): void
    {
        $acme = new GivingIdentityProfile(
            donorTypeUuid: 'corp-uuid',
            donorTypeSlug: DonorTypeSlug::CORPORATE_DONOR->value,
            corporateCategoryUuid: 'cat-uuid',
            organizationName: 'Acme Corporation',
        );

        $otherOrg = new GivingIdentityProfile(
            donorTypeUuid: 'corp-uuid',
            donorTypeSlug: DonorTypeSlug::CORPORATE_DONOR->value,
            corporateCategoryUuid: 'cat-uuid',
            organizationName: 'Other Corp',
        );

        $this->assertFalse($acme->hardFieldsMatch($otherOrg));
    }
}
