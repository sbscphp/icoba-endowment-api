<?php

namespace App\Services\Admin\ContentManagement;

use App\Enums\AuditActionEnum;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\Faq;
use App\Services\Admin\ContentManagement\Concerns\AuditsContentChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

class FaqService
{
    use AuditsContentChanges;

    public const SORTABLE_COLUMNS = ['title', 'is_active', 'sort_order', 'created_at', 'updated_at'];

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(array $validated): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        return $this->baseListQuery($validated)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload, ?string $adminUuid = null): Faq
    {
        $faq = Faq::query()->create([
            'title' => (string) $payload['title'],
            'content' => (string) $payload['content'],
            'sort_order' => (int) ($payload['sort_order'] ?? $this->nextSortOrder()),
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'created_by_admin_uuid' => $adminUuid,
            'updated_by_admin_uuid' => $adminUuid,
        ]);

        $this->recordContentAudit(
            AuditActionEnum::FAQ_CREATED,
            $faq,
            'FAQ created.',
            ['faq_uuid' => $faq->uuid, 'title' => $faq->title],
            $adminUuid,
        );

        return $faq->fresh(['updatedByAdmin']) ?? $faq;
    }

    public function findFaq(string $faqId): Faq
    {
        return $this->resolveFaq($faqId)->load('updatedByAdmin');
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(string $faqId, array $payload, ?string $adminUuid = null): Faq
    {
        $faq = $this->resolveFaq($faqId);

        $updates = [];
        foreach (['title', 'content', 'sort_order'] as $key) {
            if (array_key_exists($key, $payload)) {
                $updates[$key] = $payload[$key];
            }
        }

        if (array_key_exists('is_active', $payload)) {
            $updates['is_active'] = (bool) $payload['is_active'];
        }

        if ($updates !== []) {
            $updates['updated_by_admin_uuid'] = $adminUuid;
            $faq->fill($updates);
            $changes = $this->pendingChanges($faq);
            $faq->save();

            if ($changes['changed_fields'] !== []) {
                $this->recordContentAudit(
                    AuditActionEnum::FAQ_UPDATED,
                    $faq,
                    'FAQ updated.',
                    ['faq_uuid' => $faq->uuid, 'title' => $faq->title, ...$changes],
                    $adminUuid,
                );
            }
        }

        return $faq->fresh(['updatedByAdmin']) ?? $faq;
    }

    public function toggleActiveStatus(string $faqId, ?string $adminUuid = null): Faq
    {
        $faq = $this->resolveFaq($faqId);
        $isActive = ! ((bool) $faq->is_active);
        $faq->forceFill([
            'is_active' => $isActive,
            'updated_by_admin_uuid' => $adminUuid,
        ])->save();

        $this->recordContentAudit(
            AuditActionEnum::FAQ_STATUS_TOGGLED,
            $faq,
            $isActive ? 'FAQ activated.' : 'FAQ deactivated.',
            [
                'faq_uuid' => $faq->uuid,
                'title' => $faq->title,
                'previous_status' => $isActive ? 'inactive' : 'active',
                'new_status' => $isActive ? 'active' : 'inactive',
            ],
            $adminUuid,
        );

        return $faq->fresh(['updatedByAdmin']) ?? $faq;
    }

    public function delete(string $faqId, ?string $adminUuid = null): void
    {
        $faq = $this->resolveFaq($faqId);
        $faq->delete();

        $this->recordContentAudit(
            AuditActionEnum::FAQ_DELETED,
            $faq,
            'FAQ deleted.',
            ['faq_uuid' => $faq->uuid, 'title' => $faq->title, 'content' => $faq->content],
            $adminUuid,
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(array $validated): Builder
    {
        $query = Faq::query()->with('updatedByAdmin');

        ListingFilterRules::applyResolvedDateRange($query, $validated, 'created_at');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.strtolower($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->whereRaw('LOWER(title) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(content) LIKE ?', [$like]);
            });
        }

        $isActive = data_get($validated, 'filters.is_active');
        if ($isActive !== null && $isActive !== '') {
            $query->where('is_active', filter_var($isActive, FILTER_VALIDATE_BOOLEAN));
        }

        $sortBy = (string) ($validated['sort_by'] ?? 'sort_order');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        if (! in_array($sortBy, self::SORTABLE_COLUMNS, true)) {
            $sortBy = 'sort_order';
        }

        return $query->orderBy($sortBy, $sortDirection)->orderBy('created_at', 'desc');
    }

    private function resolveFaq(string $faqId): Faq
    {
        $faq = Faq::query()
            ->where(function (Builder $builder) use ($faqId): void {
                $builder->where('uuid', $faqId);
                if (is_numeric($faqId)) {
                    $builder->orWhere('id', (int) $faqId);
                }
            })
            ->first();

        if ($faq === null) {
            throw (new ModelNotFoundException)->setModel(Faq::class, [$faqId]);
        }

        return $faq;
    }

    private function nextSortOrder(): int
    {
        $maxSortOrder = Faq::query()->max('sort_order');

        return $maxSortOrder !== null ? ((int) $maxSortOrder + 1) : 0;
    }
}
