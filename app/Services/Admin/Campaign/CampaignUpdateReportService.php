<?php

namespace App\Services\Admin\Campaign;

use App\Enums\CampaignUpdateReportStatus;
use App\Exceptions\ApiException;
use App\Helpers\FileUploadHelper;
use App\Models\Campaign;
use App\Models\CampaignUpdateReport;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use InvalidArgumentException;

class CampaignUpdateReportService
{
    private const UPLOAD_FOLDER = 'campaign-update-reports';

    private const MAX_EXPORT_ROWS = 5000;

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(string $campaignId, array $validated): LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        return $this->baseListQuery($campaignId, $validated)->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: EloquentCollection<int, CampaignUpdateReport>, 1: bool}
     */
    public function exportCollection(string $campaignId, array $validated): array
    {
        $query = $this->baseListQuery($campaignId, $validated);
        $total = (clone $query)->count();
        $truncated = $total > self::MAX_EXPORT_ROWS;
        /** @var EloquentCollection<int, CampaignUpdateReport> $rows */
        $rows = $query->limit(self::MAX_EXPORT_ROWS)->get();

        return [$rows, $truncated];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(string $campaignId, array $validated): Builder
    {
        $campaign = $this->resolveCampaign($campaignId);
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $query = CampaignUpdateReport::query()
            ->where('campaign_uuid', $campaign->uuid);

        $this->applyDateRange($query, $validated, 'created_at');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('short_description', 'like', '%'.$search.'%')
                    ->orWhere('report_id', 'like', '%'.$search.'%')
                    ->orWhere('uuid', 'like', '%'.$search.'%');
            });
        }

        $status = CampaignUpdateReportStatus::tryFrom((string) data_get($validated, 'filters.status', ''));
        match ($status) {
            CampaignUpdateReportStatus::ACTIVATED => $query->where('is_active', true),
            CampaignUpdateReportStatus::DEACTIVATED => $query->where('is_active', false),
            default => null,
        };

        if (! in_array($sortBy, ['name', 'report_id', 'is_active', 'created_at', 'updated_at'], true)) {
            $sortBy = 'created_at';
        }

        return $query->orderBy($sortBy, $sortDirection);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function create(string $campaignId, array $payload, ?string $adminUuid = null): CampaignUpdateReport
    {
        $campaign = $this->resolveCampaign($campaignId);

        $report = CampaignUpdateReport::query()->create([
            'report_id' => $this->generateReportPublicId(),
            'campaign_uuid' => $campaign->uuid,
            'name' => (string) $payload['name'],
            'short_description' => (string) $payload['short_description'],
            'details' => (string) $payload['details'],
            'banner_url' => $this->uploadBanner($payload['banner']),
            'youtube_link' => $payload['youtube_link'] ?? null,
            'is_active' => (bool) ($payload['is_active'] ?? false),
            'created_by_admin_uuid' => $adminUuid,
        ]);

        return $report->fresh(['campaign']) ?? $report;
    }

    public function findReport(string $campaignId, string $reportId): CampaignUpdateReport
    {
        $campaign = $this->resolveCampaign($campaignId);

        return $this->resolveReportForCampaign($campaign, $reportId);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function update(string $campaignId, string $reportId, array $payload): CampaignUpdateReport
    {
        $campaign = $this->resolveCampaign($campaignId);
        $report = $this->resolveReportForCampaign($campaign, $reportId);

        $updates = [];
        foreach (['name', 'short_description', 'details', 'youtube_link'] as $key) {
            if (array_key_exists($key, $payload)) {
                $updates[$key] = $payload[$key];
            }
        }

        if (array_key_exists('banner', $payload)) {
            $updates['banner_url'] = $this->uploadBanner($payload['banner']);
        }

        if (array_key_exists('is_active', $payload)) {
            $updates['is_active'] = (bool) $payload['is_active'];
        }

        if ($updates !== []) {
            $report->fill($updates)->save();
        }

        return $report->fresh(['campaign']) ?? $report;
    }

    public function toggleActiveStatus(string $campaignId, string $reportId): CampaignUpdateReport
    {
        $campaign = $this->resolveCampaign($campaignId);
        $report = $this->resolveReportForCampaign($campaign, $reportId);
        $report->forceFill(['is_active' => ! ((bool) $report->is_active)])->save();

        return $report->fresh(['campaign']) ?? $report;
    }

    public function delete(string $campaignId, string $reportId): void
    {
        $campaign = $this->resolveCampaign($campaignId);
        $report = $this->resolveReportForCampaign($campaign, $reportId);
        $report->delete();
    }

    public function findActiveReport(string $reportId): CampaignUpdateReport
    {
        $report = $this->resolveReport($reportId);
        if (! (bool) $report->is_active) {
            throw new ApiException('Report is not available.', 404);
        }

        return $report->load('campaign');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function listActive(array $validated): LengthAwarePaginator
    {
        $sortBy = (string) ($validated['sort_by'] ?? 'created_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        $query = CampaignUpdateReport::query()
            ->with('campaign')
            ->where('is_active', true);

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('name', 'like', '%'.$search.'%')
                    ->orWhere('short_description', 'like', '%'.$search.'%')
                    ->orWhere('report_id', 'like', '%'.$search.'%');
            });
        }

        $campaignUuid = data_get($validated, 'filters.campaign_uuid');
        if (is_string($campaignUuid) && trim($campaignUuid) !== '') {
            $query->where('campaign_uuid', trim($campaignUuid));
        }

        if (! in_array($sortBy, ['name', 'report_id', 'created_at', 'updated_at'], true)) {
            $sortBy = 'created_at';
        }

        return $query
            ->orderBy($sortBy, $sortDirection)
            ->paginate($perPage);
    }

    private function resolveCampaign(string $campaignId): Campaign
    {
        $campaign = Campaign::query()
            ->where(function (Builder $builder) use ($campaignId): void {
                $builder->where('uuid', $campaignId);
                if (is_numeric($campaignId)) {
                    $builder->orWhere('id', (int) $campaignId);
                }
                $builder->orWhere('campaign_id', $campaignId);
            })
            ->first();

        if ($campaign === null) {
            throw (new ModelNotFoundException)->setModel(Campaign::class, [$campaignId]);
        }

        return $campaign;
    }

    private function resolveReportForCampaign(Campaign $campaign, string $reportId): CampaignUpdateReport
    {
        $report = CampaignUpdateReport::query()
            ->where('campaign_uuid', $campaign->uuid)
            ->where(function (Builder $builder) use ($reportId): void {
                $builder->where('uuid', $reportId)
                    ->orWhere('report_id', $reportId);
                if (is_numeric($reportId)) {
                    $builder->orWhere('id', (int) $reportId);
                }
            })
            ->first();

        if ($report === null) {
            throw (new ModelNotFoundException)->setModel(CampaignUpdateReport::class, [$reportId]);
        }

        return $report;
    }

    private function resolveReport(string $reportId): CampaignUpdateReport
    {
        $report = CampaignUpdateReport::query()
            ->where(function (Builder $builder) use ($reportId): void {
                $builder->where('uuid', $reportId)
                    ->orWhere('report_id', $reportId);
                if (is_numeric($reportId)) {
                    $builder->orWhere('id', (int) $reportId);
                }
            })
            ->first();

        if ($report === null) {
            throw (new ModelNotFoundException)->setModel(CampaignUpdateReport::class, [$reportId]);
        }

        return $report;
    }

    private function generateReportPublicId(): string
    {
        $prefix = 'RPT-';
        $pool = '0123456789';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $suffix = '';
            for ($i = 0; $i < 6; $i++) {
                $suffix .= $pool[random_int(0, strlen($pool) - 1)];
            }

            $reportId = $prefix.$suffix;
            if (! CampaignUpdateReport::query()->where('report_id', $reportId)->exists()) {
                return $reportId;
            }
        }

        throw new ApiException('Could not generate unique report ID.', 422);
    }

    private function uploadBanner(mixed $input): string
    {
        if ($input === null || $input === '') {
            throw new ApiException('Report banner is required.', 422);
        }

        try {
            $url = FileUploadHelper::smartSingleFileUpload($input, self::UPLOAD_FOLDER);
        } catch (InvalidArgumentException $e) {
            throw new ApiException('Report banner upload failed: '.$e->getMessage(), 422);
        }

        if ($url === null || $url === '') {
            throw new ApiException('Report banner upload failed.', 422);
        }

        return $url;
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
