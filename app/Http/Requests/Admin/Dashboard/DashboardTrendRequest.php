<?php

namespace App\Http\Requests\Admin\Dashboard;

use Illuminate\Validation\Rule;

class DashboardTrendRequest extends DashboardFilterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'year' => ['sometimes', 'nullable', 'integer', 'min:2000', 'max:2100'],
            'campaign_uuid' => ['sometimes', 'nullable', 'uuid', Rule::exists('campaigns', 'uuid')],
        ]);
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'year.integer' => 'Year must be a whole number.',
            'year.min' => 'Year must be at least 2000.',
            'year.max' => 'Year may not be greater than 2100.',
            'campaign_uuid.uuid' => 'Campaign filter is invalid.',
            'campaign_uuid.exists' => 'Selected campaign does not exist.',
        ]);
    }

    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();

        // Empty campaign_uuid must not land in validated() — otherwise DashboardService
        // would treat it as an active filter (same convention as `currency` above).
        if ($this->has('campaign_uuid') && trim((string) $this->input('campaign_uuid')) === '') {
            $this->replace($this->except('campaign_uuid'));
        }
    }
}
