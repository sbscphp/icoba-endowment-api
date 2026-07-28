<?php

namespace App\Http\Requests\Public;

use App\Http\Requests\ApiFormRequest;

class PublicEventListRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'sort_by' => ['sometimes', 'string', 'in:title,event_date,created_at'],
            'sort_direction' => ['sometimes', 'string', 'in:asc,desc,ASC,DESC'],
        ];
    }
}
