<?php

namespace Tests\Unit\Services\Settings;

use App\Enums\DonorTypeSlug;
use App\Models\DonorType;
use App\Models\GraduationSet;
use App\Models\User;
use App\Services\Settings\AccountSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CustomerProfileAffiliationUpdateTest extends TestCase
{
    use RefreshDatabase;

    private GraduationSet $set;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->set = GraduationSet::query()->create([
            'public_id' => 'SET-2002',
            'name' => 'Class 2002',
            'set_number' => '2002',
        ]);
    }

    public function test_alumni_can_set_house_and_profile_exposes_it(): void
    {
        $user = $this->userOfType(DonorTypeSlug::ICOBA_ALUMNI);

        $profile = app(AccountSettingsService::class)->updateCustomerProfile($user, ['house' => 'Parker']);

        $this->assertSame('parker', $user->fresh()->house);
        $this->assertSame(['value' => 'parker', 'label' => 'Parker'], $profile['donor']['house']);
        $this->assertNull($profile['donor']['affiliated_set']);
        $this->assertFalse($profile['donor']['is_igbobian_owned']);
    }

    public function test_wife_can_set_affiliated_set_and_house(): void
    {
        $user = $this->userOfType(DonorTypeSlug::WIVES_OF_ICOBA);

        $profile = app(AccountSettingsService::class)->updateCustomerProfile($user, [
            'affiliated_set_number' => '2002',
            'house' => 'aggrey',
        ]);

        $fresh = $user->fresh();
        $this->assertSame($this->set->uuid, $fresh->affiliated_graduation_set_uuid);
        $this->assertNull($fresh->graduation_set_uuid);
        $this->assertSame('aggrey', $fresh->house);
        $this->assertSame('2002', $profile['donor']['affiliated_set']['set_number']);
        $this->assertNull($profile['donor']['set']);
    }

    public function test_corporate_turning_ownership_off_clears_affiliation(): void
    {
        $user = $this->userOfType(DonorTypeSlug::CORPORATE_DONOR, [
            'organization_name' => 'Acme Ltd',
            'is_igbobian_owned' => true,
            'affiliated_graduation_set_uuid' => $this->set->uuid,
            'house' => 'freeman',
        ]);

        app(AccountSettingsService::class)->updateCustomerProfile($user, ['is_igbobian_owned' => false]);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->is_igbobian_owned);
        $this->assertNull($fresh->affiliated_graduation_set_uuid);
        $this->assertNull($fresh->house);
    }

    public function test_friends_and_relatives_can_set_affiliated_set_and_house_but_not_ownership(): void
    {
        foreach ([DonorTypeSlug::FRIENDS_OF_ICOBA, DonorTypeSlug::RELATIVES_OF_ICOBA] as $slug) {
            $user = $this->userOfType($slug);

            $profile = app(AccountSettingsService::class)->updateCustomerProfile($user, [
                'house' => 'parker',
                'affiliated_set_number' => '2002',
                'is_igbobian_owned' => true,
            ]);

            $fresh = $user->fresh();
            $this->assertSame('parker', $fresh->house);
            $this->assertSame($this->set->uuid, $fresh->affiliated_graduation_set_uuid);
            $this->assertNull($fresh->graduation_set_uuid);
            $this->assertFalse($fresh->is_igbobian_owned);
            $this->assertSame('2002', $profile['donor']['affiliated_set']['set_number']);
            $this->assertSame('Parker', $profile['donor']['house']['label']);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function userOfType(DonorTypeSlug $slug, array $attributes = []): User
    {
        $donorType = DonorType::query()->firstOrCreate(
            ['slug' => $slug->value],
            ['label' => $slug->label(), 'description' => $slug->description()],
        );

        return User::factory()->create(array_merge([
            'donor_type_uuid' => $donorType->uuid,
        ], $attributes));
    }
}
