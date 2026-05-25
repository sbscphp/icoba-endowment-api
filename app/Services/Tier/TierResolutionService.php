<?php

namespace App\Services\Tier;

use App\Models\TierConfiguration;
use Illuminate\Database\Eloquent\Builder;

class TierResolutionService
{
    public const UNCATEGORIZED_LABEL = 'Uncategorized';

    public const DEFAULT_TIER_NAME = 'Friends of Igbobi College';

    /**
     * Resolve the matching active tier for a NGN-equivalent amount, if any.
     */
    public function resolveTierForAmount(?float $amountInNaira): ?TierConfiguration
    {
        $amountInNaira = $amountInNaira ?? 0.0;

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

    /**
     * All active tiers the cumulative NGN total qualifies for, lowest to highest.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, TierConfiguration>
     */
    public function resolveQualifiedTiersForCumulativeAmount(float $cumulativeAmountInNaira): \Illuminate\Database\Eloquent\Collection
    {
        return TierConfiguration::query()
            ->where('is_active', true)
            ->where('min_amount', '<=', max(0, $cumulativeAmountInNaira))
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @return array{name: string, tier_badge_url: string|null, amount_ngn: string}
     */
    public function resolveSummaryForAmount(float $amountInNaira): array
    {
        $tier = $this->resolveTierForAmount($amountInNaira);

        return [
            'name' => $tier?->name ?? ($amountInNaira <= 0 ? self::DEFAULT_TIER_NAME : self::UNCATEGORIZED_LABEL),
            'tier_badge_url' => $tier?->tier_badge_url,
            'amount_ngn' => $this->formatNgnAmount($amountInNaira),
        ];
    }

    private function formatNgnAmount(float $amountInNaira): string
    {
        return number_format(max(0, $amountInNaira), 2, '.', '');
    }
}
