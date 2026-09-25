<?php

namespace Tests\Unit\Support;

use App\Enums\DonorTypeSlug;
use App\Models\GraduationSet;
use App\Support\DonorAffiliation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DonorAffiliationTest extends TestCase
{
    use RefreshDatabase;

    private GraduationSet $set;

    protected function setUp(): void
    {
        parent::setUp();

        $this->set = GraduationSet::query()->create([
            'public_id' => 'SET-1998',
            'name' => 'Class 1998',
            'set_number' => '1998',
        ]);
    }

    public function test_alumni_only_keeps_house(): void
    {
        $columns = DonorAffiliation::columnsFor(DonorTypeSlug::ICOBA_ALUMNI->value, [
            'house' => 'Townsend',
            'affiliated_set_number' => '1998',
            'is_igbobian_owned' => true,
        ]);

        $this->assertSame([
            'house' => 'townsend',
            'affiliated_graduation_set_uuid' => null,
            'is_igbobian_owned' => false,
            'wives_type' => null,
        ], $columns);
    }

    public function test_wife_resolves_affiliated_set_from_set_number(): void
    {
        $columns = DonorAffiliation::columnsFor(DonorTypeSlug::WIVES_OF_ICOBA->value, [
            'affiliated_set_number' => '1998',
            'house' => 'freeman',
            'wives_type' => 'Wives of ICOBA, Europe',
        ]);

        $this->assertSame($this->set->uuid, $columns['affiliated_graduation_set_uuid']);
        $this->assertSame('freeman', $columns['house']);
        $this->assertFalse($columns['is_igbobian_owned']);
        $this->assertSame('wives_of_icoba_europe', $columns['wives_type']);
    }

    public function test_wives_type_is_only_kept_for_wives(): void
    {
        foreach ([DonorTypeSlug::ICOBA_ALUMNI, DonorTypeSlug::FRIENDS_OF_ICOBA, DonorTypeSlug::RELATIVES_OF_ICOBA] as $slug) {
            $columns = DonorAffiliation::columnsFor($slug->value, ['wives_type' => 'icobana_wives']);
            $this->assertNull($columns['wives_type']);
        }

        $corporate = DonorAffiliation::columnsFor(DonorTypeSlug::CORPORATE_DONOR->value, [
            'is_igbobian_owned' => true,
            'affiliated_set_number' => '1998',
            'wives_type' => 'icobana_wives',
        ]);
        $this->assertNull($corporate['wives_type']);

        $this->assertSame(
            ['wives_type' => 'icobana_wives'],
            DonorAffiliation::partialColumnsFor(DonorTypeSlug::WIVES_OF_ICOBA->value, ['wives_type' => 'ICOBANA Wives']),
        );
    }

    public function test_corporate_not_owned_clears_affiliation(): void
    {
        $columns = DonorAffiliation::columnsFor(DonorTypeSlug::CORPORATE_DONOR->value, [
            'is_igbobian_owned' => '0',
            'affiliated_set_number' => '1998',
            'house' => 'parker',
        ]);

        $this->assertSame([
            'house' => null,
            'affiliated_graduation_set_uuid' => null,
            'is_igbobian_owned' => false,
            'wives_type' => null,
        ], $columns);
    }

    public function test_corporate_owned_keeps_set_and_optional_house(): void
    {
        $columns = DonorAffiliation::columnsFor(DonorTypeSlug::CORPORATE_DONOR->value, [
            'is_igbobian_owned' => 'true',
            'affiliated_set_number' => '1998',
        ]);

        $this->assertSame([
            'house' => null,
            'affiliated_graduation_set_uuid' => $this->set->uuid,
            'is_igbobian_owned' => true,
            'wives_type' => null,
        ], $columns);
    }

    public function test_unknown_house_and_set_resolve_to_null(): void
    {
        $columns = DonorAffiliation::columnsFor(DonorTypeSlug::WIVES_OF_ICOBA->value, [
            'affiliated_set_number' => '0000',
            'house' => 'hogwarts',
        ]);

        $this->assertNull($columns['house']);
        $this->assertNull($columns['affiliated_graduation_set_uuid']);
    }

    public function test_friends_and_relatives_keep_house_and_set_but_never_ownership(): void
    {
        foreach ([DonorTypeSlug::FRIENDS_OF_ICOBA, DonorTypeSlug::RELATIVES_OF_ICOBA] as $slug) {
            $columns = DonorAffiliation::columnsFor($slug->value, [
                'house' => 'parker',
                'affiliated_set_number' => '1998',
                'is_igbobian_owned' => true,
            ]);

            $this->assertSame('parker', $columns['house']);
            $this->assertSame($this->set->uuid, $columns['affiliated_graduation_set_uuid']);
            $this->assertFalse($columns['is_igbobian_owned']);
        }
    }

    public function test_partial_update_only_touches_sent_keys(): void
    {
        $this->assertSame([], DonorAffiliation::partialColumnsFor(DonorTypeSlug::ICOBA_ALUMNI->value, ['firstname' => 'X']));

        $this->assertSame(
            ['house' => 'oluwole'],
            DonorAffiliation::partialColumnsFor(DonorTypeSlug::ICOBA_ALUMNI->value, ['house' => 'Oluwole']),
        );

        $this->assertSame(
            ['affiliated_graduation_set_uuid' => $this->set->uuid],
            DonorAffiliation::partialColumnsFor(DonorTypeSlug::WIVES_OF_ICOBA->value, ['affiliated_set_number' => '1998']),
        );
    }

    public function test_partial_update_turning_ownership_off_clears_affiliation(): void
    {
        $this->assertSame(
            ['is_igbobian_owned' => false, 'affiliated_graduation_set_uuid' => null, 'house' => null],
            DonorAffiliation::partialColumnsFor(DonorTypeSlug::CORPORATE_DONOR->value, ['is_igbobian_owned' => false]),
        );
    }
}
