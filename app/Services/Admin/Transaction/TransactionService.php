<?php

namespace App\Services\Admin\Transaction;

use App\Enums\TransactionStatus;
use App\Models\TierConfiguration;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

class TransactionService
{
    public const MAX_EXPORT_ROWS = 5000;

    /**
     * @return array<string, mixed>
     */
    public function stats(?string $startDate, ?string $endDate): array
    {
        $start = ! empty($startDate) ? Carbon::parse((string) $startDate)->startOfDay() : null;
        $end = ! empty($endDate) ? Carbon::parse((string) $endDate)->endOfDay() : null;

        $base = Transaction::query();
        if ($start !== null) {
            $base->where('created_at', '>=', $start);
        }
        if ($end !== null) {
            $base->where('created_at', '<=', $end);
        }

        $successful = (clone $base)->where('status', TransactionStatus::SUCCESSFUL);

        return [
            'start_date' => $startDate,
            'end_date' => $endDate,
            'total_count' => (clone $base)->count(),
            'successful_count' => (clone $successful)->count(),
            'pending_count' => (clone $base)->where('status', TransactionStatus::PENDING)->count(),
            'failed_count' => (clone $base)->where('status', TransactionStatus::FAILED)->count(),
            'reversed_count' => (clone $base)->where('status', TransactionStatus::REVERSED)->count(),
            'anonymous_count' => (clone $base)->where('is_anonymous', true)->count(),
            'unique_donors_count' => (clone $successful)->whereNotNull('user_uuid')->distinct('user_uuid')->count('user_uuid'),
            'total_volume_naira' => (string) ((clone $successful)->sum('amount_in_naira') ?: '0'),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(array $validated): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        return $this->baseListQuery($validated)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: EloquentCollection<int, Transaction>, 1: bool}
     */
    public function exportCollection(array $validated): array
    {
        $query = $this->baseListQuery($validated);
        $total = (clone $query)->count();
        $truncated = $total > self::MAX_EXPORT_ROWS;
        /** @var EloquentCollection<int, Transaction> $rows */
        $rows = $query->limit(self::MAX_EXPORT_ROWS)->get();

        return [$rows, $truncated];
    }

    public function findTransaction(string $transactionId): Transaction
    {
        $transaction = Transaction::query()
            ->where(function (Builder $builder) use ($transactionId): void {
                $builder->where('uuid', $transactionId)
                    ->orWhere('transaction_id', $transactionId);
                if (is_numeric($transactionId)) {
                    $builder->orWhere('id', (int) $transactionId);
                }
            })
            ->with($this->detailRelations())
            ->first();

        if ($transaction === null) {
            throw (new ModelNotFoundException)->setModel(Transaction::class, [$transactionId]);
        }

        return $transaction;
    }

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
     * @return list<string>
     */
    public function detailRelations(): array
    {
        return [
            'campaign:uuid,name,campaign_id',
            'donor:uuid,firstname,lastname,middlename,email,phone_number,country_code,graduation_set_uuid',
            'donor.graduationSet:uuid,name,set_number',
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(array $validated): Builder
    {
        $query = Transaction::query()->with([
            'campaign:uuid,name,campaign_id',
            'donor:uuid,firstname,lastname,middlename,graduation_set_uuid',
            'donor.graduationSet:uuid,name,set_number',
        ]);

        $dateColumn = data_get($validated, 'filters.date_field') === 'paid_at' ? 'paid_at' : 'created_at';
        $this->applyDateRange($query, $validated, $dateColumn);

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$this->escapeLike($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('transaction_id', 'like', $like)
                    ->orWhere('uuid', 'like', $like)
                    ->orWhere('donor_name', 'like', $like)
                    ->orWhere('donor_email', 'like', $like)
                    ->orWhere('donor_phone', 'like', $like)
                    ->orWhere('gateway', 'like', $like)
                    ->orWhere('gateway_reference', 'like', $like)
                    ->orWhereHas('donor', function (Builder $b) use ($like): void {
                        $b->where('firstname', 'like', $like)
                            ->orWhere('lastname', 'like', $like)
                            ->orWhere('email', 'like', $like)
                            ->orWhere('phone_number', 'like', $like);
                    })
                    ->orWhereHas('campaign', function (Builder $b) use ($like): void {
                        $b->where('name', 'like', $like)
                            ->orWhere('campaign_id', 'like', $like);
                    });
            });
        }

        $status = data_get($validated, 'filters.status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $currency = data_get($validated, 'filters.currency');
        if (is_string($currency) && $currency !== '') {
            $query->where('currency', $currency);
        }

        $gateway = data_get($validated, 'filters.gateway');
        if (is_string($gateway) && $gateway !== '') {
            $query->where('gateway', $gateway);
        }

        $campaignUuid = data_get($validated, 'filters.campaign_uuid');
        if (is_string($campaignUuid) && $campaignUuid !== '') {
            $query->where('campaign_uuid', $campaignUuid);
        }

        $userUuid = data_get($validated, 'filters.user_uuid');
        if (is_string($userUuid) && $userUuid !== '') {
            $query->where('user_uuid', $userUuid);
        }

        $setUuid = data_get($validated, 'filters.graduation_set_uuid');
        if (is_string($setUuid) && $setUuid !== '') {
            $query->whereHas('donor', fn (Builder $b) => $b->where('graduation_set_uuid', $setUuid));
        }

        $anonymous = data_get($validated, 'filters.is_anonymous');
        if ($anonymous !== null && $anonymous !== '') {
            $truthy = in_array($anonymous, ['1', 1, true, 'true'], true);
            $query->where('is_anonymous', $truthy);
        }

        $minAmount = data_get($validated, 'filters.min_amount');
        if (is_numeric($minAmount)) {
            $query->where('amount_in_naira', '>=', (float) $minAmount);
        }

        $maxAmount = data_get($validated, 'filters.max_amount');
        if (is_numeric($maxAmount)) {
            $query->where('amount_in_naira', '<=', (float) $maxAmount);
        }

        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $allowedSorts = ['transaction_id', 'donor_name', 'amount', 'amount_in_naira', 'status', 'paid_at', 'created_at', 'updated_at'];
        if (! in_array($sortBy, $allowedSorts, true)) {
            $sortBy = 'created_at';
        }

        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy($sortBy, $sortDirection);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyDateRange(Builder $query, array $validated, string $column): void
    {
        $startDate = ! empty($validated['start_date']) ? Carbon::parse((string) $validated['start_date'])->startOfDay() : null;
        $endDate = ! empty($validated['end_date']) ? Carbon::parse((string) $validated['end_date'])->endOfDay() : null;

        if ($startDate !== null) {
            $query->where($column, '>=', $startDate);
        }

        if ($endDate !== null) {
            $query->where($column, '<=', $endDate);
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
