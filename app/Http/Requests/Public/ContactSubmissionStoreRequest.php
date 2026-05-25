<?php

namespace App\Http\Requests\Public;

use App\Enums\ContactSubmissionUserType;
use App\Http\Requests\ApiFormRequest;
use Illuminate\Validation\Rule;

class ContactSubmissionStoreRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->filled('user_type')) {
            $this->merge([
                'user_type' => strtolower(trim((string) $this->input('user_type'))),
            ]);
        }
    }

    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:191'],
            'user_type' => ['required', 'string', Rule::enum(ContactSubmissionUserType::class)],
            'description' => ['required', 'string', 'max:5000'],
        ];
    }

    public function attributes(): array
    {
        return array_merge(parent::attributes(), [
            'full_name' => 'full name',
            'user_type' => 'user type',
            'description' => 'description',
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'user_type.required' => 'Please select a user type.',
            'user_type.enum' => 'Please select a valid user type.',
            'description.required' => 'Please enter a description.',
            'description.string' => 'The description must be a string.',
            'description.max' => 'The description must be less than 5000 characters.',
            'email.required' => 'Please enter an email address.',
            'email.email' => 'Please enter a valid email address.',
            'email.max' => 'The email address must be less than 191 characters.',
            'full_name.required' => 'Please enter a full name.',
            'full_name.string' => 'The full name must be a string.',
            'full_name.max' => 'The full name must be less than 120 characters.',   
        ]);
    }
}
