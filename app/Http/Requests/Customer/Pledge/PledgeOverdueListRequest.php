<?php

namespace App\Http\Requests\Customer\Pledge;

use App\Http\Requests\ApiFormRequest;

class PledgeOverdueListRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
