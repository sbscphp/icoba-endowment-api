<?php

namespace App\Services;

use App\Models\CorporateCategory;
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
     * }
     */
    public function donorRegistrationMetadata(): array
    {
        return [
            'donor_types' => DonorType::query()->orderBy('id')->get(['uuid', 'slug', 'label', 'description']),
            'corporate_categories' => CorporateCategory::query()->orderBy('id')->get(['uuid', 'name']),
            'sets' => GraduationSet::query()->orderBy('id')->get(['uuid', 'public_id', 'name', 'set_number']),
        ];
    }
}
