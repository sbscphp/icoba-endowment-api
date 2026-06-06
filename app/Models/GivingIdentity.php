<?php

namespace App\Models;

use App\Enums\GivingIdentitySource;
use App\Enums\GivingIdentityStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GivingIdentity extends Model
{
    use HasUuid;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'status' => GivingIdentityStatus::class,
            'source' => GivingIdentitySource::class,
            'locked_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }

    public function donorType(): BelongsTo
    {
        return $this->belongsTo(DonorType::class, 'donor_type_uuid', 'uuid');
    }

    public function graduationSet(): BelongsTo
    {
        return $this->belongsTo(GraduationSet::class, 'graduation_set_uuid', 'uuid');
    }

    public function corporateCategory(): BelongsTo
    {
        return $this->belongsTo(CorporateCategory::class, 'corporate_category_uuid', 'uuid');
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'giving_identity_uuid', 'uuid');
    }

    /**
     * @return HasMany<Pledge, $this>
     */
    public function pledges(): HasMany
    {
        return $this->hasMany(Pledge::class, 'giving_identity_uuid', 'uuid');
    }

    public function isLocked(): bool
    {
        return $this->locked_at !== null;
    }
}
