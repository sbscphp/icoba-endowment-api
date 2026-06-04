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

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'statement.required' => 'Please attach an FCMB statement file to import.',
            'statement.file' => 'The uploaded value is not a valid file.',
            'statement.mimetypes' => 'Statement must be a CSV file.',
            'statement.max' => 'Statement file may not be larger than 8MB.',
        ]);
    }
}
