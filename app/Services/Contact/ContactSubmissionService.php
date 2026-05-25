<?php

namespace App\Services\Contact;

use App\Enums\ContactSubmissionStatus;
use App\Enums\ContactSubmissionUserType;
use App\Jobs\SendContactSubmissionAcknowledgementEmailJob;
use App\Models\ContactSubmission;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

class ContactSubmissionService
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(array $payload): ContactSubmission
    {
        $submission = ContactSubmission::query()->create([
            'full_name' => trim((string) $payload['full_name']),
            'email' => strtolower(trim((string) $payload['email'])),
            'user_type' => ContactSubmissionUserType::from((string) $payload['user_type']),
            'description' => trim((string) $payload['description']),
            'status' => ContactSubmissionStatus::PENDING,
        ]);

        SendContactSubmissionAcknowledgementEmailJob::dispatch($submission->uuid);

        return $submission;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(array $validated): LengthAwarePaginator
    {
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        $query = ContactSubmission::query()->with('handledByAdmin:uuid,name');

        $this->applyDateRange($query, $validated, 'created_at');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('full_name', 'like', '%'.$search.'%')
                    ->orWhere('email', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhere('uuid', 'like', '%'.$search.'%');
            });
        }

        $status = data_get($validated, 'filters.status');
        if (is_string($status) && $status !== '' && in_array($status, ContactSubmissionStatus::values(), true)) {
            $query->where('status', $status);
        }

        $userType = data_get($validated, 'filters.user_type');
        if (is_string($userType) && $userType !== '' && in_array($userType, ContactSubmissionUserType::values(), true)) {
            $query->where('user_type', $userType);
        }

        if (! in_array($sortBy, ['full_name', 'email', 'user_type', 'status', 'created_at', 'updated_at'], true)) {
            $sortBy = 'created_at';
        }

        return $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);
    }

    public function find(string $submissionUuid): ContactSubmission
    {
        $submission = ContactSubmission::query()
            ->with('handledByAdmin:uuid,name')
            ->where('uuid', $submissionUuid)
            ->first();

        if ($submission === null) {
            throw (new ModelNotFoundException)->setModel(ContactSubmission::class, [$submissionUuid]);
        }

        return $submission;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateStatus(string $submissionUuid, array $payload, ?string $adminUuid = null): ContactSubmission
    {
        $submission = $this->find($submissionUuid);

        $status = ContactSubmissionStatus::from((string) $payload['status']);

        $updates = [
            'status' => $status,
            'handled_by_admin_uuid' => $adminUuid,
        ];

        if ($status === ContactSubmissionStatus::RESOLVED) {
            $updates['resolved_at'] = now();
        } elseif ($status !== ContactSubmissionStatus::CLOSED) {
            $updates['resolved_at'] = null;
        }

        $submission->fill($updates)->save();

        return $submission->fresh(['handledByAdmin:uuid,name']) ?? $submission;
    }

    public function closeExpiredResolvedSubmissions(bool $dryRun = false): int
    {
        $cutoff = now()->subDays(7);
        $closed = 0;

        ContactSubmission::query()
            ->where('status', ContactSubmissionStatus::RESOLVED)
            ->whereNotNull('resolved_at')
            ->where('resolved_at', '<=', $cutoff)
            ->orderBy('id')
            ->chunkById(100, function ($submissions) use ($dryRun, &$closed): void {
                foreach ($submissions as $submission) {
                    if ($dryRun) {
                        $closed++;

                        continue;
                    }

                    $submission->forceFill([
                        'status' => ContactSubmissionStatus::CLOSED,
                    ])->save();

                    $closed++;
                }
            });

        return $closed;
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
}
