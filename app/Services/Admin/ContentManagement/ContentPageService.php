<?php

namespace App\Services\Admin\ContentManagement;

use App\Enums\ContentPage;
use App\Models\HeroSlide;

class ContentPageService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listPages(): array
    {
        return [
            $this->heroSliderPageSummary(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function heroSliderPageSummary(): array
    {
        $latestSlide = HeroSlide::query()
            ->with('updatedByAdmin')
            ->orderByDesc('updated_at')
            ->first();

        $hasActiveSlide = HeroSlide::query()->where('is_active', true)->exists();

        return [
            'page_key' => ContentPage::HERO_SLIDER->value,
            'page_title' => ContentPage::HERO_SLIDER->label(),
            'last_updated' => $latestSlide?->updated_at,
            'updated_by' => $latestSlide?->updatedByAdmin?->displayName(),
            'status' => $hasActiveSlide ? 'active' : 'inactive',
        ];
    }
}
