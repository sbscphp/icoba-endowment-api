<?php

namespace App\Services\Public;

use App\Enums\CampaignStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

final class PublicCampaignVisibility
{
    public static function today(): CarbonInterface
    {
        return today((string) config('app.timezone'));
    }

    /**
     * @param  Builder<\App\Models\Campaign>  $query
     */
    public static function applyToEloquent(Builder $query, bool $forAuthenticatedCustomer = false, string $table = 'campaigns'): void
    {
        $today = self::today()->toDateString();
        $column = static fn (string $field): string => $table === '' ? $field : "{$table}.{$field}";

        if (! $forAuthenticatedCustomer) {
            $query->where($column('allow_public_donation'), true);
        }

        $query
            ->where($column('status'), '!=', CampaignStatus::DRAFT)
            ->where($column('status'), '!=', CampaignStatus::DEACTIVATED)
            ->whereDate($column('start_date'), '<=', $today)
            ->whereDate($column('end_date'), '>=', $today);
    }

    public static function applyToQuery(QueryBuilder $query, bool $forAuthenticatedCustomer = false, string $table = 'campaigns'): void
    {
        $today = self::today()->toDateString();
        $column = static fn (string $field): string => "{$table}.{$field}";

        if (! $forAuthenticatedCustomer) {
            $query->where($column('allow_public_donation'), true);
        }

        $query
            ->where($column('status'), '!=', CampaignStatus::DRAFT->value)
            ->where($column('status'), '!=', CampaignStatus::DEACTIVATED->value)
            ->whereDate($column('start_date'), '<=', $today)
            ->whereDate($column('end_date'), '>=', $today);
    }
}
