<?php

namespace App\Http\Requests\Admin\ContentManagement;

use App\Http\Requests\Admin\ContentManagement\Concerns\AdImageSetRules;
use App\Http\Requests\ApiFormRequest;
use App\Services\Admin\ContentManagement\AdService;
use Illuminate\Validation\Rule;

class UpdateAdRequest extends ApiFormRequest
{
    use AdImageSetRules;

    /**
     * `landscape_images` / `portrait_images`, when present, are each the full desired gallery
     * for that orientation (existing URLs to keep + new base64/file entries to upload) — see
     * AdImageSetRules. Omit a key entirely to leave that orientation untouched. The ad must
     * keep at least one image overall, which the service enforces after reconciling. The
     * start/end window coherence is validated in the service against the effective (merged) values.
     *
     * `status` is a convenience alias over `is_active`: sending "archived" archives the
     * ad; "live"/"scheduled"/"expired" un-archive it (the actual label is still derived
     * from the start/end window). Prefer the dedicated /archive and /reactivate endpoints
     * for a single-purpose action — this exists so the full edit form can set it inline.
     */
    public function rules(): array
    {
        return array_merge([
            'title' => ['sometimes', 'string', 'max:255'],
            'target_url' => ['sometimes', 'nullable', 'string', 'url', 'max:2048'],
            'image_interval_seconds' => ['sometimes', 'integer', 'min:1', 'max:'.AdService::MAX_INTERVAL_SECONDS],
            'starts_at' => ['sometimes', 'date'],
            'ends_at' => ['sometimes', 'date'],
            'is_active' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'nullable', Rule::in(['live', 'scheduled', 'expired', 'archived'])],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ], $this->imageSetRules());
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'title.max' => 'Ad title may not be longer than 255 characters.',
            'target_url.url' => 'Target URL must be a valid URL.',
            'target_url.max' => 'Target URL may not be longer than 2048 characters.',
            'image_interval_seconds.integer' => 'Seconds between images must be a whole number.',
            'image_interval_seconds.min' => 'Seconds between images must be at least 1 second.',
            'image_interval_seconds.max' => 'Seconds between images may not exceed '.AdService::MAX_INTERVAL_SECONDS.' seconds.',
            'starts_at.date' => 'Ad start date and time must be a valid date.',
            'ends_at.date' => 'Ad end date and time must be a valid date.',
            'is_active.boolean' => 'Active status must be true or false.',
            'status.in' => 'Ad status is invalid.',
            'sort_order.integer' => 'Sort order must be a whole number.',
        ], $this->imageSetMessages());
    }
}
