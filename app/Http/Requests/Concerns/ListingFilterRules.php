<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

final class ListingFilterRules
{
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
            'start_date' => ['sometimes', 'nullable', 'date'],
            'end_date' => ['sometimes', 'nullable', 'date', 'after_or_equal:start_date'],
            'sort_by' => ['sometimes', 'string', Rule::in($sortableColumns)],
            'sort_direction' => ['sometimes', 'string', Rule::in(['asc', 'desc', 'ASC', 'DESC'])],
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.$maxPerPage],
            'filters' => ['sometimes', 'array'],
        ];
    }
}
