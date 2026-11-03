<?php

namespace App\Models;

use App\Enums\AdImageOrientation;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdImage extends Model
{
    use HasUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'orientation' => AdImageOrientation::class,
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Ad, $this>
     */
    public function ad(): BelongsTo
    {
        return $this->belongsTo(Ad::class, 'ad_uuid', 'uuid');
    }

    /**
     * @param  Builder<AdImage>  $query
     */
    public function scopeOrientation(Builder $query, AdImageOrientation $orientation): void
    {
        $query->where('orientation', $orientation->value);
    }
}
