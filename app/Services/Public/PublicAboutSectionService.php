<?php

namespace App\Services\Public;

use App\Enums\AboutSectionKey;
use App\Models\AboutSection;

class PublicAboutSectionService
{
    /**
     * Content of every section keyed by section_key; null when inactive, never saved, or (overview) outside its date window.
     *
     * @return array<string, array<string, mixed>|null>
     */
    public function liveContent(): array
    {
        $sections = AboutSection::query()->get()->keyBy('section_key');
        $today = now()->toDateString();

        $result = [];
        foreach (AboutSectionKey::cases() as $key) {
            /** @var AboutSection|null $section */
            $section = $sections->get($key->value);
            $content = $section !== null && (bool) $section->is_active ? $section->content : null;

            if ($content !== null && $key === AboutSectionKey::OVERVIEW
                && ($today < ($content['start_date'] ?? '') || $today > ($content['end_date'] ?? ''))) {
                $content = null;
            }

            $result[$key->value] = $content;
        }

        return $result;
    }
}
