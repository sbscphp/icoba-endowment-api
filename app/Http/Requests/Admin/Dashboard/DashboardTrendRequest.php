<?php

namespace App\Http\Requests\Admin\Dashboard;

class DashboardTrendRequest extends DashboardFilterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'year.integer' => 'Year must be a whole number.',
            'year.min' => 'Year must be at least 2000.',
            'year.max' => 'Year may not be greater than 2100.',
        ]);
    }
}
