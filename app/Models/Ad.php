<?php

namespace App\Models;

use App\Enums\AdImageOrientation;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Ad extends Model
{
    use HasUuid, SoftDeletes;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'image_interval_seconds' => 'integer',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * Every image on the ad, grouped by orientation (landscape first) and then in rotation order.
     *
     * @return HasMany<AdImage, $this>
     */
    public function images(): HasMany
    {
        return $this->hasMany(AdImage::class, 'ad_uuid', 'uuid')->orderBy('orientation')->orderBy('sort_order');
    }

    /**
     * @return HasMany<AdImage, $this>
     */
    public function landscapeImages(): HasMany
    {
        return $this->images()->where('orientation', AdImageOrientation::LANDSCAPE->value);
    }

    /**
     * @return HasMany<AdImage, $this>
     */
    public function portraitImages(): HasMany
    {
        return $this->images()->where('orientation', AdImageOrientation::PORTRAIT->value);
    }

    /**
     * Filters the already-loaded `images` relation down to one orientation, keeping rotation order.
     *
     * @return Collection<int, AdImage>
     */
    public function imagesFor(AdImageOrientation $orientation): Collection
    {
        /** @var Collection<int, AdImage> $filtered */
        $filtered = $this->images
            ->filter(fn (AdImage $image): bool => $image->orientation === $orientation)
            ->values();

        return $filtered;
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by_admin_uuid', 'uuid');
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by_admin_uuid', 'uuid');
    }

    /**
     * Guest-facing visibility derived from the archive flag and the start/end window.
     */
    public function derivedStatus(): string
    {
        if (! $this->is_active) {
            return 'archived';
        }

        $now = Carbon::now();
        if ($this->starts_at !== null && $now->lessThan($this->starts_at)) {
            return 'scheduled';
        }

        if ($this->ends_at !== null && $now->greaterThan($this->ends_at)) {
            return 'expired';
        }

        return 'live';
    }
}
