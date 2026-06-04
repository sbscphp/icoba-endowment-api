<?php

namespace App\Http\Requests\Admin\Campaign;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class CampaignReportRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'format' => ['sometimes', 'nullable', Rule::in(['csv', 'pdf'])],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'start_date.date' => 'Start date must be a valid date.',
            'end_date.date' => 'End date must be a valid date.',
            'end_date.after_or_equal' => 'End date must be on or after the start date.',
            'format.in' => "Export format must be either 'csv' or 'pdf'.",
        ]);
    }
}
