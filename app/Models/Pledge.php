<?php

namespace App\Models;

use App\Enums\PledgePaymentPlanType;
use App\Enums\PledgeStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Pledge extends Model
{
    use HasUuid, SoftDeletes;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'committed_amount' => 'decimal:2',
            'committed_amount_ngn' => 'decimal:2',
            'exchange_rate_to_naira' => 'decimal:6',
            'is_anonymous' => 'boolean',
            'payment_plan_type' => PledgePaymentPlanType::class,
            'status' => PledgeStatus::class,
            'schedule' => 'array',
            'metadata' => 'array',
            'fulfilled_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_uuid', 'uuid');
    }

    public function donor(): BelongsTo
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

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'pledge_uuid', 'uuid');
    }
}
