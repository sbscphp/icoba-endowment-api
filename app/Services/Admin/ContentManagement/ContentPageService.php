<?php

namespace App\Services\Admin\ContentManagement;

use App\Enums\ContentPage;
use App\Enums\EventStatus;
use App\Models\AboutSection;
use App\Models\Ad;
use App\Models\Event;
use App\Models\Faq;
use App\Models\HeroSlide;
use Illuminate\Support\Carbon;

class ContentPageService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listPages(): array
    {
        return [
            $this->heroSliderPageSummary(),
            $this->eventsPageSummary(),
            $this->adsPageSummary(),
            $this->faqPageSummary(),
            $this->aboutUsPageSummary(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function aboutUsPageSummary(): array
    {
        $latestSection = AboutSection::query()
            ->with('updatedByAdmin')
            ->whereNotNull('updated_at')
            ->orderByDesc('updated_at')
            ->first();

        $hasActiveSection = AboutSection::query()->where('is_active', true)->exists();

        return [
            'page_key' => ContentPage::ABOUT_US->value,
            'page_title' => ContentPage::ABOUT_US->label(),
            'last_updated' => $latestSection?->updated_at,
            'updated_by' => $latestSection?->updatedByAdmin?->displayName(),
            'status' => $hasActiveSection ? 'active' : 'inactive',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function faqPageSummary(): array
    {
        $latestFaq = Faq::query()
            ->with('updatedByAdmin')
            ->orderByDesc('updated_at')
            ->first();

        $hasActiveFaq = Faq::query()->where('is_active', true)->exists();

        return [
            'page_key' => ContentPage::FAQ->value,
            'page_title' => ContentPage::FAQ->label(),
            'last_updated' => $latestFaq?->updated_at,
            'updated_by' => $latestFaq?->updatedByAdmin?->displayName(),
            'status' => $hasActiveFaq ? 'active' : 'inactive',
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

    /**
     * @return array<string, mixed>
     */
    private function eventsPageSummary(): array
    {
        $latestEvent = Event::query()
            ->with('updater')
            ->orderByDesc('updated_at')
            ->first();

        $hasPublishedEvent = Event::query()->where('status', EventStatus::PUBLISHED->value)->exists();

        return [
            'page_key' => ContentPage::EVENTS->value,
            'page_title' => ContentPage::EVENTS->label(),
            'last_updated' => $latestEvent?->updated_at,
            'updated_by' => $latestEvent?->updater?->displayName(),
            'status' => $hasPublishedEvent ? 'active' : 'inactive',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adsPageSummary(): array
    {
        $latestAd = Ad::query()
            ->with('updater')
            ->orderByDesc('updated_at')
            ->first();

        $now = Carbon::now();
        $hasLiveAd = Ad::query()
            ->where('is_active', true)
            ->where('starts_at', '<=', $now)
            ->where('ends_at', '>=', $now)
            ->exists();

        return [
            'page_key' => ContentPage::ADS->value,
            'page_title' => ContentPage::ADS->label(),
            'last_updated' => $latestAd?->updated_at,
            'updated_by' => $latestAd?->updater?->displayName(),
            'status' => $hasLiveAd ? 'active' : 'inactive',
        ];
    }
}
