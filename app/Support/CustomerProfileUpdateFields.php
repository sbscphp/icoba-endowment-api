<?php

namespace App\Support;

use App\Enums\DonorTypeSlug;

/**
 * Single source of truth for which profile fields each donor type may send or persist.
 */
final class CustomerProfileUpdateFields
{
    /** @var list<string> */
    public const IMMUTABLE = [
        'email',
        'donor_type',
        'donor_type_uuid',
    ];

    /** @var list<string> */
    public const CONTACT = [
        'phone_number',
        'country_uuid',
        'country_code',
    ];

    /** @var list<string> */
    public const PERSON = [
        'firstname',
        'lastname',
        'middlename',
    ];

    /** @var list<string> */
    public const ALUMNI = [
        'set_number',
        'set',
        'alumni_identifier',
        'graduation_set_uuid',
    ];

    /** @var list<string> */
    public const CORPORATE = [
        'organization_name',
        'rc_number',
        'tin',
        'corporate_category_uuid',
    ];

    /** @var list<string> */
    public const ALL_PAYLOAD_KEYS = [
        'phone_number',
        'country_uuid',
        'country_code',
        'firstname',
        'lastname',
        'middlename',
        'set_number',
        'set',
        'alumni_identifier',
        'graduation_set_uuid',
        'organization_name',
        'rc_number',
        'tin',
        'corporate_category_uuid',
    ];

    /**
     * @return list<string>
     */
    public static function allowedKeys(?string $donorTypeSlug): array
    {
        $contact = self::CONTACT;

        return match ($donorTypeSlug) {
            DonorTypeSlug::ICOBA_ALUMNI->value => array_merge($contact, self::PERSON, ['set_number', 'alumni_identifier']),
            DonorTypeSlug::CORPORATE_DONOR->value => array_merge($contact, self::CORPORATE),
            DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            DonorTypeSlug::RELATIVES_OF_ICOBA->value => array_merge($contact, self::PERSON),
            default => $contact,
        };
    }

    /**
     * @return list<string>
     */
    public static function prohibitedKeys(?string $donorTypeSlug): array
    {
        $allowed = self::allowedKeys($donorTypeSlug);

        return array_values(array_diff(
            array_merge(self::IMMUTABLE, self::ALL_PAYLOAD_KEYS),
            $allowed,
        ));
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function filterForDonorType(?string $donorTypeSlug, array $data): array
    {
        return array_intersect_key($data, array_flip(self::allowedKeys($donorTypeSlug)));
    }

    /**
     * @return array<string, list<string>>
     */
    public static function prohibitedRules(?string $donorTypeSlug): array
    {
        $rules = [];

        foreach (self::prohibitedKeys($donorTypeSlug) as $field) {
            $rules[$field] = ['prohibited'];
        }

        return $rules;
    }
}
