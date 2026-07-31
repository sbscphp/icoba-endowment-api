<?php

namespace App\Services\Donation;

use App\Enums\DonorTypeSlug;
use App\Models\CorporateCategory;
use App\Models\DonorType;
use App\Models\GraduationSet;

final class GuestDonorProfileSnapshotService
{
    /**
     * @param  array<string, mixed>  $data
     * @return array{
     *     donor_type_uuid: ?string,
     *     donor_name: ?string,
     *     organization_name: ?string,
     *     rc_number: ?string,
     *     tin: ?string,
     *     donor_phone: ?string,
     *     guest_donor_profile: array<string, mixed>
     * }
     */
    public function build(array $data): array
    {
        $donorType = $this->resolveDonorType($data);
        $slug = $donorType?->slug;

        $profile = [
            'donor_type_slug' => $slug,
            'donor_type_label' => $donorType?->label,
            'donor_email' => isset($data['donor_email']) ? strtolower(trim((string) $data['donor_email'])) : null,
            'donor_phone' => isset($data['donor_phone']) ? trim((string) $data['donor_phone']) : null,
            'country_uuid' => $data['country_uuid'] ?? null,
            'country_code' => $data['country_code'] ?? null,
        ];

        $donorName = null;
        $organizationName = null;
        $rcNumber = null;
        $tin = null;

        if ($slug === DonorTypeSlug::ICOBA_ALUMNI->value) {
            $profile['firstname'] = trim((string) ($data['firstname'] ?? ''));
            $profile['lastname'] = trim((string) ($data['lastname'] ?? ''));
            $profile['set_number'] = trim((string) ($data['set_number'] ?? ''));
            $profile['alumni_identifier'] = filled($data['alumni_identifier'] ?? null)
                ? (string) $data['alumni_identifier']
                : null;

            $set = GraduationSet::query()
                ->where('set_number', $profile['set_number'])
                ->first(['uuid', 'name', 'set_number']);

            if ($set !== null) {
                $profile['graduation_set_uuid'] = $set->uuid;
                $profile['graduation_set_name'] = $set->name;
            }

            $donorName = trim($profile['firstname'].' '.$profile['lastname']);
        } elseif ($slug === DonorTypeSlug::CORPORATE_DONOR->value) {
            $organizationName = trim((string) ($data['organization_name'] ?? ''));
            $rcNumber = trim((string) ($data['rc_number'] ?? ''));
            $tin = trim((string) ($data['tin'] ?? ''));

            $categoryUuid = $data['corporate_category_uuid'] ?? null;
            $profile['corporate_category_uuid'] = is_string($categoryUuid) ? $categoryUuid : null;
            $profile['organization_name'] = $organizationName;
            $profile['rc_number'] = $rcNumber;
            $profile['tin'] = $tin;

            if (is_string($categoryUuid) && $categoryUuid !== '') {
                $category = CorporateCategory::query()->where('uuid', $categoryUuid)->first(['uuid', 'name']);
                if ($category !== null) {
                    $profile['corporate_category_name'] = $category->name;
                }
            }

            $donorName = $organizationName;
        } elseif (in_array($slug, [DonorTypeSlug::FRIENDS_OF_ICOBA->value, DonorTypeSlug::RELATIVES_OF_ICOBA->value, DonorTypeSlug::WIVES_OF_ICOBA->value], true)) {
            $profile['firstname'] = trim((string) ($data['firstname'] ?? ''));
            $profile['lastname'] = trim((string) ($data['lastname'] ?? ''));
            $donorName = trim($profile['firstname'].' '.$profile['lastname']);
        }

        return [
            'donor_type_uuid' => $donorType?->uuid,
            'donor_name' => $donorName !== '' ? $donorName : null,
            'organization_name' => $organizationName !== '' ? $organizationName : null,
            'rc_number' => $rcNumber !== '' ? $rcNumber : null,
            'tin' => $tin !== '' ? $tin : null,
            'donor_phone' => $profile['donor_phone'],
            'guest_donor_profile' => array_filter($profile, fn ($value) => $value !== null && $value !== ''),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveDonorType(array $data): ?DonorType
    {
        if (isset($data['donor_type_uuid']) && is_string($data['donor_type_uuid']) && $data['donor_type_uuid'] !== '') {
            return DonorType::query()->where('uuid', $data['donor_type_uuid'])->first();
        }

        $slug = isset($data['donor_type']) ? strtolower(trim((string) $data['donor_type'])) : '';

        if ($slug === '') {
            return null;
        }

        return DonorType::query()->where('slug', $slug)->first();
    }
}
