<?php

namespace App\Http\Requests\Concerns;

use App\Enums\DonorTypeSlug;
use App\Enums\House;
use App\Enums\WivesType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;

/**
 * Per-donor-type rules for house and affiliated-set fields.
 *
 * - ICOBA alumni: optional own `house`.
 * - Wives of ICOBA: required `wives_type` (chapter), optional affiliated Igbobian's `affiliated_set_number` and `house`.
 * - Friends and relatives of ICOBA: optional affiliated Igbobian's `affiliated_set_number` and `house`.
 * - Corporate donors: `is_igbobian_owned`; when true, `affiliated_set_number` is required and `house` stays optional.
 *
 * Shared by registration, profile update, guest checkout/pledge and reconciliation requests.
 */
trait ValidatesDonorAffiliationFields
{
    /**
     * Normalize house to its slug and coerce the ownership flag to a boolean before validation.
     */
    protected function prepareDonorAffiliationForValidation(): void
    {
        $merge = [];

        if ($this->has('house')) {
            $house = $this->input('house');
            $merge['house'] = is_string($house) && trim($house) !== ''
                ? (House::normalize($house) ?? strtolower(trim($house)))
                : null;
        }

        if ($this->has('is_igbobian_owned')) {
            $merge['is_igbobian_owned'] = filter_var($this->input('is_igbobian_owned'), FILTER_VALIDATE_BOOLEAN);
        }

        if ($this->has('wives_type')) {
            $wivesType = $this->input('wives_type');
            $merge['wives_type'] = is_string($wivesType) && trim($wivesType) !== ''
                ? (WivesType::normalize($wivesType) ?? strtolower(trim($wivesType)))
                : null;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, list<string|ValidationRule>>
     */
    protected function donorAffiliationRulesForSlug(?string $slug, bool $sometimes = false): array
    {
        $prefix = $sometimes ? ['sometimes'] : [];

        $house = array_merge($prefix, ['nullable', 'string', Rule::in(House::values())]);
        $optionalSet = array_merge($prefix, ['nullable', 'string', 'max:16', Rule::exists('sets', 'set_number')]);
        $wivesType = array_merge($prefix, ['required', 'string', Rule::in(WivesType::values())]);

        return match ($slug) {
            DonorTypeSlug::ICOBA_ALUMNI->value => [
                'house' => $house,
            ],
            DonorTypeSlug::WIVES_OF_ICOBA->value => [
                'wives_type' => $wivesType,
                'affiliated_set_number' => $optionalSet,
                'house' => $house,
            ],
            DonorTypeSlug::FRIENDS_OF_ICOBA->value,
            DonorTypeSlug::RELATIVES_OF_ICOBA->value => [
                'affiliated_set_number' => $optionalSet,
                'house' => $house,
            ],
            DonorTypeSlug::CORPORATE_DONOR->value => [
                'is_igbobian_owned' => array_merge($prefix, ['nullable', 'boolean']),
                'affiliated_set_number' => array_merge($prefix, [
                    'required_if:is_igbobian_owned,true',
                    'nullable',
                    'string',
                    'max:16',
                    Rule::exists('sets', 'set_number'),
                ]),
                'house' => $house,
            ],
            default => [],
        };
    }

    /**
     * @return array<string, string>
     */
    protected function donorAffiliationMessages(): array
    {
        return [
            'house.in' => 'Please select a valid house.',
            'wives_type.required' => 'Please select your Wives of ICOBA chapter.',
            'wives_type.in' => 'Please select a valid Wives of ICOBA chapter.',
            'affiliated_set_number.exists' => 'We could not find that set. Please double-check the set and try again.',
            'affiliated_set_number.required_if' => 'Please select the set of the Igbobian this organization belongs to.',
        ];
    }
}
