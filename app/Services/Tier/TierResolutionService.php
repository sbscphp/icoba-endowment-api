<?php

namespace App\Services\Tier;

use App\Models\TierConfiguration;
use Illuminate\Database\Eloquent\Builder;

class TierResolutionService
{
    public const UNCATEGORIZED_LABEL = 'Uncategorized';

    /**
     * Resolve the matching active tier for a NGN-equivalent amount, if any.
     */
    public function resolveTierForAmount(?float $amountInNaira): ?TierConfiguration
    {
        if ($amountInNaira === null) {
            return null;
        }

        return TierConfiguration::query()
            ->where('is_active', true)
            ->where('min_amount', '<=', $amountInNaira)
            ->where(function (Builder $builder) use ($amountInNaira): void {
                $builder->whereNull('max_amount')
                    ->orWhere('max_amount', '>=', $amountInNaira);
            })
            ->orderBy('sort_order')
            ->first();
    }

    /**
     * Human label for display (leaderboard, receipts). Uses tier name or "Uncategorized".
     */
    public function resolveDisplayLabelForAmount(?float $amountInNaira): string
    {
        $tier = $this->resolveTierForAmount($amountInNaira);

        return $tier !== null ? (string) $tier->name : self::UNCATEGORIZED_LABEL;
    }

    /**
     * Cumulative paid total in NGN for tier (e.g. leaderboard).
     */
    public function resolveDisplayLabelForCumulativeAmount(float $cumulativeAmountInNaira): string
    {
        return $this->resolveDisplayLabelForAmount($cumulativeAmountInNaira);
    }
}
