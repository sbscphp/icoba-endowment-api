<?php

namespace App\Http\Requests\Admin\Campaign;

use App\Enums\CampaignCategory;
use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class UpdateCampaignRequest extends ApiFormRequest
{
    public function rules(): array
    {
        $campaignUuid = $this->route('campaignId');

        return [
            'name' => [
                'sometimes',
                'string',
                'max:60',
                Rule::unique('campaigns', 'name')->ignore($campaignUuid, 'uuid'),
            ],
            'short_description' => ['sometimes', 'string', 'max:500', $this->maxWordsRule(50)],
            'long_description' => ['sometimes', 'string'],
            'cover_image' => ['sometimes', 'nullable'],
            'gallery_images' => ['sometimes', 'nullable', 'array', 'max:4'],
            'gallery_images.*' => ['nullable'],
            'categories' => ['sometimes', 'array', 'min:1'],
            'categories.*' => ['string', Rule::in(CampaignCategory::values())],
            'base_currency' => ['sometimes', 'string', Rule::in(Currency::values())],
            'available_donation_currencies' => ['sometimes', 'array', 'min:1'],
            'available_donation_currencies.*' => ['string', Rule::in(Currency::values())],
            'target_amount' => ['sometimes', 'numeric', 'min:0'],
            'start_date' => ['sometimes', 'date'],
            'end_date' => ['sometimes', 'date', 'after_or_equal:start_date'],
            'allow_anonymous_donation' => ['sometimes', 'boolean'],
            'allow_public_donation' => ['sometimes', 'boolean'],
            'applies_to_all_graduation_sets' => ['sometimes', 'boolean'],
            'graduation_set_uuids' => ['sometimes', 'array'],
            'graduation_set_uuids.*' => ['string', Rule::exists('sets', 'uuid')],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'name.unique' => 'Another campaign is already using this name.',
            'name.max' => 'Campaign name may not be longer than 60 characters.',
            'short_description.max' => 'Short description may not be longer than 500 characters.',
            'categories.array' => 'Categories must be provided as a list.',
            'categories.min' => 'Please choose at least one campaign category.',
            'categories.*.in' => 'One or more selected categories are invalid.',
            'base_currency.in' => 'Base currency is invalid.',
            'available_donation_currencies.array' => 'Donation currencies must be provided as a list.',
            'available_donation_currencies.min' => 'Please select at least one donation currency.',
            'available_donation_currencies.*.in' => 'One or more selected donation currencies are invalid.',
            'target_amount.numeric' => 'Target amount must be a number.',
            'target_amount.min' => 'Target amount must be at least 0.',
            'start_date.date' => 'Start date must be a valid date.',
            'end_date.date' => 'End date must be a valid date.',
            'end_date.after_or_equal' => 'End date must be on or after the start date.',
            'gallery_images.array' => 'Gallery images must be provided as a list.',
            'gallery_images.max' => 'You may upload at most 4 gallery images.',
            'graduation_set_uuids.array' => 'Graduation sets must be provided as a list.',
            'graduation_set_uuids.*.exists' => 'One or more selected graduation sets do not exist.',
        ]);
    }

    protected function prepareForValidation(): void
    {
        $all = $this->input('applies_to_all_graduation_sets');
        if ($all === true || $all === 'true' || $all === 1 || $all === '1') {
            $this->merge(['graduation_set_uuids' => []]);
        }
    }

    /**
     * @return \Closure(string, mixed, \Closure): void
     */
    private function maxWordsRule(int $maxWords): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($maxWords): void {
            if (! is_string($value)) {
                return;
            }
            $words = preg_split('/\s+/', trim($value), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            if (count($words) > $maxWords) {
                $fail('The short description must not be more than '.$maxWords.' words.');
            }
        };
    }
}
