<?php

namespace App\Services;

use App\Enums\DonationPurpose;
use App\Enums\House;
use App\Enums\WivesType;
use App\Http\Resources\CountryResource;
use App\Models\CorporateCategory;
use App\Models\Country;
use App\Models\DonorType;
use App\Models\GraduationSet;
use Illuminate\Database\Eloquent\Collection;

final class DropdownService
{
    /**
     * Options exposed on donor registration metadata (sign-up dropdowns).
     *
     * @return array{
     *     donor_types: Collection<int, DonorType>,
     *     corporate_categories: Collection<int, CorporateCategory>,
     *     sets: Collection<int, GraduationSet>,
     *     houses: list<array{value: string, label: string}>,
     *     icoba_wives_type: list<array{value: string, label: string}>,
     *     donation_purposes: list<array{value: string, label: string}>,
     *     countries: array<int, array<string, mixed>>,
     * }
     */
    public function donorRegistrationMetadata(): array
    {
        return [
            'donor_types' => DonorType::query()->orderBy('id')->get(['uuid', 'slug', 'label', 'description']),
            'corporate_categories' => CorporateCategory::query()->orderBy('id')->get(['uuid', 'name']),
            'sets' => GraduationSet::query()->orderBy('sort_order')->orderBy('set_number')->get(['uuid', 'public_id', 'name', 'set_number', 'sort_order']),
            'houses' => House::options(),
            'icoba_wives_type' => WivesType::options(),
            'donation_purposes' => DonationPurpose::options(),
            'countries' => CountryResource::collection(
                Country::query()->active()->orderBy('name')->get()
            )->resolve(),
        ];
    }
}
