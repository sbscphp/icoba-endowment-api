<?php

namespace App\Services\Admin\TierConfiguration;

use App\Exceptions\ApiException;
use App\Helpers\FileUploadHelper;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\TierConfiguration;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use InvalidArgumentException;

class TierConfigurationService
{
    private const UPLOAD_FOLDER = 'tier-badges';

    private const MAX_EXPORT_ROWS = 5000;

    private const RANGE_GAP = 0.01;

    public function __construct(
        private readonly TierConfigurationMemberStatsService $memberStats,
    ) {}
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): TierConfiguration
    {
        $minAmount = (float) $payload['min_amount'];
        $maxAmount = isset($payload['max_amount']) ? (float) $payload['max_amount'] : null;
        $this->assertNoRangeOverlap($minAmount, $maxAmount);

        $tier = TierConfiguration::query()->create([
            'name' => (string) $payload['name'],
            'slug' => $this->resolveSlug($payload),
            'description' => $payload['description'] ?? null,
            'tier_badge_url' => $this->uploadBadgeIfPresent($payload['tier_badge_url'] ?? null),
            'base_color' => $payload['base_color'] ?? null,
            'min_amount' => $minAmount,
            'max_amount' => $maxAmount,
            'benefits' => array_values(array_filter((array) ($payload['benefits'] ?? []), fn ($v) => is_string($v) && trim($v) !== '')),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ]);

        return $tier->fresh() ?? $tier;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{total:int,active:int,inactive:int}
     */
    public function stats(array $validated): array
    {
        $query = TierConfiguration::query();
        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        return array_merge(ListingFilterRules::periodMeta($validated), [
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

        $paginator = $this->baseListQuery($validated)->paginate($perPage);
        $this->attachMemberStats($paginator->getCollection(), $validated);

        return $paginator;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: Collection<int, TierConfiguration>, 1: bool}
     */
    public function exportCollection(array $validated): array
    {
        $query = $this->baseListQuery($validated);
        $total = (clone $query)->count();
        $truncated = $total > self::MAX_EXPORT_ROWS;
        /** @var Collection<int, TierConfiguration> $rows */
        $rows = $query->limit(self::MAX_EXPORT_ROWS)->get();

        return [$rows, $truncated];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(array $validated): Builder
    {
        $sortBy = (string) ($validated['sort_by'] ?? 'sort_order');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';

        $query = TierConfiguration::query()
            ->withCount('certificateTemplates as templates_count');

        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('uuid', 'like', '%'.$search.'%');
            });
        }

        $status = data_get($validated, 'filters.status');
        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        if (! in_array($sortBy, ['name', 'min_amount', 'max_amount', 'sort_order', 'is_active', 'created_at', 'updated_at'], true)) {
            $sortBy = 'sort_order';
        }

        return $query->orderBy($sortBy, $sortDirection);
    }

    public function findTier(string $tierId, array $validated = []): TierConfiguration
    {
        $tier = $this->resolveTier($tierId);
        $this->attachMemberStats(collect([$tier]), $validated);

        return $tier;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(string $tierId, array $payload): TierConfiguration
    {
        $tier = $this->resolveTier($tierId);

        $updates = [];
        foreach (['name', 'description', 'sort_order', 'slug', 'base_color'] as $key) {
            if (array_key_exists($key, $payload)) {
                $updates[$key] = $payload[$key];
            }
        }

        if (array_key_exists('slug', $payload) && ($payload['slug'] === null || $payload['slug'] === '')) {
            unset($updates['slug']);
        } elseif (array_key_exists('slug', $payload)) {
            $updates['slug'] = Str::slug((string) $payload['slug']);
        }

        if (array_key_exists('tier_badge_url', $payload)) {
            $updates['tier_badge_url'] = $this->uploadBadgeIfPresent($payload['tier_badge_url']);
        }

        if (array_key_exists('min_amount', $payload)) {
            $updates['min_amount'] = (float) $payload['min_amount'];
        }
        if (array_key_exists('max_amount', $payload)) {
            $updates['max_amount'] = $payload['max_amount'] !== null ? (float) $payload['max_amount'] : null;
        }
        if (array_key_exists('benefits', $payload)) {
            $updates['benefits'] = array_values(array_filter((array) ($payload['benefits'] ?? []), fn ($v) => is_string($v) && trim($v) !== ''));
        }
        if (array_key_exists('is_active', $payload)) {
            $updates['is_active'] = (bool) $payload['is_active'];
        }

        $effectiveMin = array_key_exists('min_amount', $updates)
            ? (float) $updates['min_amount']
            : (float) $tier->min_amount;
        $effectiveMax = array_key_exists('max_amount', $updates)
            ? ($updates['max_amount'] !== null ? (float) $updates['max_amount'] : null)
            : ($tier->max_amount !== null ? (float) $tier->max_amount : null);
        $this->assertNoRangeOverlap($effectiveMin, $effectiveMax, (int) $tier->id);

        if ($updates !== []) {
            $tier->fill($updates)->save();
        }

        return $tier->fresh() ?? $tier;
    }

    public function toggleActiveStatus(string $tierId): TierConfiguration
    {
        $tier = $this->resolveTier($tierId);
        $tier->forceFill(['is_active' => ! ((bool) $tier->is_active)])->save();

        return $tier->fresh() ?? $tier;
    }

    /**
     * @return array{templates_count:int}
     */
    public function delete(string $tierId): array
    {
        $tier = $this->resolveTier($tierId);
        $templatesCount = $tier->certificateTemplates()->count();

        if ($templatesCount > 0) {
            return ['templates_count' => $templatesCount];
        }

        $tier->delete();

        return ['templates_count' => 0];
    }

    /**
     * @return Collection<int, TierConfiguration>
     */
    public function dropdown(string $status = 'active'): Collection
    {
        $query = TierConfiguration::query();

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        return $query
            ->orderBy('sort_order')
            ->orderBy('name')
            ->get(['uuid', 'name', 'tier_badge_url', 'is_active']);
    }

    private function resolveTier(string $tierId): TierConfiguration
    {
        $tier = TierConfiguration::query()
            ->where(function (Builder $builder) use ($tierId): void {
                $builder->where('uuid', $tierId);
                if (is_numeric($tierId)) {
                    $builder->orWhere('id', (int) $tierId);
                }
            })
            ->first();

        if ($tier === null) {
            throw (new ModelNotFoundException)->setModel(TierConfiguration::class, [$tierId]);
        }

        return $tier;
    }

    private function uploadBadgeIfPresent(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return FileUploadHelper::smartSingleFileUpload($value, self::UPLOAD_FOLDER);
        } catch (InvalidArgumentException $e) {
            throw new ApiException('Tier badge upload failed: '.$e->getMessage(), 422);
        }
    }

    private function assertNoRangeOverlap(float $minAmount, ?float $maxAmount, ?int $ignoreTierId = null): void
    {
        $result = $this->findRangeOverlaps($minAmount, $maxAmount, $ignoreTierId);

        if ($result['overlapping'] === []) {
            return;
        }

        $names = array_column($result['overlapping'], 'name');
        $message = count($names) === 1
            ? sprintf(
                'Tier range overlaps with existing tier "%s". Please adjust min/max amount or update neighboring tiers first.',
                $names[0]
            )
            : sprintf(
                'Tier range overlaps with %d existing tiers: %s. Please adjust min/max amount or update neighboring tiers first.',
                count($names),
                implode(', ', $names)
            );

        throw new ApiException($message, 422, [
            'overlapping_tiers' => $result['overlapping'],
            'suggested_adjustments' => $result['suggestions'],
            'requested_range' => [
                'min_amount' => $minAmount,
                'max_amount' => $maxAmount,
            ],
        ]);
    }

    /**
     * @return array{
     *     overlapping: list<array{id: int, uuid: string, name: string, min_amount: float, max_amount: float|null}>,
     *     suggestions: list<array{tier_id: string, tier_name: string, field: string, suggested_value: bool|float|null, reason: string}>
     * }
     */
    private function findRangeOverlaps(float $minAmount, ?float $maxAmount, ?int $ignoreTierId = null): array
    {
        $overlapping = TierConfiguration::query()
            ->when($ignoreTierId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreTierId))
            ->where(function (Builder $query) use ($minAmount, $maxAmount): void {
                if ($maxAmount === null) {
                    $query->whereNull('max_amount')
                        ->orWhere('max_amount', '>=', $minAmount);

                    return;
                }

                $query->where('min_amount', '<=', $maxAmount)
                    ->where(function (Builder $inner) use ($minAmount): void {
                        $inner->whereNull('max_amount')
                            ->orWhere('max_amount', '>=', $minAmount);
                    });
            })
            ->orderBy('min_amount')
            ->get(['id', 'uuid', 'name', 'min_amount', 'max_amount']);

        $rows = $overlapping->map(fn (TierConfiguration $tier): array => [
            'id' => (int) $tier->id,
            'uuid' => (string) $tier->uuid,
            'name' => (string) $tier->name,
            'min_amount' => (float) $tier->min_amount,
            'max_amount' => $tier->max_amount !== null ? (float) $tier->max_amount : null,
        ])->values()->all();

        return [
            'overlapping' => $rows,
            'suggestions' => $this->buildOverlapSuggestions($minAmount, $maxAmount, $rows),
        ];
    }

    /**
     * @param  list<array{id: int, uuid: string, name: string, min_amount: float, max_amount: float|null}>  $overlapping
     * @return list<array{tier_id: string, tier_name: string, field: string, suggested_value: bool|float|null, reason: string}>
     */
    private function buildOverlapSuggestions(float $newMin, ?float $newMax, array $overlapping): array
    {
        $suggestions = [];

        foreach ($overlapping as $tier) {
            $otherMin = $tier['min_amount'];
            $otherMax = $tier['max_amount'];

            if ($otherMin < $newMin && ($otherMax === null || $otherMax >= $newMin)) {
                $suggestedMax = round($newMin - self::RANGE_GAP, 2);
                if ($suggestedMax >= $otherMin) {
                    $suggestions[] = [
                        'tier_id' => $tier['uuid'],
                        'tier_name' => $tier['name'],
                        'field' => 'max_amount',
                        'suggested_value' => $suggestedMax,
                        'reason' => sprintf(
                            'Reduce max so it ends before your new minimum (₦%s).',
                            number_format($newMin, 2, '.', ',')
                        ),
                    ];
                }
            }

            if ($newMax !== null && $otherMin <= $newMax && ($otherMax === null || $otherMax > $newMax)) {
                $suggestedMin = round($newMax + self::RANGE_GAP, 2);
                if ($otherMax === null || $suggestedMin <= $otherMax) {
                    $suggestions[] = [
                        'tier_id' => $tier['uuid'],
                        'tier_name' => $tier['name'],
                        'field' => 'min_amount',
                        'suggested_value' => $suggestedMin,
                        'reason' => sprintf(
                            'Raise min so it starts after your new maximum (₦%s).',
                            number_format($newMax, 2, '.', ',')
                        ),
                    ];

                    continue;
                }
            }

            if ($newMax !== null
                && $otherMin >= $newMin
                && ($otherMax === null || $otherMax <= $newMax)) {
                $suggestions[] = [
                    'tier_id' => $tier['uuid'],
                    'tier_name' => $tier['name'],
                    'field' => 'is_active',
                    'suggested_value' => false,
                    'reason' => 'This tier falls entirely within your requested range; deactivate or delete it first.',
                ];
            }
        }

        $seen = [];

        return array_values(array_filter(
            $suggestions,
            function (array $suggestion) use (&$seen): bool {
                $key = $suggestion['tier_id'].'|'.$suggestion['field'];
                if (isset($seen[$key])) {
                    return false;
                }

                $seen[$key] = true;

                return true;
            }
        ));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveSlug(array $payload): string
    {
        $slug = trim((string) ($payload['slug'] ?? ''));

        if ($slug !== '') {
            return Str::slug($slug);
        }

        return Str::slug((string) $payload['name']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function attachMemberStats(\Illuminate\Support\Collection $tiers, array $validated): void
    {
        if ($tiers->isEmpty()) {
            return;
        }

        $window = ListingFilterRules::resolveDateWindow($validated);
        $this->memberStats->attachToTiers($tiers, $window['start'], $window['end']);
    }
}
