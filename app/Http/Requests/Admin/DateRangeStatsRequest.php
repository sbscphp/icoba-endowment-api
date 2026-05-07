<?php

namespace App\Http\Requests\Admin;

use App\Http\Requests\ApiFormRequest;

class DateRangeStatsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
