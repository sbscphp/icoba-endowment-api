<?php

namespace App\Support;

use App\Enums\DonorTypeSlug;
use App\Enums\House;
use App\Enums\WivesType;
use App\Models\GraduationSet;

/**
 * Resolves the house / affiliated-set / Igbobian-ownership columns from a validated payload,
 * applying the per-donor-type semantics so every write path stores the same shape.
 */
final class DonorAffiliation
{
    /** @var list<string> */
    public const COLUMNS = ['house', 'affiliated_graduation_set_uuid', 'is_igbobian_owned', 'wives_type'];

    /** @var list<string> */
    public const PAYLOAD_KEYS = ['house', 'affiliated_set_number', 'affiliated_graduation_set_uuid', 'is_igbobian_owned', 'wives_type'];

    /**
     * Column values for a donor of the given type. Fields not applicable to the type are nulled.
     *
     * @param  array<string, mixed>  $data
     * @return array{house: ?string, affiliated_graduation_set_uuid: ?string, is_igbobian_owned: bool, wives_type: ?string}
     */
    public static function columnsFor(?string $slug, array $data): array
    {
        $empty = ['house' => null, 'affiliated_graduation_set_uuid' => null, 'is_igbobian_owned' => false, 'wives_type' => null];

        return match ($slug) {
            DonorTypeSlug::ICOBA_ALUMNI->value => array_merge($empty, [
                'house' => House::normalize($data['house'] ?? null),
            ]),
            DonorTypeSlug::WIVES_OF_ICOBA->value => array_merge($empty, [
                'house' => House::normalize($data['house'] ?? null),
                'affiliated_graduation_set_uuid' => self::resolveSetUuid($data),
                'wives_type' => WivesType::normalize($data['wives_type'] ?? null),
            ]),
            DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            DonorTypeSlug::RELATIVES_OF_ICOBA->value => array_merge($empty, [
                'house' => House::normalize($data['house'] ?? null),
                'affiliated_graduation_set_uuid' => self::resolveSetUuid($data),
            ]),
            DonorTypeSlug::CORPORATE_DONOR->value => array_merge($empty, self::corporateColumns($data)),
            default => $empty,
        };
    }

    /**
     * Like columnsFor() but only returns keys present in the payload, for partial (PATCH) updates.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function partialColumnsFor(?string $slug, array $data): array
    {
        $touched = array_intersect(self::PAYLOAD_KEYS, array_keys($data));
        if ($touched === []) {
            return [];
        }

        $resolved = self::columnsFor($slug, $data);
        $out = [];

        if (in_array('house', $touched, true)) {
            $out['house'] = $resolved['house'];
        }

        if (array_intersect(['affiliated_set_number', 'affiliated_graduation_set_uuid'], $touched) !== []) {
            $out['affiliated_graduation_set_uuid'] = $resolved['affiliated_graduation_set_uuid'];
        }

        if (in_array('is_igbobian_owned', $touched, true)) {
            $out['is_igbobian_owned'] = $resolved['is_igbobian_owned'];
            if (! $resolved['is_igbobian_owned']) {
                // Turning ownership off clears the affiliation.
                $out['affiliated_graduation_set_uuid'] = null;
                $out['house'] = null;
            }
        }

        if (in_array('wives_type', $touched, true)) {
            $out['wives_type'] = $resolved['wives_type'];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{house: ?string, affiliated_graduation_set_uuid: ?string, is_igbobian_owned: bool}
     */
    private static function corporateColumns(array $data): array
    {
        $owned = filter_var($data['is_igbobian_owned'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (! $owned) {
            return ['house' => null, 'affiliated_graduation_set_uuid' => null, 'is_igbobian_owned' => false];
        }

        return [
            'house' => House::normalize($data['house'] ?? null),
            'affiliated_graduation_set_uuid' => self::resolveSetUuid($data),
            'is_igbobian_owned' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function resolveSetUuid(array $data): ?string
    {
        $uuid = $data['affiliated_graduation_set_uuid'] ?? null;
        if (is_string($uuid) && $uuid !== '') {
            return $uuid;
        }

        $setNumber = $data['affiliated_set_number'] ?? null;
        if (! is_string($setNumber) || trim($setNumber) === '') {
            return null;
        }

        $resolved = GraduationSet::query()->where('set_number', trim($setNumber))->value('uuid');

        return is_string($resolved) ? $resolved : null;
    }
}
