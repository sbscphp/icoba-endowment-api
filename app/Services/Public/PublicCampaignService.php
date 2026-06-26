<?php

namespace App\Services\Public;

use App\Enums\CampaignStatus;
use App\Enums\PledgeStatus;
use App\Enums\PublicCampaignVisibilityFilter;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Campaign;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class PublicCampaignService
{
    /** @var list<CampaignStatus> */
    private const CLOSED_STATUSES = [
        CampaignStatus::COMPLETED,
        // CampaignStatus::DEACTIVATED, //Public should not see deactivated admin campaigns
        // CampaignStatus::PAUSED, //Public should not see paused campaigns
    ];

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 15), 100));

        return $this->baseQuery($filters)
            ->orderByDesc('updated_at')
            ->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Collection<int, Campaign>
     */
    public function dropdown(array $filters): Collection
    {
        return $this->baseQuery($filters)
            ->where('status', CampaignStatus::ACTIVE)
            ->orderBy('name')
            ->get(['uuid', 'name']);
    }

    public function find(string $uuid, bool $forAuthenticatedCustomer = false): Campaign
    {
        $query = Campaign::query()->where('uuid', $uuid);
        $this->applyPublicVisibilityConstraints($query, $forAuthenticatedCustomer);
        $this->applyFinancialAggregates($query);

        return $query->firstOrFail();
    }

    /**
     * @param  Builder<Campaign>  $query
     */
    private function applyPublicVisibilityConstraints(Builder $query, bool $forAuthenticatedCustomer = false): void
    {
        PublicCampaignVisibility::applyToEloquent($query, $forAuthenticatedCustomer);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<Campaign>
     */
    private function baseQuery(array $filters): Builder
    {
        $query = Campaign::query();
        $forAuthenticatedCustomer = (bool) ($filters['for_authenticated_customer'] ?? false);
        $this->applyPublicVisibilityConstraints($query, $forAuthenticatedCustomer);

        $filter = PublicCampaignVisibilityFilter::tryFrom((string) ($filters['filter'] ?? ''))
            ?? PublicCampaignVisibilityFilter::ALL;
        $this->applyVisibilityFilter($query, $filter);
        $this->applyFinancialAggregates($query);

        $search = trim((string) ($filters['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$this->escapeLike($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('name', 'like', $like)
                    ->orWhere('campaign_id', 'like', $like)
                    ->orWhere('short_description', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @param  Builder<Campaign>  $query
     */
    private function applyVisibilityFilter(Builder $query, PublicCampaignVisibilityFilter $filter): void
    {
        match ($filter) {
            PublicCampaignVisibilityFilter::ACTIVE => $query->where('status', CampaignStatus::ACTIVE),
            PublicCampaignVisibilityFilter::CLOSED => $query->whereIn('status', array_map(
                fn (CampaignStatus $status): string => $status->value,
                self::CLOSED_STATUSES,
            )),
            PublicCampaignVisibilityFilter::ALL => null,
        };
    }

    /**
     * @param  Builder<Campaign>  $query
     */
    private function applyFinancialAggregates(Builder $query): void
    {
        $query->withSum([
            'transactions as amount_realized_ngn' => function (Builder $q): void {
                $q->where('status', TransactionStatus::SUCCESSFUL)
                    ->where(function (Builder $b): void {
                        $b->whereNull('application_type')
                            ->orWhere(
                                'application_type',
                                '!=',
                                TransactionApplicationType::PLEDGE_PLACEHOLDER->value
                            );
                    });
            },
        ], 'amount_in_naira');

        $query->withSum([
            'pledges as donation_pledge_ngn' => function (Builder $q): void {
                $q->where('status', '!=', PledgeStatus::CANCELLED);
            },
        ], 'committed_amount_ngn');
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
