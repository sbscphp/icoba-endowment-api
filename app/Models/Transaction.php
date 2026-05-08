<?php

namespace App\Models;

use App\Enums\TransactionStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class Transaction extends Model
{
    use HasUuid, SoftDeletes;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'exchange_rate_to_naira' => 'decimal:6',
            'amount_in_naira' => 'decimal:2',
            'is_anonymous' => 'boolean',
            'status' => TransactionStatus::class,
            'metadata' => 'array',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Transaction $transaction): void {
            if ($transaction->campaign_uuid === null || $transaction->campaign_uuid === '') {
                $transaction->campaign_uuid = Campaign::defaultCampaign()->uuid;
            }
        });
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class, 'campaign_uuid', 'uuid');
    }

    public function donor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_uuid', 'uuid');
    }
}
