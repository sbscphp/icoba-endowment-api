<?php

namespace App\Http\Resources;

use App\Models\CertificateTemplate;
use App\Support\CertificateDesignDefaults;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificateTemplate
 */
class CertificateTemplateListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('tier:uuid,name');
        $design = is_array($this->design)
            ? CertificateDesignDefaults::sanitizeForStorage($this->design)
            : [];

        return [
            'template_id' => $this->uuid,
            'name' => $this->name,
            'tier' => $this->tier !== null ? [
                'tier_id' => $this->tier->uuid,
                'name' => $this->tier->name,
            ] : null,
            'linked_tier_name' => $this->tier?->name,
            'tier_id' => $this->tier?->uuid,
            'design' => $design,
            'status' => $this->is_active ? 'active' : 'inactive',
            'last_updated' => $this->updated_at,
        ];
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
