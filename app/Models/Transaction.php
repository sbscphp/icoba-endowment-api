<?php

namespace App\Models;

use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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
            'application_type' => TransactionApplicationType::class,
            'metadata' => 'array',
            'paid_at' => 'datetime',
            'awaiting_bank_verification_at' => 'datetime',
            'reconciled_at' => 'datetime',
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

    public function givingIdentity(): BelongsTo
    {
        return $this->belongsTo(GivingIdentity::class, 'giving_identity_uuid', 'uuid');
    }

    public function pledge(): BelongsTo
    {
        return $this->belongsTo(Pledge::class, 'pledge_uuid', 'uuid');
    }

    public function supersededBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'superseded_by_transaction_uuid', 'uuid');
    }

    public function donorType(): BelongsTo
    {
        return $this->belongsTo(DonorType::class, 'donor_type_uuid', 'uuid');
    }

    public function receipt(): HasOne
    {
        return $this->hasOne(TransactionReceipt::class, 'transaction_uuid', 'uuid');
    }

    public function reconciledByAdmin(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'reconciled_by_admin_uuid', 'uuid');
    }

    public function certificates(): HasMany
    {
        return $this->hasMany(DonorRecognition::class, 'trigger_transaction_uuid', 'uuid');
    }

    /**
     * Successful cash movements that count toward campaign revenue and leaderboards.
     *
     * @param  Builder<Transaction>  $query
     * @return Builder<Transaction>
     */
    public function scopeCountableTowardRevenue(Builder $query): Builder
    {
        $table = $query->getModel()->getTable();

        return $query
            ->where($table.'.status', TransactionStatus::SUCCESSFUL)
            ->where(function (Builder $b) use ($table): void {
                $b->whereNull($table.'.application_type')
                    ->orWhere(
                        $table.'.application_type',
                        '!=',
                        TransactionApplicationType::PLEDGE_PLACEHOLDER->value
                    );
            });
    }
}
