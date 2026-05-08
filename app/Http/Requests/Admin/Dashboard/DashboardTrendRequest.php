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
}
