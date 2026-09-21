<?php

namespace App\Services\Public;

use App\Models\Faq;
use Illuminate\Database\Eloquent\Collection;

class PublicFaqService
{
    /**
     * @return Collection<int, Faq>
     */
    public function listActive(): Collection
    {
        return Faq::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('title')
            ->get();
    }
}
