<?php

namespace App\Http\Requests\Admin\EmailCampaign;

use App\Enums\BulkEmailAudience;
use App\Enums\BulkEmailStatus;
use App\Http\Requests\ApiFormRequest;
use App\Http\Requests\Concerns\ListingFilterRules;
use Illuminate\Validation\Rule;

class BulkEmailListRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return array_merge(
            ListingFilterRules::rules(['title', 'status', 'created_at', 'updated_at', 'sent_at']),
            [
                'export' => ['sometimes', 'nullable', Rule::in(['csv', 'pdf'])],
                'filters.campaign_uuid' => ['sometimes', 'nullable', 'string', Rule::exists('campaigns', 'uuid')],
                'filters.status' => ['sometimes', 'nullable', Rule::in(BulkEmailStatus::values())],
                'filters.audience' => ['sometimes', 'nullable', Rule::in(BulkEmailAudience::values())],
                'filters.created_by_admin_uuid' => ['sometimes', 'nullable', 'string', Rule::exists('admins', 'uuid')],
                'filters.is_active' => ['sometimes', 'nullable', Rule::in(['0', '1', true, false])],
            ]
        );
    }
}
