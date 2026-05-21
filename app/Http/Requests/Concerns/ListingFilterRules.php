<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Currency;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Validation\Rule;

final class ListingFilterRules
{
    /**
     * @return list<string>
     */
    public static function periodValues(): array
    {
        return [
            '1day',
            '3days',
            '7days',
            '14days',
            '30days',
            '3months',
            '6months',
            '1year',
            'lastyear',
            'custom',
        ];
    }

    /**
     * Shared query-string rules for search, date range, ordering, and pagination.
     *
     * Domain-specific filters should merge keys such as `filters.*` after calling this method.
     *
     * @param  list<string>  $sortableColumns
     */
    public static function rules(array $sortableColumns, int $maxPerPage = 100): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'period' => ['sometimes', 'nullable', Rule::in(self::periodValues())],
            'start_date' => ['sometimes', 'nullable', 'date', 'required_if:period,custom'],
            'end_date' => ['sometimes', 'nullable', 'date', 'required_if:period,custom', 'after_or_equal:start_date'],
            'sort_by' => ['sometimes', 'string', Rule::in($sortableColumns)],
            'sort_direction' => ['sometimes', 'string', Rule::in(['asc', 'desc', 'ASC', 'DESC'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
            'filters' => ['sometimes', 'array'],
        ];
    }

    /**
     * Shared rules for date-period based filters.
     *
     * If period=custom, start_date and end_date are required.
     */
    public static function periodDateRules(bool $includeCurrency = false): array
    {
        $rules = [
            'period' => ['sometimes', 'nullable', Rule::in(self::periodValues())],
            'start_date' => ['sometimes', 'nullable', 'date', 'required_if:period,custom'],
            'end_date' => ['sometimes', 'nullable', 'date', 'required_if:period,custom', 'after_or_equal:start_date'],
        ];

        if ($includeCurrency) {
            $rules['currency'] = ['sometimes', 'nullable', Rule::in(Currency::values())];
        }

        return $rules;
    }

    /**
     * @return array{start_date: string|null, end_date: string|null}
     */
    public static function dateRangeFromPeriod(?string $period): array
    {
        $value = strtolower((string) $period);
        $now = now();

        [$start, $end] = match ($value) {
            '1day' => [$now->copy()->subDay()->startOfDay(), $now->copy()->endOfDay()],
            '3days' => [$now->copy()->subDays(3)->startOfDay(), $now->copy()->endOfDay()],
            '7days' => [$now->copy()->subDays(7)->startOfDay(), $now->copy()->endOfDay()],
            '14days' => [$now->copy()->subDays(14)->startOfDay(), $now->copy()->endOfDay()],
            '30days' => [$now->copy()->subDays(30)->startOfDay(), $now->copy()->endOfDay()],
            '3months' => [$now->copy()->subMonths(3)->startOfDay(), $now->copy()->endOfDay()],
            '6months' => [$now->copy()->subMonths(6)->startOfDay(), $now->copy()->endOfDay()],
            '1year' => [$now->copy()->subYear()->startOfDay(), $now->copy()->endOfDay()],
            'lastyear' => [$now->copy()->subYear()->startOfYear(), $now->copy()->subYear()->endOfYear()],
            default => [null, null],
        };

        return [
            'start_date' => $start instanceof CarbonInterface ? $start->toDateString() : null,
            'end_date' => $end instanceof CarbonInterface ? $end->toDateString() : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{start: Carbon|null, end: Carbon|null, period: string|null}
     */
    public static function resolveDateWindow(array $validated): array
    {
        $period = isset($validated['period']) ? strtolower((string) $validated['period']) : null;
        $start = ! empty($validated['start_date']) ? Carbon::parse((string) $validated['start_date'])->startOfDay() : null;
        $end = ! empty($validated['end_date']) ? Carbon::parse((string) $validated['end_date'])->endOfDay() : null;

        if (($start === null || $end === null) && $period !== null && $period !== '' && $period !== 'custom') {
            $derived = self::dateRangeFromPeriod($period);
            if ($start === null && ! empty($derived['start_date'])) {
                $start = Carbon::parse((string) $derived['start_date'])->startOfDay();
            }
            if ($end === null && ! empty($derived['end_date'])) {
                $end = Carbon::parse((string) $derived['end_date'])->endOfDay();
            }
        }

        return [
            'start' => $start,
            'end' => $end,
            'period' => ($period !== null && $period !== '') ? $period : null,
        ];
    }

    public static function applyPeriodDateRangeToRequest($request): void
    {
        $period = strtolower((string) $request->input('period', ''));
        if ($period === '' || $period === 'custom') {
            return;
        }

        $range = self::dateRangeFromPeriod($period);
        if ($range['start_date'] !== null && $range['end_date'] !== null) {
            $request->merge($range);
        }
    }
}
