<?php

namespace App\Http\Requests\Admin;

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
