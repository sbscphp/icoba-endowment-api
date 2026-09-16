<?php

namespace App\Http\Requests\Admin\Report;

use App\Http\Requests\ApiFormRequest;
use Carbon\CarbonInterface;
use Illuminate\Validation\Rule;

class WeeklyDigestReportRequest extends ApiFormRequest
{
    public function rules(): array
    {
        // The digest only covers completed weeks, so the date must fall before
        // the Monday that started the current week.
        $currentWeekStart = now((string) config('app.timezone', 'UTC'))
            ->startOfWeek(CarbonInterface::MONDAY)
            ->toDateString();

        return [
            'date' => ['sometimes', 'nullable', 'date_format:Y-m-d', 'before:'.$currentWeekStart],
            'export' => ['sometimes', 'nullable', Rule::in(['pdf', 'csv'])],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'date.date_format' => 'Report date must be in Y-m-d format.',
            'date.before' => 'Report date must fall in a completed week; the digest covers full Monday–Sunday weeks only.',
            'export.in' => "Export format must be either 'pdf' or 'csv'.",
        ]);
    }
}
