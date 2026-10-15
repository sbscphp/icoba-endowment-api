<?php

namespace App\Services\Admin\PaymentFeeRule;

use App\Enums\AuditActionEnum;
use App\Enums\ModuleEnums;
use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\PaymentFeeRule;
use App\Models\Transaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

class PaymentFeeRuleService
{
    private const MAX_EXPORT_ROWS = 5000;

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function stats(array $validated): array
    {
        $query = PaymentFeeRule::query();
        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        return array_merge(ListingFilterRules::periodMeta($validated), [
            'charges_enabled' => (bool) config('platform_charges.enabled', false),
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('is_active', true)->count(),
            'inactive' => (clone $query)->where('is_active', false)->count(),
        ]);
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
     * @return array{0: Collection<int, PaymentFeeRule>, 1: bool}
     */
    public function exportCollection(array $validated): array
    {
        $query = $this->baseListQuery($validated);
        $total = (clone $query)->count();
        /** @var Collection<int, PaymentFeeRule> $rows */
        $rows = $query->limit(self::MAX_EXPORT_ROWS)->get();

        return [$rows, $total > self::MAX_EXPORT_ROWS];
    }

    public function find(string $ruleId): PaymentFeeRule
    {
        $rule = $this->resolve($ruleId);
        $rule->setAttribute('transactions_count', Transaction::query()->where('fee_rule_uuid', $rule->uuid)->count());

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, ?string $adminUuid): PaymentFeeRule
    {
        $attributes = $this->normalize($payload, true);
        $this->assertNoOverlap($attributes, null);

        $rule = PaymentFeeRule::query()->create($attributes);

        $this->audit(AuditActionEnum::PAYMENT_FEE_RULE_CREATED, $rule, 'Payment fee rule created.', [
            'rule_uuid' => $rule->uuid,
            'attributes' => $rule->toSnapshot(),
        ], $adminUuid);

        return $rule->fresh() ?? $rule;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(string $ruleId, array $payload, ?string $adminUuid): PaymentFeeRule
    {
        $rule = $this->resolve($ruleId);
        $before = $rule->toSnapshot();

        $attributes = $this->normalize($payload, false);
        $this->assertNoOverlap($attributes, $rule);

        $rule->fill($attributes);
        $changed = array_keys($rule->getDirty());
        $rule->save();

        $this->audit(AuditActionEnum::PAYMENT_FEE_RULE_UPDATED, $rule, 'Payment fee rule updated.', [
            'rule_uuid' => $rule->uuid,
            'changed_fields' => $changed,
            'before' => $before,
            'after' => $rule->toSnapshot(),
        ], $adminUuid);

        return $rule->fresh() ?? $rule;
    }

    public function toggleActiveStatus(string $ruleId, ?string $adminUuid): PaymentFeeRule
    {
        $rule = $this->resolve($ruleId);
        $activating = ! (bool) $rule->is_active;

        if ($activating) {
            $this->assertNoOverlap(['is_active' => true], $rule);
        }

        $rule->forceFill(['is_active' => $activating])->save();

        $this->audit(AuditActionEnum::PAYMENT_FEE_RULE_STATUS_TOGGLED, $rule, $activating ? 'Payment fee rule activated.' : 'Payment fee rule deactivated.', [
            'rule_uuid' => $rule->uuid,
            'is_active' => $activating,
        ], $adminUuid);

        return $rule->fresh() ?? $rule;
    }

    public function delete(string $ruleId, ?string $adminUuid): void
    {
        $rule = $this->resolve($ruleId);
        $snapshot = $rule->toSnapshot();

        // Soft delete: transactions keep their fee_snapshot and FK (withTrashed relation).
        $rule->delete();

        $this->audit(AuditActionEnum::PAYMENT_FEE_RULE_DELETED, $rule, 'Payment fee rule deleted.', [
            'rule_uuid' => $rule->uuid,
            'attributes' => $snapshot,
        ], $adminUuid);
    }

    /**
     * Column values from a request payload. On create, missing numbers default.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function normalize(array $payload, bool $creating): array
    {
        $attributes = [];

        if (array_key_exists('name', $payload)) {
            $attributes['name'] = trim((string) $payload['name']);
        }
        if (array_key_exists('currency', $payload)) {
            $attributes['currency'] = strtoupper((string) $payload['currency']);
        }
        if (array_key_exists('min_amount', $payload)) {
            $attributes['min_amount'] = round((float) $payload['min_amount'], 2);
        }
        if (array_key_exists('max_amount', $payload)) {
            $attributes['max_amount'] = $payload['max_amount'] !== null ? round((float) $payload['max_amount'], 2) : null;
        }
        if (array_key_exists('percentage', $payload)) {
            $attributes['percentage'] = round((float) $payload['percentage'], 4);
        }
        if (array_key_exists('fixed_amount', $payload)) {
            $attributes['fixed_amount'] = round((float) $payload['fixed_amount'], 2);
        }
        if (array_key_exists('cap_amount', $payload)) {
            $attributes['cap_amount'] = $payload['cap_amount'] !== null ? round((float) $payload['cap_amount'], 2) : null;
        }
        if (array_key_exists('cap_currency', $payload)) {
            $capCurrency = is_string($payload['cap_currency']) ? strtoupper(trim($payload['cap_currency'])) : null;
            $attributes['cap_currency'] = $capCurrency !== '' ? $capCurrency : null;
        }
        // A cap currency means nothing without a cap.
        if (array_key_exists('cap_amount', $attributes) && $attributes['cap_amount'] === null) {
            $attributes['cap_currency'] = null;
        }
        if (array_key_exists('is_active', $payload)) {
            $attributes['is_active'] = (bool) $payload['is_active'];
        }

        if ($creating) {
            $attributes += [
                'min_amount' => 0.0,
                'max_amount' => null,
                'percentage' => 0.0,
                'fixed_amount' => 0.0,
                'cap_amount' => null,
                'cap_currency' => null,
                'is_active' => true,
            ];
        }

        return $attributes;
    }

    /**
     * Active rules for the same currency must not cover the same amounts, otherwise the
     * resolver would have to guess.
     *
     * @param  array<string, mixed>  $attributes  incoming values; the existing rule fills the gaps
     */
    private function assertNoOverlap(array $attributes, ?PaymentFeeRule $existing): void
    {
        $active = array_key_exists('is_active', $attributes) ? (bool) $attributes['is_active'] : (bool) ($existing?->is_active ?? true);
        if (! $active) {
            return;
        }

        $currency = (string) ($attributes['currency'] ?? $existing?->currency);
        $min = (float) ($attributes['min_amount'] ?? $existing?->min_amount ?? 0);
        $max = array_key_exists('max_amount', $attributes)
            ? ($attributes['max_amount'] !== null ? (float) $attributes['max_amount'] : null)
            : ($existing?->max_amount !== null ? (float) $existing->max_amount : null);

        $query = PaymentFeeRule::query()
            ->active()
            ->forCurrency($currency)
            ->where('min_amount', '<=', $max ?? PHP_FLOAT_MAX)
            ->where(function (Builder $builder) use ($min): void {
                $builder->whereNull('max_amount')->orWhere('max_amount', '>=', $min);
            });

        if ($existing !== null) {
            $query->where('id', '!=', $existing->id);
        }

        $clash = $query->first();
        if ($clash !== null) {
            throw new ApiException(sprintf(
                'This range overlaps the active rule "%s" (%s to %s). Adjust the range or deactivate the other rule.',
                $clash->name,
                (string) $clash->min_amount,
                $clash->max_amount !== null ? (string) $clash->max_amount : 'no limit',
            ), 422);
        }
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(array $validated): Builder
    {
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = PaymentFeeRule::query()->withCount([
            'transactions as transactions_count' => static function ($q): void {
                $q->whereNull('deleted_at');
            },
        ]);

        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('uuid', 'like', '%'.$search.'%');
            });
        }

        $status = data_get($validated, 'filters.status');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $currency = data_get($validated, 'filters.currency');
        if (is_string($currency) && $currency !== '') {
            $query->where('currency', $currency);
        }

        $sortable = ['name', 'currency', 'min_amount', 'max_amount', 'percentage', 'is_active', 'created_at', 'updated_at'];
        if (! in_array($sortBy, $sortable, true)) {
            $sortBy = 'created_at';
        }

        return $query->orderBy($sortBy, $sortDirection)->orderBy('id');
    }

    private function resolve(string $ruleId): PaymentFeeRule
    {
        $rule = PaymentFeeRule::query()
            ->where(function (Builder $builder) use ($ruleId): void {
                $builder->where('uuid', $ruleId);
                if (is_numeric($ruleId)) {
                    $builder->orWhere('id', (int) $ruleId);
                }
            })
            ->first();

        if ($rule === null) {
            throw (new ModelNotFoundException)->setModel(PaymentFeeRule::class, [$ruleId]);
        }

        return $rule;
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    private function audit(AuditActionEnum $action, PaymentFeeRule $rule, string $description, array $metadata, ?string $adminUuid): void
    {
        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            $action,
            request(),
            $adminUuid,
            $metadata,
            $description,
            PaymentFeeRule::class,
            (string) $rule->uuid,
            ModuleEnums::payment_fee_rules,
            200,
        );
    }
}
