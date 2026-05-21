<?php

namespace App\Services\Admin\Report;

use App\Enums\ReportType;
use App\Models\Admin;
use App\Models\Campaign;
use App\Models\CampaignEmail;
use App\Models\Pledge;
use App\Models\Role;
use App\Models\TierConfiguration;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Pagination\LengthAwarePaginator;

class ReportService
{
    public const MAX_EXPORT_ROWS = 5000;

    /**
     * @return list<array{value:string,label:string,filter_keys:list<string>,sort_keys:list<string>}>
     */
    public function reportTypeOptions(): array
    {
        return collect(ReportType::cases())
            ->map(fn (ReportType $type): array => [
                'value' => $type->value,
                'label' => $type->label(),
                'filter_keys' => $this->filterKeysFor($type),
                'sort_keys' => $this->sortKeysFor($type),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{headers:list<string>,paginator:LengthAwarePaginator}
     */
    public function paginated(array $validated): array
    {
        $type = ReportType::from((string) $validated['report_type']);
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));
        $query = $this->baseQuery($type, $validated);

        return [
            'headers' => $this->headersFor($type),
            'paginator' => $query->paginate($perPage)->through(fn ($row) => $this->transformRow($type, $row)),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{headers:list<string>,rows:list<array<int, mixed>>,truncated:bool}
     */
    public function exportRows(array $validated): array
    {
        $type = ReportType::from((string) $validated['report_type']);
        $query = $this->baseQuery($type, $validated);
        $total = (clone $query)->count();
        $truncated = $total > self::MAX_EXPORT_ROWS;
        /** @var EloquentCollection<int, mixed> $rows */
        $rows = $query->limit(self::MAX_EXPORT_ROWS)->get();

        return [
            'headers' => $this->headersFor($type),
            'rows' => $rows->map(fn ($row): array => $this->rowValuesForExport($type, $row))->values()->all(),
            'truncated' => $truncated,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseQuery(ReportType $type, array $validated): Builder
    {
        $query = match ($type) {
            ReportType::TRANSACTIONS => Transaction::query()->with(['campaign:uuid,name,campaign_id', 'donor:uuid,firstname,lastname,email']),
            ReportType::TIER_CONFIGURATIONS => TierConfiguration::query(),
            ReportType::ADMIN_USERS => Admin::query()->with('roles:id,name'),
            ReportType::ROLES => Role::query()->where('guard_name', 'api')->withCount('admins as users_count'),
            ReportType::CAMPAIGNS => Campaign::query(),
            ReportType::EMAIL_CAMPAIGNS => CampaignEmail::query()->with('campaign:uuid,name,campaign_id'),
            ReportType::PLEDGES => Pledge::query()->with(['campaign:uuid,name,campaign_id', 'donor:uuid,firstname,lastname,email']),
        };

        $this->applyDateRange($query, $validated);
        $this->applySearch($query, $type, $validated);
        $this->applyFilters($query, $type, $validated);
        $this->applySort($query, $type, $validated);

        return $query;
    }

    /**
     * @return array<string,mixed>
     */
    private function transformRow(ReportType $type, mixed $row): array
    {
        return match ($type) {
            ReportType::TRANSACTIONS => [
                'id' => $row->transaction_id,
                'date' => $row->created_at?->toDateTimeString(),
                'donor_name' => (bool) $row->is_anonymous ? 'Anonymous' : ($row->donor_name ?? trim((string) (($row->donor?->firstname ?? '').' '.($row->donor?->lastname ?? '')))),
                'donor_email' => $row->donor_email ?? $row->donor?->email,
                'campaign' => $row->campaign?->name,
                'amount' => (string) $row->amount,
                'currency' => $row->currency,
                'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
            ],
            ReportType::TIER_CONFIGURATIONS => [
                'name' => $row->name,
                'min_amount' => (string) $row->min_amount,
                'max_amount' => $row->max_amount !== null ? (string) $row->max_amount : null,
                'sort_order' => (int) $row->sort_order,
                'active' => (bool) $row->is_active,
                'created_at' => $row->created_at,
            ],
            ReportType::ADMIN_USERS => [
                'name' => $row->name,
                'email' => $row->email,
                'role' => $row->roles->pluck('name')->implode(', '),
                'active' => (bool) $row->is_active,
                'last_active_at' => $row->last_active_at,
                'created_at' => $row->created_at,
            ],
            ReportType::ROLES => [
                'name' => $row->name,
                'description' => $row->description,
                'users_count' => (int) ($row->users_count ?? 0),
                'active' => (bool) $row->is_active,
                'created_at' => $row->created_at,
            ],
            ReportType::CAMPAIGNS => [
                'campaign_id' => $row->campaign_id,
                'name' => $row->name,
                'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
                'target_amount' => (string) $row->target_amount,
                'base_currency' => $row->base_currency,
                'created_at' => $row->created_at,
            ],
            ReportType::EMAIL_CAMPAIGNS => [
                'title' => $row->title,
                'campaign' => $row->campaign?->name,
                'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
                'is_active' => (bool) $row->is_active,
                'recipients' => (int) ($row->total_recipients ?? 0),
                'created_at' => $row->created_at,
            ],
            ReportType::PLEDGES => [
                'uuid' => $row->uuid,
                'donor_name' => (bool) $row->is_anonymous ? 'Anonymous' : ($row->donor_name ?? trim((string) (($row->donor?->firstname ?? '').' '.($row->donor?->lastname ?? '')))),
                'donor_email' => $row->donor_email ?? $row->donor?->email,
                'donor_phone' => $row->donor_phone,
                'campaign' => $row->campaign?->name,
                'committed_amount' => (string) $row->committed_amount,
                'currency' => $row->currency,
                'committed_amount_ngn' => $row->committed_amount_ngn !== null ? (string) $row->committed_amount_ngn : '',
                'exchange_rate_to_naira' => $row->exchange_rate_to_naira !== null ? (string) $row->exchange_rate_to_naira : '',
                'payment_plan_type' => $row->payment_plan_type instanceof \BackedEnum ? $row->payment_plan_type->value : $row->payment_plan_type,
                'installment_count' => $row->installment_count,
                'status' => $row->status instanceof \BackedEnum ? $row->status->value : $row->status,
                'is_anonymous' => (bool) $row->is_anonymous,
                'created_at' => $row->created_at,
            ],
        };
    }

    /**
     * @return list<mixed>
     */
    private function rowValuesForExport(ReportType $type, mixed $row): array
    {
        $data = $this->transformRow($type, $row);

        return array_values($data);
    }

    /**
     * @return list<string>
     */
    private function headersFor(ReportType $type): array
    {
        return match ($type) {
            ReportType::TRANSACTIONS => ['ID', 'Date', 'Donor Name', 'Donor Email', 'Campaign', 'Amount', 'Currency', 'Status'],
            ReportType::TIER_CONFIGURATIONS => ['Name', 'Min Amount', 'Max Amount', 'Sort Order', 'Active', 'Created At'],
            ReportType::ADMIN_USERS => ['Name', 'Email', 'Role', 'Active', 'Last Active At', 'Created At'],
            ReportType::ROLES => ['Name', 'Description', 'Users Count', 'Active', 'Created At'],
            ReportType::CAMPAIGNS => ['Campaign ID', 'Name', 'Status', 'Target Amount', 'Base Currency', 'Created At'],
            ReportType::EMAIL_CAMPAIGNS => ['Title', 'Campaign', 'Status', 'Is Active', 'Recipients', 'Created At'],
            ReportType::PLEDGES => ['UUID', 'Donor Name', 'Donor Email', 'Donor Phone', 'Campaign', 'Committed Amount', 'Currency', 'Committed Amount (NGN)', 'FX to NGN', 'Payment Plan', 'Installments', 'Status', 'Anonymous', 'Created At'],
        };
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    private function applyDateRange(Builder $query, array $validated): void
    {
        $startDate = ! empty($validated['start_date']) ? Carbon::parse((string) $validated['start_date'])->startOfDay() : null;
        $endDate = ! empty($validated['end_date']) ? Carbon::parse((string) $validated['end_date'])->endOfDay() : null;

        if ($startDate !== null) {
            $query->where('created_at', '>=', $startDate);
        }
        if ($endDate !== null) {
            $query->where('created_at', '<=', $endDate);
        }
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    private function applySearch(Builder $query, ReportType $type, array $validated): void
    {
        $search = trim((string) ($validated['search'] ?? ''));
        if ($search === '') {
            return;
        }

        $like = '%'.$search.'%';
        $query->where(function (Builder $builder) use ($type, $like): void {
            match ($type) {
                ReportType::TRANSACTIONS => $builder->where('transaction_id', 'like', $like)
                    ->orWhere('donor_name', 'like', $like)
                    ->orWhere('donor_email', 'like', $like),
                ReportType::TIER_CONFIGURATIONS => $builder->where('name', 'like', $like)->orWhere('description', 'like', $like),
                ReportType::ADMIN_USERS => $builder->where('name', 'like', $like)->orWhere('email', 'like', $like),
                ReportType::ROLES => $builder->where('name', 'like', $like)->orWhere('description', 'like', $like),
                ReportType::CAMPAIGNS => $builder->where('name', 'like', $like)->orWhere('campaign_id', 'like', $like),
                ReportType::EMAIL_CAMPAIGNS => $builder->where('title', 'like', $like),
                ReportType::PLEDGES => $builder->where('uuid', 'like', $like)
                    ->orWhere('donor_name', 'like', $like)
                    ->orWhere('donor_email', 'like', $like)
                    ->orWhere('donor_phone', 'like', $like),
            };
        });
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    private function applyFilters(Builder $query, ReportType $type, array $validated): void
    {
        $status = data_get($validated, 'filters.status');
        if (is_string($status) && $status !== '' && in_array($type, [ReportType::TRANSACTIONS, ReportType::CAMPAIGNS, ReportType::EMAIL_CAMPAIGNS, ReportType::PLEDGES], true)) {
            $query->where('status', $status);
        }

        $active = data_get($validated, 'filters.is_active');
        if ($active !== null && in_array($type, [ReportType::TIER_CONFIGURATIONS, ReportType::ADMIN_USERS, ReportType::ROLES, ReportType::EMAIL_CAMPAIGNS], true)) {
            $truthy = in_array($active, ['1', 1, true, 'true'], true);
            $query->where('is_active', $truthy);
        }

        if ($type === ReportType::TRANSACTIONS) {
            $currency = data_get($validated, 'filters.currency');
            if (is_string($currency) && $currency !== '') {
                $query->where('currency', $currency);
            }
        }

        if ($type === ReportType::PLEDGES) {
            $currency = data_get($validated, 'filters.currency');
            if (is_string($currency) && $currency !== '') {
                $query->where('currency', strtoupper($currency));
            }

            $campaignUuid = data_get($validated, 'filters.campaign_uuid');
            if (is_string($campaignUuid) && $campaignUuid !== '') {
                $query->where('campaign_uuid', $campaignUuid);
            }

            $userUuid = data_get($validated, 'filters.user_uuid');
            if (is_string($userUuid) && $userUuid !== '') {
                $query->where('user_uuid', $userUuid);
            }

            $plan = data_get($validated, 'filters.payment_plan_type');
            if (is_string($plan) && $plan !== '') {
                $query->where('payment_plan_type', $plan);
            }

            $anonymous = data_get($validated, 'filters.is_anonymous');
            if ($anonymous !== null && $anonymous !== '') {
                $truthy = in_array($anonymous, ['1', 1, true, 'true'], true);
                $query->where('is_anonymous', $truthy);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    private function applySort(Builder $query, ReportType $type, array $validated): void
    {
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $allowed = $this->sortKeysFor($type);

        if (! in_array($sortBy, $allowed, true)) {
            $sortBy = 'created_at';
        }

        $query->orderBy($sortBy, $sortDirection);
    }

    /**
     * @return list<string>
     */
    private function filterKeysFor(ReportType $type): array
    {
        return match ($type) {
            ReportType::TRANSACTIONS => ['status', 'currency', 'is_anonymous'],
            ReportType::TIER_CONFIGURATIONS => ['is_active'],
            ReportType::ADMIN_USERS => ['is_active'],
            ReportType::ROLES => ['is_active'],
            ReportType::CAMPAIGNS => ['status'],
            ReportType::EMAIL_CAMPAIGNS => ['status', 'is_active'],
            ReportType::PLEDGES => ['status', 'currency', 'campaign_uuid', 'user_uuid', 'payment_plan_type', 'is_anonymous'],
        };
    }

    /**
     * @return list<string>
     */
    private function sortKeysFor(ReportType $type): array
    {
        return match ($type) {
            ReportType::TRANSACTIONS => ['transaction_id', 'amount', 'status', 'created_at', 'updated_at'],
            ReportType::TIER_CONFIGURATIONS => ['name', 'min_amount', 'max_amount', 'sort_order', 'is_active', 'created_at'],
            ReportType::ADMIN_USERS => ['name', 'email', 'is_active', 'last_active_at', 'created_at'],
            ReportType::ROLES => ['name', 'users_count', 'is_active', 'created_at', 'updated_at'],
            ReportType::CAMPAIGNS => ['name', 'campaign_id', 'status', 'target_amount', 'created_at', 'updated_at'],
            ReportType::EMAIL_CAMPAIGNS => ['title', 'status', 'is_active', 'sent_at', 'created_at', 'updated_at'],
            ReportType::PLEDGES => ['uuid', 'committed_amount', 'committed_amount_ngn', 'exchange_rate_to_naira', 'currency', 'status', 'payment_plan_type', 'installment_count', 'created_at', 'updated_at'],
        };
    }
}
