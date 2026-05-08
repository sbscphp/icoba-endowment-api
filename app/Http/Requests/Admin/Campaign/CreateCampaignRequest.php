<?php

namespace App\Http\Requests\Admin\Campaign;

use App\Enums\CampaignCategory;
use App\Enums\Currency;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CreateCampaignRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique('campaigns', 'name'),
            ],
            'short_description' => ['required', 'string', 'max:500', $this->maxWordsRule(50)],
            'long_description' => ['required', 'string'],
            'cover_image' => ['sometimes', 'nullable'],
            'gallery_images' => ['sometimes', 'nullable', 'array', 'max:4'],
            'gallery_images.*' => ['nullable'],
            'categories' => ['required', 'array', 'min:1'],
            'categories.*' => ['string', Rule::in(CampaignCategory::values())],
            'base_currency' => ['required', 'string', Rule::in(Currency::values())],
            'available_donation_currencies' => ['required', 'array', 'min:1'],
            'available_donation_currencies.*' => ['string', Rule::in(Currency::values())],
            'target_amount' => ['required', 'numeric', 'min:0'],
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date', 'after_or_equal:start_date'],
            'allow_anonymous_donation' => ['sometimes', 'boolean'],
            'allow_public_donation' => ['sometimes', 'boolean'],
            'applies_to_all_graduation_sets' => ['sometimes', 'boolean'],
            'graduation_set_uuids' => [
                'required_if:applies_to_all_graduation_sets,false',
                'array',
            ],
            'graduation_set_uuids.*' => ['string', Rule::exists('sets', 'uuid')],
        ];
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
