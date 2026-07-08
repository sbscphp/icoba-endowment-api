<?php

namespace App\Support;

use Carbon\Carbon;

/**
 * Normalised listing parameters shared across paginated APIs and exports.
 *
 * Build via {@see self::fromValidated()} after merging reusable listing rules in a Form Request.
 */
final readonly class ListingQuery
{
    /**
     * @param  array<string, mixed>  $filters
     */
    public function __construct(
        public ?string $search,
        public ?Carbon $startDate,
        public ?Carbon $endDate,
        public string $sortBy,
        public string $sortDirection,
        public int $page,
        public int $perPage,
        public array $filters,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(
        array $validated,
        string $defaultSortBy = 'created_at',
        string $defaultSortDirection = 'desc',
        int $defaultPage = 1,
        int $defaultPerPage = 15
    ): self {
        $filters = $validated['filters'] ?? [];
        if (! is_array($filters)) {
            $filters = [];
        }

        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? $defaultSortDirection));
        if (! in_array($sortDirection, ['asc', 'desc'], true)) {
            $sortDirection = $defaultSortDirection;
        }

        $search = isset($validated['search']) && is_string($validated['search'])
            ? trim($validated['search'])
            : null;

        return new self(
            search: ($search !== '' && $search !== null) ? $search : null,
            startDate: ! empty($validated['start_date'])
                ? Carbon::parse((string) $validated['start_date'])->startOfDay()
                : null,
            endDate: ! empty($validated['end_date'])
                ? Carbon::parse((string) $validated['end_date'])->endOfDay()
                : null,
            sortBy: (string) ($validated['sort_by'] ?? $defaultSortBy),
            sortDirection: $sortDirection,
            page: max(1, (int) ($validated['page'] ?? $defaultPage)),
            perPage: max(1, (int) ($validated['per_page'] ?? $defaultPerPage)),
            filters: $filters,
        );
    }

    public function searchTokens(): array
    {
        if ($this->search === null || $this->search === '') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (string $t): string => trim($t),
            preg_split('/\s+/u', $this->search) ?: []
        )));
    }
}
