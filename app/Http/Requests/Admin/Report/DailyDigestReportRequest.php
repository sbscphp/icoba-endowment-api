<?php

namespace App\Http\Requests\Admin\Report;

use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class DailyDigestReportRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:today'],
            'export' => ['sometimes', 'nullable', Rule::in(['pdf', 'csv'])],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'date.date_format' => 'Report date must be in Y-m-d format.',
            'date.before' => 'Report date must be a past day; the digest covers completed days only.',
            'export.in' => "Export format must be either 'pdf' or 'csv'.",
        ]);
    }
}
