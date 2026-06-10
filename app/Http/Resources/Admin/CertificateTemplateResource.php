<?php

namespace App\Http\Resources;

use App\Models\CertificateTemplate;
use App\Support\CertificateDesignDefaults;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin CertificateTemplate
 */
class CertificateTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $this->resource->loadMissing('tier:uuid,name');
        $tier = $this->tier;

        return [
            'template_id' => $this->uuid,
            'name' => $this->name,
            'tier' => $tier !== null ? [
                'tier_id' => $tier->uuid,
                'name' => $tier->name,
            ] : null,
            'design' => is_array($this->design)
                ? CertificateDesignDefaults::sanitizeForStorage($this->design)
                : [],
            'status' => $this->is_active ? 'active' : 'inactive',
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
