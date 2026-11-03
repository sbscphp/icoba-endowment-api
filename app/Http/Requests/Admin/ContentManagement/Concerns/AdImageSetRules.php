<?php

namespace App\Http\Requests\Admin\ContentManagement\Concerns;

use App\Enums\AdImageOrientation;
use App\Services\Admin\ContentManagement\AdService;
use Illuminate\Contracts\Validation\Validator;

/**
 * Shared validation for an ad's per-orientation image lists.
 *
 * An ad carries two galleries: `landscape_images` (wide slots) and `portrait_images`
 * (tall slots). Each list is the full desired gallery for that orientation: existing
 * Cloudinary URLs to keep plus new base64/file entries to upload. A list that is sent is
 * reconciled exactly (so an empty list clears that orientation); a list that is omitted
 * leaves that orientation untouched. The legacy `images` key is still accepted and means
 * `landscape_images`.
 */
trait AdImageSetRules
{
    /**
     * @return array<string, array<int, string>>
     */
    protected function imageSetRules(): array
    {
        $rules = [
            'images' => ['sometimes', 'array', 'max:'.AdService::MAX_IMAGES],
            'images.*' => ['required'],
        ];

        foreach (AdImageOrientation::cases() as $orientation) {
            $key = $orientation->payloadKey();
            $rules[$key] = ['sometimes', 'array', 'max:'.AdService::MAX_IMAGES];
            $rules[$key.'.*'] = ['required'];
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    protected function imageSetMessages(): array
    {
        $messages = [
            'images.array' => 'Images must be provided as a list.',
            'images.max' => 'You may have at most '.AdService::MAX_IMAGES.' landscape images per ad.',
            'images.*.required' => 'Each image entry must be a URL or an upload.',
        ];

        foreach (AdImageOrientation::cases() as $orientation) {
            $key = $orientation->payloadKey();
            $messages[$key.'.array'] = ucfirst($orientation->value).' images must be provided as a list.';
            $messages[$key.'.max'] = 'You may have at most '.AdService::MAX_IMAGES.' '.$orientation->value.' images per ad.';
            $messages[$key.'.*.required'] = 'Each '.$orientation->value.' image entry must be a URL or an upload.';
        }

        return $messages;
    }

    /**
     * @return list<string>
     */
    protected function imageSetKeys(): array
    {
        return ['images', ...array_map(fn (AdImageOrientation $o): string => $o->payloadKey(), AdImageOrientation::cases())];
    }

    protected function hasAnyImageSet(): bool
    {
        foreach ($this->imageSetKeys() as $key) {
            if ($this->has($key)) {
                return true;
            }
        }

        return false;
    }

    protected function hasAnyImageEntry(): bool
    {
        foreach ($this->imageSetKeys() as $key) {
            $value = $this->input($key);
            if (is_array($value) && $value !== []) {
                return true;
            }
        }

        return false;
    }

    protected function requireAtLeastOneImageEntry(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if (! $this->hasAnyImageEntry()) {
                $validator->errors()->add('landscape_images', 'At least one landscape or portrait ad image is required.');
            }
        });
    }
}
