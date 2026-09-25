<?php

namespace Tests\Feature\Auth;

use App\Enums\DonorTypeSlug;
use App\Enums\eRole;
use App\Models\CorporateCategory;
use App\Models\Country;
use App\Models\DonorType;
use App\Models\GivingIdentity;
use App\Models\GraduationSet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class DonorRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Ic0ba!Xk9mP2vQn7wLz4Rt6';

    private GraduationSet $set;

    private CorporateCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        Country::query()->create(['name' => 'Nigeria', 'iso2' => 'NG', 'dial_code' => '+234', 'is_active' => true]);

        $this->withoutMiddleware(ThrottleRequests::class);
        Mail::fake();
        Notification::fake();

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

        $this->category = CorporateCategory::query()->create(['name' => 'Bank']);
    }

    public function test_registration_options_expose_houses(): void
    {
        $response = $this->getJson('/api/v1/auth/registration/options');

        $response->assertOk();
        $houses = collect($response->json('data.houses'))->pluck('value')->all();

        $this->assertSame(['parker', 'townsend', 'oluwole', 'aggrey', 'freeman'], $houses);
        $this->assertSame('Parker', $response->json('data.houses.0.label'));

        $this->assertSame(
            ['ICOBANA Wives', 'Wives of ICOBA, Europe', 'Wives of ICOBA, International'],
            collect($response->json('data.icoba_wives_type'))->pluck('label')->all(),
        );
        $this->assertSame('icobana_wives', $response->json('data.icoba_wives_type.0.value'));

        $this->assertSame(
            ['general', 'student_welfare', 'infrastructure'],
            collect($response->json('data.donation_purposes'))->pluck('value')->all(),
        );
        $this->assertSame('Student Welfare', $response->json('data.donation_purposes.1.label'));
    }

    public function test_alumni_can_register_with_house(): void
    {
        $response = $this->postJson('/api/v1/auth/signup', $this->payload([
            'donor_type' => DonorTypeSlug::ICOBA_ALUMNI->value,
            'firstname' => 'Ade',
            'lastname' => 'Ola',
            'set_number' => '2002',
            'house' => 'Parker',
        ]));

        $response->assertStatus(201);

        $user = User::query()->where('email', 'donor@example.com')->firstOrFail();
        $this->assertSame('parker', $user->house);
        $this->assertSame($this->set->uuid, $user->graduation_set_uuid);
        $this->assertNull($user->affiliated_graduation_set_uuid);
        $this->assertFalse($user->is_igbobian_owned);

        $identity = GivingIdentity::query()->where('user_uuid', $user->uuid)->firstOrFail();
        $this->assertSame('parker', $identity->house);
    }

    public function test_alumni_house_is_optional_but_must_be_valid(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload([
            'donor_type' => DonorTypeSlug::ICOBA_ALUMNI->value,
            'firstname' => 'Ade',
            'lastname' => 'Ola',
            'set_number' => '2002',
        ]))->assertStatus(201);

        $this->postJson('/api/v1/auth/signup', $this->payload([
            'donor_type' => DonorTypeSlug::ICOBA_ALUMNI->value,
            'email' => 'other@example.com',
            'phone_number' => '+2348099999999',
            'firstname' => 'Ade',
            'lastname' => 'Ola',
            'set_number' => '2002',
            'house' => 'hogwarts',
        ]))->assertStatus(422)->assertJsonStructure(['data' => ['house']]);
    }

    public function test_wife_can_register_with_husband_set_and_house(): void
    {
        $response = $this->postJson('/api/v1/auth/signup', $this->payload([
            'donor_type' => DonorTypeSlug::WIVES_OF_ICOBA->value,
            'firstname' => 'Bisi',
            'lastname' => 'Ola',
            'affiliated_set_number' => '2002',
            'house' => 'aggrey',
            'wives_type' => 'Wives of ICOBA, Europe',
        ]));

        $response->assertStatus(201);

        $user = User::query()->where('email', 'donor@example.com')->firstOrFail();
        $this->assertNull($user->graduation_set_uuid);
        $this->assertSame($this->set->uuid, $user->affiliated_graduation_set_uuid);
        $this->assertSame('aggrey', $user->house);
        $this->assertSame('wives_of_icoba_europe', $user->wives_type);
        $this->assertFalse($user->is_igbobian_owned);

        $identity = GivingIdentity::query()->where('user_uuid', $user->uuid)->firstOrFail();
        $this->assertSame($this->set->uuid, $identity->affiliated_graduation_set_uuid);
        $this->assertSame('aggrey', $identity->house);
        $this->assertSame('wives_of_icoba_europe', $identity->wives_type);
    }

    public function test_wife_must_specify_wives_type(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload([
            'donor_type' => DonorTypeSlug::WIVES_OF_ICOBA->value,
            'firstname' => 'Bisi',
            'lastname' => 'Ola',
        ]))->assertStatus(422)->assertJsonStructure(['data' => ['wives_type']]);

        $this->postJson('/api/v1/auth/signup', $this->payload([
            'donor_type' => DonorTypeSlug::WIVES_OF_ICOBA->value,
            'firstname' => 'Bisi',
            'lastname' => 'Ola',
            'wives_type' => 'wives_of_mars',
        ]))->assertStatus(422)->assertJsonStructure(['data' => ['wives_type']]);
    }

    public function test_wives_type_is_ignored_for_other_donor_types(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload([
            'donor_type' => DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            'firstname' => 'Tunde',
            'lastname' => 'Ola',
            'wives_type' => 'icobana_wives',
        ]))->assertStatus(201);

        $this->assertNull(User::query()->where('email', 'donor@example.com')->firstOrFail()->wives_type);
    }

    public function test_friends_and_relatives_can_register_with_affiliated_set_and_house(): void
    {
        foreach ([DonorTypeSlug::FRIENDS_OF_ICOBA, DonorTypeSlug::RELATIVES_OF_ICOBA] as $index => $slug) {
            $email = "donor{$index}@example.com";
            $phone = '+2348077777'.$index.'00';

            $this->postJson('/api/v1/auth/signup', $this->payload([
                'donor_type' => $slug->value,
                'email' => $email,
                'phone_number' => $phone,
                'firstname' => 'Tunde',
                'lastname' => 'Ola',
                'affiliated_set_number' => '2002',
                'house' => 'Freeman',
            ]))->assertStatus(201);

            $user = User::query()->where('email', $email)->firstOrFail();
            $this->assertNull($user->graduation_set_uuid);
            $this->assertSame($this->set->uuid, $user->affiliated_graduation_set_uuid);
            $this->assertSame('freeman', $user->house);
            $this->assertFalse($user->is_igbobian_owned);

            $identity = GivingIdentity::query()->where('user_uuid', $user->uuid)->firstOrFail();
            $this->assertSame($this->set->uuid, $identity->affiliated_graduation_set_uuid);
            $this->assertSame('freeman', $identity->house);
        }
    }

    public function test_friends_affiliated_set_must_exist_and_house_must_be_valid(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload([
            'donor_type' => DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            'firstname' => 'Tunde',
            'lastname' => 'Ola',
            'affiliated_set_number' => '0000',
            'house' => 'hogwarts',
        ]))->assertStatus(422)->assertJsonStructure(['data' => ['affiliated_set_number', 'house']]);
    }

    public function test_wife_set_and_house_are_optional(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->payload([
            'donor_type' => DonorTypeSlug::WIVES_OF_ICOBA->value,
            'firstname' => 'Bisi',
            'lastname' => 'Ola',
            'wives_type' => 'icobana_wives',
        ]))->assertStatus(201);

        $user = User::query()->where('email', 'donor@example.com')->firstOrFail();
        $this->assertNull($user->affiliated_graduation_set_uuid);
        $this->assertNull($user->house);
        $this->assertSame('icobana_wives', $user->wives_type);
    }

    public function test_igbobian_owned_corporate_requires_affiliated_set_but_not_house(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->corporatePayload([
            'is_igbobian_owned' => true,
        ]))->assertStatus(422)->assertJsonStructure(['data' => ['affiliated_set_number']]);

        $this->postJson('/api/v1/auth/signup', $this->corporatePayload([
            'is_igbobian_owned' => true,
            'affiliated_set_number' => '2002',
        ]))->assertStatus(201);

        $user = User::query()->where('email', 'donor@example.com')->firstOrFail();
        $this->assertTrue($user->is_igbobian_owned);
        $this->assertSame($this->set->uuid, $user->affiliated_graduation_set_uuid);
        $this->assertNull($user->house);
        $this->assertNull($user->graduation_set_uuid);

        $identity = GivingIdentity::query()->where('user_uuid', $user->uuid)->firstOrFail();
        $this->assertTrue($identity->is_igbobian_owned);
        $this->assertSame($this->set->uuid, $identity->affiliated_graduation_set_uuid);
    }

    public function test_corporate_not_igbobian_owned_ignores_affiliation_fields(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->corporatePayload([
            'is_igbobian_owned' => false,
            'affiliated_set_number' => '2002',
            'house' => 'parker',
        ]))->assertStatus(201);

        $user = User::query()->where('email', 'donor@example.com')->firstOrFail();
        $this->assertFalse($user->is_igbobian_owned);
        $this->assertNull($user->affiliated_graduation_set_uuid);
        $this->assertNull($user->house);
    }

    public function test_corporate_without_flag_defaults_to_not_owned(): void
    {
        $this->postJson('/api/v1/auth/signup', $this->corporatePayload([]))->assertStatus(201);

        $user = User::query()->where('email', 'donor@example.com')->firstOrFail();
        $this->assertFalse($user->is_igbobian_owned);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides): array
    {
        return array_merge([
            'email' => 'donor@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'phone_number' => '+2348012345678',
            'otp_channel' => 'email',
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function corporatePayload(array $overrides): array
    {
        return $this->payload(array_merge([
            'donor_type' => DonorTypeSlug::CORPORATE_DONOR->value,
            'organization_name' => 'Acme Ltd',
            'corporate_category_uuid' => $this->category->uuid,
            'rc_number' => 'RC123456',
            'tin' => 'TIN123456',
        ], $overrides));
    }
}
