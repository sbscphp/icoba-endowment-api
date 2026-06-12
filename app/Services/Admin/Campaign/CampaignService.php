<?php

namespace App\Services\Admin\Campaign;

use App\Enums\AuditActionEnum;
use App\Enums\CampaignStatus;
use App\Enums\ModuleEnums;
use App\Enums\PledgeStatus;
use App\Enums\TransactionStatus;
use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\FileUploadHelper;
use App\Helpers\GeneralHelper;
use App\Helpers\PDFReportHelper;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\Admin;
use App\Models\Campaign;
use App\Models\CampaignStatusLog;
use App\Models\GraduationSet;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CampaignService
{
    private const UPLOAD_FOLDER = 'campaigns';

    private const MAX_EXPORT_ROWS = 5000;

    public function __construct(
        private readonly PDFReportHelper $pdfReportHelper,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Admin $actor, Request $request): Campaign
    {
        return DB::transaction(function () use ($data, $actor, $request): Campaign {
            $campaignId = $this->generateCampaignPublicId();
            $cover = $this->uploadCover($data['cover_image'] ?? null);
            $gallery = $this->uploadGallery($data['gallery_images'] ?? []);

            $appliesToAll = (bool) ($data['applies_to_all_graduation_sets'] ?? true);
            $setUuids = $appliesToAll ? [] : array_values(array_filter((array) ($data['graduation_set_uuids'] ?? [])));

            $campaign = Campaign::query()->create([
                'campaign_id' => $campaignId,
                'name' => (string) $data['name'],
                'short_description' => (string) $data['short_description'],
                'long_description' => (string) $data['long_description'],
                'cover_image' => $cover,
                'gallery_images' => array_values($gallery),
                'categories' => array_values((array) $data['categories']),
                'base_currency' => (string) $data['base_currency'],
                'available_donation_currencies' => array_values((array) $data['available_donation_currencies']),
                'target_amount' => (string) $data['target_amount'],
                'start_date' => (string) $data['start_date'],
                'end_date' => (string) $data['end_date'],
                'allow_anonymous_donation' => (bool) ($data['allow_anonymous_donation'] ?? false),
                'allow_public_donation' => (bool) ($data['allow_public_donation'] ?? false),
                'applies_to_all_graduation_sets' => $appliesToAll,
                'status' => CampaignStatus::ACTIVE,
                'created_by_admin_uuid' => $actor->uuid,
            ]);

            if (! $appliesToAll && $setUuids !== []) {
                $campaign->graduationSets()->sync($setUuids);
            }

            $this->writeStatusLog(
                $campaign,
                null,
                CampaignStatus::DRAFT,
                $actor->uuid,
                'Campaign created.',
                [
                    'snapshot_actual_start_date' => null,
                    'snapshot_actual_end_date' => null,
                ]
            );

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::CAMPAIGN_CREATED,
                $request,
                $actor->uuid,
                ['campaign_uuid' => $campaign->uuid],
                'Campaign created.',
                Campaign::class,
                $campaign->uuid,
                ModuleEnums::campaign_management,
                200,
            );

            return $campaign->fresh() ?? $campaign;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $campaignId, array $data, Admin $actor, Request $request): Campaign
    {
        $campaign = $this->resolveCampaign($campaignId);

        if ($campaign->status !== CampaignStatus::DRAFT) {
            $data = $this->onlyKeys($data, [
                'short_description', 'long_description', 'cover_image', 'gallery_images',
            ]);
        }

        return DB::transaction(function () use ($campaign, $data, $actor, $request): Campaign {
            $updates = [];

            if (array_key_exists('name', $data)) {
                $updates['name'] = (string) $data['name'];
            }
            if (array_key_exists('short_description', $data)) {
                $updates['short_description'] = (string) $data['short_description'];
            }
            if (array_key_exists('long_description', $data)) {
                $updates['long_description'] = (string) $data['long_description'];
            }
            if (array_key_exists('cover_image', $data)) {
                $updates['cover_image'] = $this->uploadCover($data['cover_image'] ?? null);
            }
            if (array_key_exists('gallery_images', $data)) {
                $updates['gallery_images'] = array_values($this->uploadGallery((array) ($data['gallery_images'] ?? [])));
            }
            if (array_key_exists('categories', $data) && $campaign->status === CampaignStatus::DRAFT) {
                $updates['categories'] = array_values((array) $data['categories']);
            }
            if (array_key_exists('base_currency', $data) && $campaign->status === CampaignStatus::DRAFT) {
                $updates['base_currency'] = (string) $data['base_currency'];
            }
            if (array_key_exists('available_donation_currencies', $data) && $campaign->status === CampaignStatus::DRAFT) {
                $updates['available_donation_currencies'] = array_values((array) $data['available_donation_currencies']);
            }
            if (array_key_exists('target_amount', $data) && $campaign->status === CampaignStatus::DRAFT) {
                $updates['target_amount'] = (string) $data['target_amount'];
            }
            if (array_key_exists('start_date', $data) && $campaign->status === CampaignStatus::DRAFT) {
                $updates['start_date'] = (string) $data['start_date'];
            }
            if (array_key_exists('end_date', $data) && $campaign->status === CampaignStatus::DRAFT) {
                $updates['end_date'] = (string) $data['end_date'];
            }
            if (array_key_exists('allow_anonymous_donation', $data) && $campaign->status === CampaignStatus::DRAFT) {
                $updates['allow_anonymous_donation'] = (bool) $data['allow_anonymous_donation'];
            }
            if (array_key_exists('allow_public_donation', $data) && $campaign->status === CampaignStatus::DRAFT) {
                $updates['allow_public_donation'] = (bool) $data['allow_public_donation'];
            }
            if (array_key_exists('applies_to_all_graduation_sets', $data) && $campaign->status === CampaignStatus::DRAFT) {
                $updates['applies_to_all_graduation_sets'] = (bool) $data['applies_to_all_graduation_sets'];
            }

            if ($updates !== []) {
                $campaign->fill($updates)->save();
            }

            if ($campaign->status === CampaignStatus::DRAFT) {
                if (array_key_exists('applies_to_all_graduation_sets', $data) || array_key_exists('graduation_set_uuids', $data)) {
                    if ($campaign->applies_to_all_graduation_sets) {
                        $campaign->graduationSets()->sync([]);
                    } else {
                        $setUuids = array_key_exists('graduation_set_uuids', $data)
                            ? array_values(array_filter((array) $data['graduation_set_uuids']))
                            : $campaign->graduationSets->pluck('uuid')->all();
                        $campaign->graduationSets()->sync($setUuids);
                    }
                }
            }

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::CAMPAIGN_UPDATED,
                $request,
                $actor->uuid,
                ['campaign_uuid' => $campaign->uuid, 'fields' => array_keys($updates)],
                'Campaign updated.',
                Campaign::class,
                $campaign->uuid,
                ModuleEnums::campaign_management,
                200,
            );

            return $campaign->fresh() ?? $campaign;
        });
    }

    public function activate(string $campaignId, ?string $reason, Admin $actor, Request $request): Campaign
    {
        $campaign = $this->resolveCampaign($campaignId);

        if (! in_array($campaign->status, [CampaignStatus::DRAFT, CampaignStatus::DEACTIVATED], true)) {
            throw new ApiException('Only draft or deactivated campaigns can be activated.', 422);
        }

        return DB::transaction(function () use ($campaign, $reason, $actor, $request): Campaign {
            $from = $campaign->status;
            $campaign->status = CampaignStatus::ACTIVE;
            if ($campaign->actual_start_date === null) {
                $campaign->actual_start_date = now();
            }
            if ($from === CampaignStatus::DEACTIVATED) {
                $campaign->actual_end_date = null;
            }
            $campaign->save();

            $this->writeStatusLog(
                $campaign,
                $from,
                CampaignStatus::ACTIVE,
                $actor->uuid,
                $reason,
                [
                    'snapshot_actual_start_date' => $campaign->actual_start_date,
                    'snapshot_actual_end_date' => $campaign->actual_end_date,
                ]
            );

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::CAMPAIGN_ACTIVATED,
                $request,
                $actor->uuid,
                ['reason' => $reason],
                'Campaign activated.',
                Campaign::class,
                $campaign->uuid,
                ModuleEnums::campaign_management,
                200,
            );

            return $campaign->fresh() ?? $campaign;
        });
    }

    public function pause(string $campaignId, ?string $reason, Admin $actor, Request $request): Campaign
    {
        $campaign = $this->resolveCampaign($campaignId);

        if ($campaign->status !== CampaignStatus::ACTIVE) {
            throw new ApiException('Only active campaigns can be paused.', 422);
        }

        return $this->transitionStatus($campaign, CampaignStatus::PAUSED, $reason, $actor, $request, AuditActionEnum::CAMPAIGN_PAUSED, 'Campaign paused.');
    }

    public function resume(string $campaignId, ?string $reason, Admin $actor, Request $request): Campaign
    {
        $campaign = $this->resolveCampaign($campaignId);

        if ($campaign->status !== CampaignStatus::PAUSED) {
            throw new ApiException('Only paused campaigns can be resumed.', 422);
        }

        return DB::transaction(function () use ($campaign, $reason, $actor, $request): Campaign {
            $from = $campaign->status;
            $campaign->status = CampaignStatus::ACTIVE;
            if ($campaign->actual_start_date === null) {
                $campaign->actual_start_date = now();
            }
            $campaign->save();

            $this->writeStatusLog(
                $campaign,
                $from,
                CampaignStatus::ACTIVE,
                $actor->uuid,
                $reason,
                [
                    'snapshot_actual_start_date' => $campaign->actual_start_date,
                    'snapshot_actual_end_date' => $campaign->actual_end_date,
                ]
            );

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::CAMPAIGN_RESUMED,
                $request,
                $actor->uuid,
                ['reason' => $reason],
                'Campaign resumed.',
                Campaign::class,
                $campaign->uuid,
                ModuleEnums::campaign_management,
                200,
            );

            return $campaign->fresh() ?? $campaign;
        });
    }

    public function deactivate(string $campaignId, ?string $reason, Admin $actor, Request $request): Campaign
    {
        $campaign = $this->resolveCampaign($campaignId);

        if (! in_array($campaign->status, [CampaignStatus::ACTIVE, CampaignStatus::PAUSED], true)) {
            throw new ApiException('Campaign cannot be deactivated in its current state.', 422);
        }

        return DB::transaction(function () use ($campaign, $reason, $actor, $request): Campaign {
            $from = $campaign->status;
            $campaign->status = CampaignStatus::DEACTIVATED;
            $campaign->actual_end_date = now();
            $campaign->save();

            $this->writeStatusLog(
                $campaign,
                $from,
                CampaignStatus::DEACTIVATED,
                $actor->uuid,
                $reason,
                [
                    'snapshot_actual_start_date' => $campaign->actual_start_date,
                    'snapshot_actual_end_date' => $campaign->actual_end_date,
                ]
            );

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::CAMPAIGN_DEACTIVATED,
                $request,
                $actor->uuid,
                ['reason' => $reason],
                'Campaign deactivated.',
                Campaign::class,
                $campaign->uuid,
                ModuleEnums::campaign_management,
                200,
            );

            return $campaign->fresh() ?? $campaign;
        });
    }

    public function complete(string $campaignId, ?Admin $actor, string $reason, Request $request): Campaign
    {
        $campaign = $this->resolveCampaign($campaignId);

        if (! in_array($campaign->status, [CampaignStatus::ACTIVE, CampaignStatus::PAUSED], true)) {
            throw new ApiException('Campaign cannot be completed in its current state.', 422);
        }

        return DB::transaction(function () use ($campaign, $actor, $reason, $request): Campaign {
            $campaign->refresh();

            if (! in_array($campaign->status, [CampaignStatus::ACTIVE, CampaignStatus::PAUSED], true)) {
                return $campaign;
            }

            $from = $campaign->status;
            $campaign->status = CampaignStatus::COMPLETED;
            $campaign->actual_end_date = now();
            $campaign->save();

            $actorUuid = $actor?->uuid;

            $this->writeStatusLog(
                $campaign,
                $from,
                CampaignStatus::COMPLETED,
                $actorUuid,
                $reason,
                [
                    'snapshot_actual_start_date' => $campaign->actual_start_date,
                    'snapshot_actual_end_date' => $campaign->actual_end_date,
                ]
            );

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::CAMPAIGN_COMPLETED,
                $request,
                $actorUuid,
                ['reason' => $reason],
                'Campaign completed.',
                Campaign::class,
                $campaign->uuid,
                ModuleEnums::campaign_management,
                200,
            );

            return $campaign->fresh() ?? $campaign;
        });
    }

    /**
     * @return array<string, int>
     */
    public function delete(string $campaignId): array
    {
        $campaign = $this->resolveCampaign($campaignId);

        if ($campaign->status !== CampaignStatus::DRAFT) {
            return ['blocked' => 1, 'transactions_count' => $campaign->transactions()->count()];
        }

        $count = $campaign->transactions()->count();

        if ($count > 0) {
            return ['blocked' => 1, 'transactions_count' => $count];
        }

        $uuid = $campaign->uuid;
        $campaign->delete();

        return ['blocked' => 0, 'transactions_count' => 0, 'uuid' => $uuid];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function stats(array $validated): array
    {
        $query = Campaign::query();
        $this->applyDateRange($query, $validated, 'created_at');

        return array_merge(ListingFilterRules::periodMeta($validated), [
            'total_count' => (clone $query)->count(),
            'active_count' => (clone $query)->where('status', CampaignStatus::ACTIVE)->count(),
            'completed_count' => (clone $query)->where('status', CampaignStatus::COMPLETED)->count(),
            'paused_count' => (clone $query)->where('status', CampaignStatus::PAUSED)->count(),
            'deactivated_count' => (clone $query)->where('status', CampaignStatus::DEACTIVATED)->count(),
            'draft_count' => (clone $query)->where('status', CampaignStatus::DRAFT)->count(),
        ]);
    }

    public function findCampaign(string $campaignId): Campaign
    {
        return $this->resolveCampaign($campaignId);
    }

    /**
     * Merge successful-contribution sums onto the campaign (preserves eager loads).
     *
     * @param  array<string, mixed>  $filters  e.g. ['raised_currency' => 'USD']
     */
    public function hydrateCampaignRaiseTotals(Campaign $campaign, array $filters = []): Campaign
    {
        $validated = ['filters' => $filters];
        $query = Campaign::query()->where('uuid', $campaign->uuid);
        $this->applyTransactionRaisedAggregates($query, $validated);
        $this->applyPledgeCommittedAggregates($query, $validated);
        $row = $query->first();

        if ($row === null) {
            return $campaign;
        }

        $campaign->setAttribute('successful_contributions_sum_naira', $row->successful_contributions_sum_naira);
        $campaign->setAttribute('total_raised_filtered', $row->total_raised_filtered);
        $campaign->setAttribute('successful_transactions_count', $row->successful_transactions_count);
        $campaign->setAttribute('pledges_committed_sum_naira', $row->pledges_committed_sum_naira);
        $campaign->setAttribute('pledges_committed_filtered', $row->pledges_committed_filtered);
        $campaign->setAttribute('pledges_count', $row->pledges_count);

        return $campaign;
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
     * @return array{0: EloquentCollection<int, Campaign>, 1: bool}
     */
    public function exportCollection(array $validated): array
    {
        $query = $this->baseListQuery($validated);
        $total = (clone $query)->count();
        $truncated = $total > self::MAX_EXPORT_ROWS;
        /** @var EloquentCollection<int, Campaign> $rows */
        $rows = $query->limit(self::MAX_EXPORT_ROWS)->get();

        return [$rows, $truncated];
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function statusLogsPaginated(string $campaignId, array $validated): LengthAwarePaginator
    {
        $campaign = $this->resolveCampaign($campaignId);
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        return CampaignStatusLog::query()
            ->where('campaign_uuid', $campaign->uuid)
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    /**
     * @return Collection<int, Campaign>
     */
    public function dropdown(?string $status = 'all'): Collection
    {
        $query = Campaign::query()->orderBy('name');

        // if ($status === 'draft') {
        //     $query->where('status', CampaignStatus::DRAFT);
        // }

        if ($status === 'active') {
            $query->where('status', CampaignStatus::ACTIVE);
        } elseif ($status === 'paused') {
            $query->where('status', CampaignStatus::PAUSED);
        } elseif ($status === 'completed') {
            $query->where('status', CampaignStatus::COMPLETED);
        } elseif ($status === 'deactivated') {
            $query->where('status', CampaignStatus::DEACTIVATED);
        } else {
            $query->where('status', '!=', CampaignStatus::DRAFT);
        }

        return $query->get(['uuid', 'name', 'campaign_id', 'status']);
    }

    /**
     * @return Collection<int, GraduationSet>
     */
    public function applicableOptions(?string $status = 'all'): Collection
    {
        return GraduationSet::query()
            ->orderBy('name')
            ->get(['uuid', 'name', 'set_number']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function generateReport(string $campaignId, Admin $actor, Request $request, array $validated): Response|StreamedResponse
    {
        $campaign = $this->resolveCampaign($campaignId);
        $format = strtolower((string) ($validated['format'] ?? 'pdf'));
        $start = $validated['start_date'] ?? null;
        $end = $validated['end_date'] ?? null;

        $txQuery = Transaction::query()->where('campaign_uuid', $campaign->uuid);

        if (! empty($start)) {
            $txQuery->where('created_at', '>=', Carbon::parse((string) $start)->startOfDay());
        }
        if (! empty($end)) {
            $txQuery->where('created_at', '<=', Carbon::parse((string) $end)->endOfDay());
        }

        $transactions = $txQuery->orderByDesc('created_at')->limit(10000)->get();

        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::CAMPAIGN_REPORT_GENERATED,
            $request,
            $actor->uuid,
            ['campaign_uuid' => $campaign->uuid, 'format' => $format],
            'Campaign report generated.',
            Campaign::class,
            $campaign->uuid,
            ModuleEnums::campaign_management,
            200,
        );

        $heading = [
            'Campaign' => $campaign->name,
            'Code' => $campaign->campaign_id,
            'Status' => $campaign->status->value,
        ];

        $rows = $transactions->map(fn (Transaction $t): array => [
            $t->transaction_id,
            $t->created_at?->format('Y-m-d H:i') ?? '',
            $t->status->value,
            (string) $t->amount,
            $t->currency,
            $t->amount_in_naira !== null ? (string) $t->amount_in_naira : '',
            (string) ($t->donor_email ?? ''),
        ])->values()->all();

        $headings = ['Transaction ID', 'Created', 'Status', 'Amount', 'Currency', 'Amount (NGN)', 'Donor email'];

        if ($format === 'csv') {
            $filename = 'campaign-report-'.$campaign->campaign_id.'-'.now()->format('Y-m-d-His').'.csv';

            return response()->streamDownload(function () use ($rows, $headings, $heading): void {
                $out = fopen('php://output', 'w');
                if ($out === false) {
                    return;
                }
                fwrite($out, "\xEF\xBB\xBF");
                foreach ($heading as $k => $v) {
                    fputcsv($out, [$k, $v]);
                }
                fputcsv($out, []);
                fputcsv($out, $headings);
                foreach ($rows as $row) {
                    fputcsv($out, $row);
                }
                fclose($out);
            }, $filename, [
                'Content-Type' => 'text/csv; charset=UTF-8',
            ]);
        }

        $filename = 'campaign-report-'.$campaign->campaign_id.'-'.now()->format('Y-m-d-His').'.pdf';
        $periodStart = ! empty($start) ? Carbon::parse((string) $start)->toDateString() : 'All dates';
        $periodEnd = ! empty($end) ? Carbon::parse((string) $end)->toDateString() : 'All dates';

        return $this->pdfReportHelper->download(
            rows: $rows,
            headings: $headings,
            title: 'Campaign: '.$campaign->name,
            filename: $filename,
            orientation: 'landscape',
            periodStart: $periodStart,
            periodEnd: $periodEnd,
            truncated: count($rows) >= 10000,
            includedRows: count($rows),
        );
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function transitionStatus(
        Campaign $campaign,
        CampaignStatus $to,
        ?string $reason,
        Admin $actor,
        Request $request,
        AuditActionEnum $auditAction,
        string $description,
    ): Campaign {
        return DB::transaction(function () use ($campaign, $to, $reason, $actor, $request, $auditAction, $description): Campaign {
            $from = $campaign->status;
            $campaign->status = $to;
            $campaign->save();

            $this->writeStatusLog(
                $campaign,
                $from,
                $to,
                $actor->uuid,
                $reason,
                [
                    'snapshot_actual_start_date' => $campaign->actual_start_date,
                    'snapshot_actual_end_date' => $campaign->actual_end_date,
                ]
            );

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                $auditAction,
                $request,
                $actor->uuid,
                ['reason' => $reason],
                $description,
                Campaign::class,
                $campaign->uuid,
                ModuleEnums::campaign_management,
                200,
            );

            return $campaign->fresh() ?? $campaign;
        });
    }

    private function writeStatusLog(
        Campaign $campaign,
        ?CampaignStatus $from,
        CampaignStatus $to,
        ?string $actorUuid,
        ?string $reason,
        array $snapshots,
    ): void {
        CampaignStatusLog::query()->create([
            'campaign_uuid' => $campaign->uuid,
            'from_status' => $from?->value,
            'to_status' => $to->value,
            'actor_admin_uuid' => $actorUuid,
            'reason' => $reason,
            'snapshot_actual_start_date' => $snapshots['snapshot_actual_start_date'] ?? null,
            'snapshot_actual_end_date' => $snapshots['snapshot_actual_end_date'] ?? null,
        ]);
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

    private function generateCampaignPublicId(): string
    {
        $result = GeneralHelper::getModelUniqueRandomId([
            'modelNamespace' => Campaign::class,
            'modelField' => 'campaign_id',
            'prefix' => 'ICB-',
            'idLength' => 4,
            'idType' => 'numalpha_upper',
        ]);

        if (is_array($result) && isset($result['error'])) {
            throw new ApiException('Could not generate unique campaign code.', 422);
        }

        return (string) $result;
    }

    private function uploadCover(mixed $input): ?string
    {
        if ($input === null || $input === '') {
            return null;
        }

        try {
            return FileUploadHelper::smartSingleFileUpload($input, self::UPLOAD_FOLDER);
        } catch (InvalidArgumentException $e) {
            throw new ApiException('File upload failed: '.$e->getMessage(), 422);
        }
    }

    /**
     * @param  array<int, mixed>  $inputs
     * @return list<?string>
     */
    private function uploadGallery(array $inputs): array
    {
        if ($inputs === []) {
            return [];
        }

        try {
            return FileUploadHelper::smartMultipleFileUpload($inputs, self::UPLOAD_FOLDER);
        } catch (InvalidArgumentException $e) {
            throw new ApiException('Gallery upload failed: '.$e->getMessage(), 422);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, mixed>
     */
    private function onlyKeys(array $data, array $keys): array
    {
        return array_intersect_key($data, array_flip($keys));
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(array $validated): Builder
    {
        $dateColumn = data_get($validated, 'filters.date_field') === 'start_date' ? 'start_date' : 'created_at';

        $query = Campaign::query();
        $this->applyTransactionRaisedAggregates($query, $validated);

        $this->applyDateRange($query, $validated, $dateColumn);

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.strtolower($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->whereRaw('LOWER(name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(campaign_id) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(short_description) LIKE ?', [$like]);
            });
        }

        $statusFilter = data_get($validated, 'filters.status');
        if (is_string($statusFilter) && $statusFilter !== '') {
            $query->where('status', $statusFilter);
        }

        $category = data_get($validated, 'filters.category');
        if (is_string($category) && $category !== '') {
            $query->whereJsonContains('categories', $category);
        }

        $currency = data_get($validated, 'filters.currency');
        if (is_string($currency) && $currency !== '') {
            $query->where(function (Builder $b) use ($currency): void {
                $b->where('base_currency', $currency)
                    ->orWhereJsonContains('available_donation_currencies', $currency);
            });
        }

        $setUuid = data_get($validated, 'filters.graduation_set_uuid');
        if (is_string($setUuid) && $setUuid !== '') {
            $query->whereHas('graduationSets', fn (Builder $b) => $b->where('uuid', $setUuid));
        }

        $creator = data_get($validated, 'filters.created_by_admin_uuid');
        if (is_string($creator) && $creator !== '') {
            $query->where('created_by_admin_uuid', $creator);
        }

        $this->applyMinTotalRaisedFilter($query, $validated);

        $sortBy = (string) ($validated['sort_by'] ?? 'updated_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        if (! in_array($sortBy, ['name', 'start_date', 'end_date', 'target_amount', 'status', 'created_at', 'updated_at'], true)) {
            $sortBy = 'updated_at';
        }

        return $query->orderBy($sortBy, $sortDirection);
    }

    /**
     * Successful transactions: unified NGN (amount_in_naira) on all rows + optional native currency sum for list display.
     *
     * @param  array<string, mixed>  $validated
     */
    /**
     * Non-cancelled pledges: committed totals for campaign progress.
     *
     * @param  array<string, mixed>  $validated
     */
    private function applyPledgeCommittedAggregates(Builder $query, array $validated): void
    {
        $window = ListingFilterRules::resolveDateWindow($validated);

        $activePledges = function (Builder $q) use ($window): void {
            $q->where('status', '!=', PledgeStatus::CANCELLED);
            $this->applyPledgeCreatedAtWindow($q, $window['start'], $window['end']);
        };

        $query->withSum([
            'pledges as pledges_committed_sum_naira' => $activePledges,
        ], 'committed_amount_ngn');

        $query->withCount([
            'pledges as pledges_count' => $activePledges,
        ]);

        $pledgeCurrency = strtoupper((string) data_get($validated, 'filters.raised_currency', 'NGN'));
        if ($pledgeCurrency === '') {
            $pledgeCurrency = 'NGN';
        }

        if ($pledgeCurrency === 'NGN') {
            $query->withSum([
                'pledges as pledges_committed_filtered' => $activePledges,
            ], 'committed_amount_ngn');

            return;
        }

        $query->withSum([
            'pledges as pledges_committed_filtered' => function (Builder $q) use ($pledgeCurrency, $window): void {
                $q->where('status', '!=', PledgeStatus::CANCELLED)
                    ->where('currency', $pledgeCurrency);
                $this->applyPledgeCreatedAtWindow($q, $window['start'], $window['end']);
            },
        ], 'committed_amount');
    }

    private function applyPledgeCreatedAtWindow(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        if ($start !== null) {
            $query->where('created_at', '>=', $start);
        }

        if ($end !== null) {
            $query->where('created_at', '<=', $end);
        }
    }

    private function applyTransactionRaisedAggregates(Builder $query, array $validated): void
    {
        $window = ListingFilterRules::resolveDateWindow($validated);

        $successfulTransactions = function (Builder $q) use ($window): void {
            $q->where('status', TransactionStatus::SUCCESSFUL);
            $this->applyTransactionCreatedAtWindow($q, $window['start'], $window['end']);
        };

        $query->withSum([
            'transactions as successful_contributions_sum_naira' => $successfulTransactions,
        ], 'amount_in_naira');

        $query->withCount([
            'transactions as successful_transactions_count' => $successfulTransactions,
        ]);

        $raisedCurrency = strtoupper((string) data_get($validated, 'filters.raised_currency', 'NGN'));
        if ($raisedCurrency === '') {
            $raisedCurrency = 'NGN';
        }

        if ($raisedCurrency === 'NGN') {
            $query->withSum([
                'transactions as total_raised_filtered' => function (Builder $q) use ($window): void {
                    $q->where('status', TransactionStatus::SUCCESSFUL);
                    $this->applyTransactionCreatedAtWindow($q, $window['start'], $window['end']);
                },
            ], 'amount_in_naira');

            return;
        }

        $query->withSum([
            'transactions as total_raised_filtered' => function (Builder $q) use ($raisedCurrency, $window): void {
                $q->where('status', TransactionStatus::SUCCESSFUL)
                    ->where('currency', $raisedCurrency);
                $this->applyTransactionCreatedAtWindow($q, $window['start'], $window['end']);
            },
        ], 'amount');
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyMinTotalRaisedFilter(Builder $query, array $validated): void
    {
        $min = data_get($validated, 'filters.min_total_raised');
        if ($min === null || $min === '') {
            return;
        }

        $minVal = is_numeric($min) ? (float) $min : null;
        if ($minVal === null) {
            return;
        }

        $raisedCurrency = strtoupper((string) data_get($validated, 'filters.raised_currency', 'NGN'));
        if ($raisedCurrency === '') {
            $raisedCurrency = 'NGN';
        }

        $statusValue = TransactionStatus::SUCCESSFUL->value;
        $window = ListingFilterRules::resolveDateWindow($validated);
        $dateSql = '';
        $dateBindings = [];
        if ($window['start'] !== null) {
            $dateSql .= ' and transactions.created_at >= ?';
            $dateBindings[] = $window['start'];
        }
        if ($window['end'] !== null) {
            $dateSql .= ' and transactions.created_at <= ?';
            $dateBindings[] = $window['end'];
        }

        if ($raisedCurrency === 'NGN') {
            $query->whereRaw(
                '(select coalesce(sum(amount_in_naira), 0) from transactions where transactions.campaign_uuid = campaigns.uuid and transactions.status = ? and transactions.deleted_at is null'.$dateSql.') >= ?',
                array_merge([$statusValue], $dateBindings, [$minVal])
            );

            return;
        }

        $query->whereRaw(
            '(select coalesce(sum(amount), 0) from transactions where transactions.campaign_uuid = campaigns.uuid and transactions.status = ? and transactions.currency = ? and transactions.deleted_at is null'.$dateSql.') >= ?',
            array_merge([$statusValue, $raisedCurrency], $dateBindings, [$minVal])
        );
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function applyDateRange(Builder $query, array $validated, string $column): void
    {
        ListingFilterRules::applyResolvedDateRange($query, $validated, $column);
    }

    private function applyTransactionCreatedAtWindow(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        if ($start !== null) {
            $query->where('created_at', '>=', $start);
        }

        if ($end !== null) {
            $query->where('created_at', '<=', $end);
        }
    }
}
