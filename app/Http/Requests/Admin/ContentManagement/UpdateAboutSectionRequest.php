<?php

namespace App\Http\Requests\Admin\ContentManagement;

use App\Enums\AboutSectionKey;
use App\Http\Requests\ApiFormRequest;
use Closure;

class UpdateAboutSectionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return match (AboutSectionKey::tryFrom((string) $this->route('sectionKey'))) {
            AboutSectionKey::OVERVIEW => [
                'section_tag' => ['required', 'string'],
                'section_title' => ['required', 'string'],
                'body' => ['required', 'string'],
                'start_date' => ['required', 'date_format:Y-m-d'],
                'end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:start_date'],
                'image' => ['required', 'string'],
            ],
            AboutSectionKey::VISION_MISSION => [
                'section_tag' => ['required', 'string'],
                'section_title' => ['required', 'string'],
                'sub_text' => ['nullable', 'string'],
                'vision' => ['required', 'array'],
                'vision.title' => ['required', 'string'],
                'vision.body' => ['required', 'string'],
                'mission' => ['required', 'array'],
                'mission.title' => ['required', 'string'],
                'mission.body' => ['required', 'string'],
            ],
            AboutSectionKey::IMPLEMENTATION_PLAN => [
                'section_tag' => ['required', 'string'],
                'section_title' => ['required', 'string'],
                'sub_text' => ['nullable', 'string'],
                'plans' => ['required', 'array', 'min:1'],
                'plans.*' => ['array'],
                'plans.*.title' => ['required', 'string'],
                'plans.*.body' => ['required', 'string'],
            ],
            AboutSectionKey::PDF_VIEWER => [
                'document_link' => ['bail', 'required', 'string', function (string $attribute, mixed $value, Closure $fail): void {
                    $isLink = str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
                    if ($isLink && filter_var($value, FILTER_VALIDATE_URL) === false) {
                        $fail('Enter a valid URL starting with http:// or https://.');
                    }
                }],
            ],
            // Unknown keys fall through to the service, which returns 404.
            null => [],
        };
    }

    public function messages(): array
    {
        return array_merge(parent::messages(), [
            'required' => ':Attribute is required.',
            'start_date.date_format' => 'Start date must be in YYYY-MM-DD format.',
            'end_date.date_format' => 'Expiration date must be in YYYY-MM-DD format.',
            'end_date.after_or_equal' => 'Expiration date must be on or after the start date.',
            'plans.min' => 'At least one implementation plan is required.',
        ]);
    }

    public function attributes(): array
    {
        return [
            'section_tag' => 'section tag',
            'section_title' => 'section title',
            'body' => 'section body content',
            'start_date' => 'start date',
            'end_date' => 'expiration date',
            'image' => 'section image',
            'sub_text' => 'section sub-text',
            'vision' => 'vision',
            'vision.title' => 'vision title',
            'vision.body' => 'vision body content',
            'mission' => 'mission',
            'mission.title' => 'mission title',
            'mission.body' => 'mission body content',
            'plans' => 'implementation plans',
            'plans.*.title' => 'plan title',
            'plans.*.body' => 'plan body content',
            'document_link' => 'document link',
        ];
    }
}
