<?php

namespace Tests\Feature\Customer\Settings;

use App\Enums\DonorTypeSlug;
use App\Enums\eRole;
use App\Models\Country;
use App\Models\DonorType;
use App\Models\GraduationSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerProfileAffiliationTest extends TestCase
{
    use RefreshDatabase;

    private GraduationSet $set;

    protected function setUp(): void
    {
        parent::setUp();

        Country::query()->create(['name' => 'Nigeria', 'iso2' => 'NG', 'dial_code' => '+234', 'is_active' => true]);
        $this->withoutMiddleware(ThrottleRequests::class);

        Role::firstOrCreate(['name' => eRole::CUSTOMER->value, 'guard_name' => 'api'], ['uuid' => (string) Str::uuid()]);

        foreach (DonorTypeSlug::cases() as $slug) {
            DonorType::query()->firstOrCreate(
                ['slug' => $slug->value],
                ['label' => $slug->label(), 'description' => $slug->description()],
            );
        }

        $this->set = GraduationSet::query()->create([
            'public_id' => 'SET-2002',
            'name' => 'Class 2002',
            'set_number' => '2002',
        ]);
    }

    public function test_profile_exposes_house_and_affiliated_set_for_wife(): void
    {
        $user = $this->customer([
            'donor_type_uuid' => DonorType::query()->where('slug', DonorTypeSlug::WIVES_OF_ICOBA->value)->value('uuid'),
            'house' => 'aggrey',
            'affiliated_graduation_set_uuid' => $this->set->uuid,
            'wives_type' => 'icobana_wives',
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/settings/profile');

        $response->assertOk();
        $this->assertSame(['value' => 'aggrey', 'label' => 'Aggrey'], $response->json('data.donor.house'));
        $this->assertSame(['value' => 'icobana_wives', 'label' => 'ICOBANA Wives'], $response->json('data.donor.wives_type'));
        $this->assertSame($this->set->uuid, $response->json('data.donor.affiliated_set.uuid'));
        $this->assertSame('2002', $response->json('data.donor.affiliated_set.set_number'));
        $this->assertFalse($response->json('data.donor.is_igbobian_owned'));
        $this->assertNull($response->json('data.donor.set'));
    }

    public function test_profile_exposes_igbobian_owned_flag_for_corporate(): void
    {
        $user = $this->customer([
            'donor_type_uuid' => DonorType::query()->where('slug', DonorTypeSlug::CORPORATE_DONOR->value)->value('uuid'),
            'organization_name' => 'Acme Ltd',
            'is_igbobian_owned' => true,
            'affiliated_graduation_set_uuid' => $this->set->uuid,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/settings/profile');

        $response->assertOk();
        $this->assertTrue($response->json('data.donor.is_igbobian_owned'));
        $this->assertSame($this->set->uuid, $response->json('data.donor.affiliated_set.uuid'));
        $this->assertNull($response->json('data.donor.house'));
    }

    public function test_profile_returns_nulls_when_affiliation_not_set(): void
    {
        $user = $this->customer([
            'donor_type_uuid' => DonorType::query()->where('slug', DonorTypeSlug::ICOBA_ALUMNI->value)->value('uuid'),
            'graduation_set_uuid' => $this->set->uuid,
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/settings/profile');

        $response->assertOk();
        $this->assertNull($response->json('data.donor.house'));
        $this->assertNull($response->json('data.donor.affiliated_set'));
        $this->assertFalse($response->json('data.donor.is_igbobian_owned'));
        $this->assertNull($response->json('data.donor.wives_type'));
        $this->assertSame($this->set->uuid, $response->json('data.donor.set.uuid'));
    }

    public function test_wife_can_update_wives_type_and_must_send_a_valid_one(): void
    {
        $user = $this->customer([
            'donor_type_uuid' => DonorType::query()->where('slug', DonorTypeSlug::WIVES_OF_ICOBA->value)->value('uuid'),
            'wives_type' => 'icobana_wives',
        ]);

        Sanctum::actingAs($user);

        $this->patchJson('/api/v1/settings/profile', ['wives_type' => 'wives_of_mars'])
            ->assertStatus(422)
            ->assertJsonStructure(['data' => ['wives_type']]);

        $response = $this->patchJson('/api/v1/settings/profile', ['wives_type' => 'Wives of ICOBA, Europe']);

        $response->assertOk();
        $this->assertSame('wives_of_icoba_europe', $response->json('data.donor.wives_type.value'));
        $this->assertSame('wives_of_icoba_europe', $user->fresh()->wives_type);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function customer(array $attributes): User
    {
        $user = User::factory()->create($attributes);
        $user->assignRole(eRole::CUSTOMER->value);

        return $user;
    }
}
