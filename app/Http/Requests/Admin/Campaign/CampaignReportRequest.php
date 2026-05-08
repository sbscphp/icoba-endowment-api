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
}
