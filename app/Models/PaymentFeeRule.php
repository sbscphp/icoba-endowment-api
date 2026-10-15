<?php

namespace App\Models;

use App\Traits\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One band of the service fee for a currency over an amount range.
 * Fee = amount × percentage + fixed, then capped. Gateway-independent.
 *
 * The cap may be kept in another currency (cap_currency, e.g. 2,000 NGN on every rule); it is
 * converted into the rule currency at quote time with the latest exchange rate.
 */
class PaymentFeeRule extends Model
{
    use HasUuid, SoftDeletes;

    protected $guarded = ['id', 'uuid'];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'percentage' => 'decimal:4',
            'fixed_amount' => 'decimal:2',
            'cap_amount' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<Transaction, $this>
     */
    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'fee_rule_uuid', 'uuid');
    }

    /**
     * @param  Builder<PaymentFeeRule>  $query
     * @return Builder<PaymentFeeRule>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param  Builder<PaymentFeeRule>  $query
     * @return Builder<PaymentFeeRule>
     */
    public function scopeForCurrency(Builder $query, string $currency): Builder
    {
        return $query->where('currency', strtoupper(trim($currency)));
    }

    /**
     * Rules whose [min_amount, max_amount] band contains the amount.
     *
     * @param  Builder<PaymentFeeRule>  $query
     * @return Builder<PaymentFeeRule>
     */
    public function scopeCoveringAmount(Builder $query, float $amount): Builder
    {
        return $query
            ->where('min_amount', '<=', $amount)
            ->where(function (Builder $builder) use ($amount): void {
                $builder->whereNull('max_amount')->orWhere('max_amount', '>=', $amount);
            });
    }

    /**
     * Frozen copy stored on the transaction so later edits to the rule do not rewrite history.
     *
     * @return array<string, mixed>
     */
    public function toSnapshot(): array
    {
        return [
            'uuid' => $this->uuid,
            'name' => $this->name,
            'currency' => $this->currency,
            'min_amount' => (string) $this->min_amount,
            'max_amount' => $this->max_amount !== null ? (string) $this->max_amount : null,
            'percentage' => (string) $this->percentage,
            'fixed_amount' => (string) $this->fixed_amount,
            'cap_amount' => $this->cap_amount !== null ? (string) $this->cap_amount : null,
            'cap_currency' => $this->cap_amount !== null ? $this->capCurrency() : null,
        ];
    }

    /**
     * Currency the cap is written in; defaults to the rule currency.
     */
    public function capCurrency(): string
    {
        $capCurrency = trim((string) ($this->cap_currency ?? ''));

        return strtoupper($capCurrency !== '' ? $capCurrency : (string) $this->currency);
    }
}
