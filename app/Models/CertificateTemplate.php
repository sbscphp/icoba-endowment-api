<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CertificateTemplate extends Model
{
    use HasUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'design' => 'array',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TierConfiguration, $this>
     */
    public function tier(): BelongsTo
    {
        return $this->belongsTo(TierConfiguration::class, 'tier_uuid', 'uuid');
    }
}
