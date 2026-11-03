<?php

namespace App\Http\Requests\Public;

use App\Enums\AdImageOrientation;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class PublicAdListRequest extends ApiFormRequest
{
    /**
     * `orientation` narrows the feed to one slot type: `?orientation=portrait` returns only
     * ads that have portrait images, each carrying just its portrait gallery. Without it the
     * feed returns every live ad with both galleries.
     */
    public function rules(): array
    {
        return [
            'orientation' => ['sometimes', 'nullable', 'string', Rule::in(AdImageOrientation::values())],
        ];
    }

    protected function prepareForValidation(): void
    {
        $orientation = $this->query('orientation');
        if (is_string($orientation)) {
            $this->merge(['orientation' => strtolower(trim($orientation))]);
        }
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'orientation.in' => 'Orientation must be one of: '.implode(', ', AdImageOrientation::values()).'.',
        ]);
    }

    public function orientation(): ?AdImageOrientation
    {
        return AdImageOrientation::tryFromRequest($this->validated('orientation'));
    }
}
