<?php

namespace App\Http\Requests\Admin\AuditTrail;

use App\Enums\AuditActionEnum;
use App\Enums\ModuleEnums;
use App\Enums\UserTypeEnum;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Services\Audit\AuditTrailQueryService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuditTrailListingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules(AuditTrailQueryService::SORTABLE_COLUMNS),
            [
                'export' => ['sometimes', 'nullable', 'string', Rule::in(['csv', 'pdf'])],
                'filters.user_type' => ['sometimes', 'nullable', Rule::enum(UserTypeEnum::class)],
                'filters.action_module' => ['sometimes', 'nullable', Rule::in(ModuleEnums::values())],
                'filters.action' => ['sometimes', 'nullable', Rule::in(AuditActionEnum::values())],
                'filters.model' => ['sometimes', 'nullable', 'string', 'max:255'],
                'filters.http_status' => ['sometimes', 'nullable', 'integer', 'between:100,599'],
            ]
        );
    }

    public function messages(): array
    {
        return [
            'export.in' => "Export format must be either 'csv' or 'pdf'.",
            'filters.user_type.enum' => 'User type filter is invalid.',
            'filters.action_module.in' => 'Action module filter is invalid.',
            'filters.action.in' => 'Action filter is invalid.',
            'filters.model.max' => 'Model filter may not be longer than 255 characters.',
            'filters.http_status.integer' => 'HTTP status filter must be an integer.',
            'filters.http_status.between' => 'HTTP status filter must be between 100 and 599.',
        ];
    }

    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);

        if ($this->has('sort_direction') && is_string($this->input('sort_direction'))) {
            $this->merge([
                'sort_direction' => strtolower($this->input('sort_direction')),
            ]);
        }

        if ($this->has('export') && is_string($this->input('export'))) {
            $this->merge([
                'export' => strtolower(trim($this->input('export'))),
            ]);
        }

        $this->merge([
            'sort_by' => $this->input('sort_by', 'created_at'),
            'sort_direction' => $this->input('sort_direction', 'desc'),
            'page' => $this->input('page', 1),
            'per_page' => $this->input('per_page', 15),
        ]);
    }
}
