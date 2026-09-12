<?php

namespace App\Http\Requests\Admin\Pledge;

use App\Http\Requests\ApiFormRequest;

class PledgeReminderRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'schedule_item_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'force' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'schedule_item_id.string' => 'Installment reference must be text.',
            'schedule_item_id.max' => 'Installment reference is too long.',
            'note.string' => 'Note must be text.',
            'note.max' => 'Note may not be longer than 1000 characters.',
            'force.boolean' => 'Force must be true or false.',
        ]);
    }
}
