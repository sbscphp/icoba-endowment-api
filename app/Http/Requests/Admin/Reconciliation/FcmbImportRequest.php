<?php

namespace App\Http\Requests\Admin\Reconciliation;

use App\Http\Requests\ApiFormRequest;

class FcmbImportRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'statement' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/vnd.ms-excel', 'max:8192'],
        ];
    }
}
