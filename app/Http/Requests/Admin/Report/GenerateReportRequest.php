<?php

namespace App\Http\Requests\Admin\Report;

use App\Enums\ReportType;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class GenerateReportRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        ListingFilterRules::applyPeriodDateRangeToRequest($this);
    }

    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules([
                'created_at',
                'updated_at',
                'name',
                'status',
                'uuid',
                'committed_amount',
                'committed_amount_ngn',
                'exchange_rate_to_naira',
                'currency',
                'payment_plan_type',
                'installment_count',
                'campaign_uuid',
                'donor_name',
            ]),
            [
                'report_type' => ['required', 'string', Rule::in(ReportType::values())],
                'export' => ['sometimes', 'nullable', Rule::in(['csv', 'pdf'])],
            ]
        );
    }
}
