<?php

namespace App\Services\Admin\Campaign;

use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\Pledge;
use App\Services\Pledge\PledgeBalanceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class CampaignPledgeService
{
    public const MAX_EXPORT_ROWS = 5000;

    public function __construct(
        private readonly CampaignService $campaignService,
        private readonly PledgeBalanceService $balanceService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(string $campaignId, array $validated): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));
        $paginator = $this->baseListQuery($campaignId, $validated)->paginate($perPage);
        $this->hydrateBalanceAttributes($paginator->items());

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: EloquentCollection<int, Pledge>, 1: bool}
     */
    public function exportCollection(string $campaignId, array $validated): array
    {
        $query = $this->baseListQuery($campaignId, $validated);
        $total = (clone $query)->count();
        $truncated = $total > self::MAX_EXPORT_ROWS;
        /** @var EloquentCollection<int, Pledge> $rows */
        $rows = $query->limit(self::MAX_EXPORT_ROWS)->get();
        $this->hydrateBalanceAttributes($rows->all());

        return [$rows, $truncated];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return Builder<Pledge>
     */
    private function baseListQuery(string $campaignId, array $validated): Builder
    {
        $campaign = $this->campaignService->findCampaign($campaignId);

        $query = Pledge::query()
            ->where('campaign_uuid', $campaign->uuid)
            ->with([
                'donor:uuid,firstname,lastname,email,phone_number,graduation_set_uuid,donor_type_uuid',
                'donor.graduationSet:uuid,name,set_number',
                'donor.donorType:uuid,slug,label',
                'donorType:uuid,slug,label',
                'graduationSet:uuid,name,set_number',
            ]);

        ListingFilterRules::applyResolvedDateRange($query, $validated);

        $status = data_get($validated, 'filters.status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $userUuid = data_get($validated, 'filters.user_uuid');
        if (is_string($userUuid) && $userUuid !== '') {
            $query->where('user_uuid', $userUuid);
        }

        $currency = data_get($validated, 'filters.currency');
        if (is_string($currency) && $currency !== '') {
            $query->where('currency', strtoupper($currency));
        }

        $plan = data_get($validated, 'filters.payment_plan_type');
        if (is_string($plan) && $plan !== '') {
            $query->where('payment_plan_type', $plan);
        }

        $donorTypeUuid = data_get($validated, 'filters.donor_type_uuid');
        if (is_string($donorTypeUuid) && $donorTypeUuid !== '') {
            $query->where(function (Builder $builder) use ($donorTypeUuid): void {
                $builder->where('donor_type_uuid', $donorTypeUuid)
                    ->orWhereHas('donor', fn (Builder $donor) => $donor->where('donor_type_uuid', $donorTypeUuid));
            });
        }

        $setUuid = data_get($validated, 'filters.graduation_set_uuid');
        if (is_string($setUuid) && $setUuid !== '') {
            $query->where(function (Builder $builder) use ($setUuid): void {
                $builder->where('graduation_set_uuid', $setUuid)
                    ->orWhereHas('donor', fn (Builder $donor) => $donor->where('graduation_set_uuid', $setUuid));
            });
        }

        $anonymous = data_get($validated, 'filters.is_anonymous');
        if ($anonymous !== null && $anonymous !== '') {
            $truthy = in_array($anonymous, ['1', 1, true, 'true'], true);
            $query->where('is_anonymous', $truthy);
        }

        $minCommitted = data_get($validated, 'filters.min_committed_amount');
        if (is_numeric($minCommitted)) {
            $query->where('committed_amount_ngn', '>=', (float) $minCommitted);
        }

        $maxCommitted = data_get($validated, 'filters.max_committed_amount');
        if (is_numeric($maxCommitted)) {
            $query->where('committed_amount_ngn', '<=', (float) $maxCommitted);
        }

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$this->escapeLike($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('uuid', 'like', $like)
                    ->orWhere('donor_name', 'like', $like)
                    ->orWhere('donor_email', 'like', $like)
                    ->orWhere('donor_phone', 'like', $like)
                    ->orWhereHas('donor', function (Builder $donor) use ($like): void {
                        $donor->where('firstname', 'like', $like)
                            ->orWhere('lastname', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone_number', 'like', $like);
                    });
            });
        }

        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $allowedSorts = ['donor_name', 'committed_amount', 'committed_amount_ngn', 'status', 'created_at', 'updated_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }

        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortBy, $sortDirection);
    }

    /**
     * @param  list<Pledge>  $pledges
     */
    private function hydrateBalanceAttributes(array $pledges): void
    {
        foreach ($pledges as $pledge) {
            $pledge->setAttribute('fulfilled_amount', $this->balanceService->fulfilledAmount($pledge));
            $pledge->setAttribute('remaining_amount', $this->balanceService->remainingAmount($pledge));
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
