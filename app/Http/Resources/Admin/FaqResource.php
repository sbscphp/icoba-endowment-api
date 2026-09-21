<?php

namespace App\Http\Resources\Admin;

use App\Models\Faq;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Faq
 */
class FaqResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'faq_id' => $this->uuid,
            'title' => $this->title,
            'content' => $this->content,
            'sort_order' => (int) $this->sort_order,
            'is_active' => (bool) $this->is_active,
            'status' => $this->is_active ? 'active' : 'inactive',
            'updated_by' => $this->whenLoaded('updatedByAdmin', fn () => $this->updatedByAdmin?->displayName()),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
