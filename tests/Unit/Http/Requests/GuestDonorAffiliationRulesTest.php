<?php

namespace Tests\Unit\Http\Requests;

use App\Enums\DonorTypeSlug;
use App\Http\Requests\Customer\Donation\DonationCheckoutRequest;
use App\Models\CorporateCategory;
use App\Models\Country;
use App\Models\DonorType;
use App\Models\GraduationSet;
use App\Services\Donation\GuestDonorProfileSnapshotService;
use App\Services\GivingIdentity\GivingIdentityProfileBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

/**
 * Guest checkout, bank transfer, guest pledge and reconciliation requests all share
 * ValidatesGuestDonorProfileFields, so exercising DonationCheckoutRequest covers the trait.
 */
class GuestDonorAffiliationRulesTest extends TestCase
{
    use RefreshDatabase;

    private GraduationSet $set;

    private CorporateCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Country::query()->create(['name' => 'Nigeria', 'iso2' => 'NG', 'dial_code' => '+234', 'is_active' => true]);

        foreach (DonorTypeSlug::cases() as $slug) {
            DonorType::query()->firstOrCreate(['slug' => $slug->value], ['label' => $slug->label(), 'description' => $slug->description()]);
        }

        $this->set = GraduationSet::query()->create(['public_id' => 'SET-2002', 'name' => 'Class 2002', 'set_number' => '2002']);
        $this->category = CorporateCategory::query()->create(['name' => 'Bank']);
    }

    public function test_guest_wife_can_send_affiliated_set_and_house(): void
    {
        [$errors, $data] = $this->validate([
            'donor_type' => DonorTypeSlug::WIVES_OF_ICOBA->value,
            'firstname' => 'Bisi',
            'lastname' => 'Ola',
            'affiliated_set_number' => '2002',
            'house' => 'Townsend',
        ]);

        $this->assertArrayNotHasKey('house', $errors);
        $this->assertArrayNotHasKey('affiliated_set_number', $errors);
        $this->assertSame('townsend', $data['house']);

        $snapshot = app(GuestDonorProfileSnapshotService::class)->build($data);
        $profile = $snapshot['guest_donor_profile'];

        $this->assertSame('townsend', $profile['house']);
        $this->assertSame('Townsend', $profile['house_label']);
        $this->assertSame('2002', $profile['affiliated_set_number']);
        $this->assertSame($this->set->uuid, $profile['affiliated_graduation_set_uuid']);
        $this->assertArrayNotHasKey('is_igbobian_owned', $profile);

        $identity = GivingIdentityProfileBuilder::fromGuestPayload($data, $snapshot)->toIdentityAttributes('bisi@example.com');
        $this->assertSame('townsend', $identity['house']);
        $this->assertSame($this->set->uuid, $identity['affiliated_graduation_set_uuid']);
        $this->assertFalse($identity['is_igbobian_owned']);
    }

    public function test_guest_owned_corporate_requires_set_and_snapshots_ownership(): void
    {
        [$errors] = $this->validate($this->corporate(['is_igbobian_owned' => '1']));
        $this->assertArrayHasKey('affiliated_set_number', $errors);

        [$errors, $data] = $this->validate($this->corporate(['is_igbobian_owned' => '1', 'affiliated_set_number' => '2002']));
        $this->assertArrayNotHasKey('affiliated_set_number', $errors);

        $snapshot = app(GuestDonorProfileSnapshotService::class)->build($data);
        $this->assertTrue($snapshot['guest_donor_profile']['is_igbobian_owned']);
        $this->assertSame($this->set->uuid, $snapshot['guest_donor_profile']['affiliated_graduation_set_uuid']);

        $identity = GivingIdentityProfileBuilder::fromGuestPayload($data, $snapshot)->toIdentityAttributes('acme@example.com');
        $this->assertTrue($identity['is_igbobian_owned']);
        $this->assertSame($this->set->uuid, $identity['affiliated_graduation_set_uuid']);
    }

    public function test_guest_corporate_not_owned_drops_affiliation(): void
    {
        [, $data] = $this->validate($this->corporate(['is_igbobian_owned' => false, 'affiliated_set_number' => '2002', 'house' => 'parker']));

        $snapshot = app(GuestDonorProfileSnapshotService::class)->build($data);
        $profile = $snapshot['guest_donor_profile'];

        $this->assertArrayNotHasKey('house', $profile);
        $this->assertArrayNotHasKey('affiliated_graduation_set_uuid', $profile);
        $this->assertArrayNotHasKey('is_igbobian_owned', $profile);
    }

    public function test_guest_alumni_house_must_be_valid(): void
    {
        [$errors] = $this->validate([
            'donor_type' => DonorTypeSlug::ICOBA_ALUMNI->value,
            'firstname' => 'Ade',
            'lastname' => 'Ola',
            'set_number' => '2002',
            'house' => 'hogwarts',
        ]);

        $this->assertArrayHasKey('house', $errors);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function corporate(array $overrides): array
    {
        return array_merge([
            'donor_type' => DonorTypeSlug::CORPORATE_DONOR->value,
            'organization_name' => 'Acme Ltd',
            'corporate_category_uuid' => $this->category->uuid,
            'rc_number' => 'RC123',
            'tin' => 'TIN123',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $profile
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function validate(array $profile): array
    {
        $request = DonationCheckoutRequest::create('/api/v1/donations/checkout', 'POST', array_merge([
            'donor_email' => 'guest@example.com',
            'donor_phone' => '+2348012345678',
        ], $profile));
        $request->setContainer(app())->setRedirector(app('redirect'));

        $prepare = new \ReflectionMethod($request, 'prepareForValidation');
        $prepare->setAccessible(true);
        $prepare->invoke($request);

        $validator = Validator::make($request->all(), $request->rules(), $request->messages());

        return [$validator->errors()->toArray(), $request->all()];
    }
}
