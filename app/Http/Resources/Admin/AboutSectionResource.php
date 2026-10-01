<?php

namespace App\Http\Resources\Admin;

use App\Enums\AboutSectionKey;
use App\Models\AboutSection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin AboutSection
 */
class AboutSectionResource extends JsonResource
{
    private bool $withContent = true;

    public function withoutContent(): static
    {
        $this->withContent = false;

        return $this;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $data = [
            'section_key' => $this->section_key,
            'section_name' => AboutSectionKey::tryFrom((string) $this->section_key)?->label(),
            'is_active' => (bool) $this->is_active,
            'last_updated' => $this->updated_at,
            'updated_by' => $this->whenLoaded('updatedByAdmin', fn () => $this->updatedByAdmin?->displayName()),
        ];

        if ($this->withContent) {
            $data['content'] = $this->content;
        }

        return $data;
    }
}
