<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class TierConfiguration extends Model
{
    use HasUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'benefits' => 'array',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CertificateTemplate, $this>
     */
    public function certificateTemplates(): HasMany
    {
        return $this->hasMany(CertificateTemplate::class, 'tier_uuid', 'uuid');
    }
}
