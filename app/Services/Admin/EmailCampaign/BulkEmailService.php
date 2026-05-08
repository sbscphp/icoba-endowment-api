<?php

namespace App\Services\Admin\EmailCampaign;

use App\Enums\AuditActionEnum;
use App\Enums\BulkEmailAudience;
use App\Enums\BulkEmailStatus;
use App\Enums\ModuleEnums;
use App\Enums\TransactionStatus;
use App\Enums\UserTypeEnum;
use App\Exceptions\ApiException;
use App\Helpers\GeneralHelper;
use App\Jobs\SendCampaignBulkEmailJob;
use App\Models\Admin;
use App\Models\Campaign;
use App\Models\CampaignEmail;
use App\Models\Transaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class BulkEmailService
{
    private const MAX_EXPORT_ROWS = 5000;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, Admin $actor, Request $request): CampaignEmail
    {
        $campaign = $this->resolveCampaign((string) $data['campaign_uuid']);
        $action = (string) $data['action'];

        $email = DB::transaction(function () use ($data, $actor, $request, $campaign): CampaignEmail {
            $email = CampaignEmail::query()->create([
                'campaign_uuid' => $campaign->uuid,
                'title' => (string) $data['title'],
                'content' => (string) $data['content'],
                'design_template' => (string) $data['design_template'],
                'recipient_audience' => $this->normalizeRecipientAudiences($data['recipient_audience']),
                'status' => BulkEmailStatus::DRAFT,
                'created_by_admin_uuid' => $actor->uuid,
            ]);

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::BULK_EMAIL_CREATED,
                $request,
                $actor->uuid,
                ['campaign_email_uuid' => $email->uuid, 'campaign_uuid' => $campaign->uuid],
                'Bulk email draft created.',
                CampaignEmail::class,
                $email->uuid,
                ModuleEnums::email_campaigns,
                200,
            );

            return $email->fresh() ?? $email;
        });

        if ($action === 'send_now') {
            $this->dispatchSendJobs($email->fresh() ?? $email, $actor, $request);
        }

        return $email->fresh() ?? $email;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(string $emailId, array $data, Admin $actor, Request $request): CampaignEmail
    {
        $email = $this->resolveEmail($emailId);

        if ($email->status !== BulkEmailStatus::DRAFT) {
            throw new ApiException('Only draft email campaigns can be updated.', 422);
        }

        $updates = [];
        if (array_key_exists('campaign_uuid', $data)) {
            $updates['campaign_uuid'] = $this->resolveCampaign((string) $data['campaign_uuid'])->uuid;
        }
        if (array_key_exists('title', $data)) {
            $updates['title'] = (string) $data['title'];
        }
        if (array_key_exists('content', $data)) {
            $updates['content'] = (string) $data['content'];
        }
        if (array_key_exists('design_template', $data)) {
            $updates['design_template'] = (string) $data['design_template'];
        }
        if (array_key_exists('recipient_audience', $data)) {
            $updates['recipient_audience'] = $this->normalizeRecipientAudiences($data['recipient_audience']);
        }

        if ($updates !== []) {
            $email->fill($updates)->save();
        }

        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::BULK_EMAIL_CREATED,
            $request,
            $actor->uuid,
            ['campaign_email_uuid' => $email->uuid, 'updated' => true],
            'Bulk email updated.',
            CampaignEmail::class,
            $email->uuid,
            ModuleEnums::email_campaigns,
            200,
        );

        return $email->fresh() ?? $email;
    }

    public function send(string $emailId, Admin $actor, Request $request): CampaignEmail
    {
        $email = $this->resolveEmail($emailId);

        if ($email->status !== BulkEmailStatus::DRAFT) {
            throw new ApiException('Only draft email campaigns can be sent.', 422);
        }

        $this->dispatchSendJobs($email, $actor, $request);

        return $email->fresh() ?? $email;
    }

    public function setActive(string $emailId, Admin $actor, Request $request): CampaignEmail
    {
        $email = $this->resolveEmail($emailId);

        return DB::transaction(function () use ($email, $actor, $request): CampaignEmail {
            CampaignEmail::query()
                ->where('campaign_uuid', $email->campaign_uuid)
                ->update(['is_active' => false]);

            $email->forceFill(['is_active' => true])->save();

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::BULK_EMAIL_SET_ACTIVE,
                $request,
                $actor->uuid,
                ['campaign_email_uuid' => $email->uuid],
                'Bulk email marked active for campaign.',
                CampaignEmail::class,
                $email->uuid,
                ModuleEnums::email_campaigns,
                200,
            );

            return $email->fresh() ?? $email;
        });
    }

    /**
     * @return array<string, int>
     */
    public function delete(string $emailId): array
    {
        $email = $this->resolveEmail($emailId);

        if (! in_array($email->status, [BulkEmailStatus::DRAFT, BulkEmailStatus::FAILED], true)) {
            return ['blocked' => 1];
        }

        $uuid = $email->uuid;
        $email->delete();

        return ['blocked' => 0, 'uuid' => $uuid];
    }

    public function findEmail(string $emailId): CampaignEmail
    {
        return $this->resolveEmail($emailId);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function list(array $validated): LengthAwarePaginator
    {
        $query = $this->baseListQuery($validated);
        $perPage = max(1, min((int) ($validated['per_page'] ?? 15), 100));

        return $query->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{0: EloquentCollection<int, CampaignEmail>, 1: bool}
     */
    public function exportCollection(array $validated): array
    {
        $query = $this->baseListQuery($validated);
        $total = (clone $query)->count();
        $truncated = $total > self::MAX_EXPORT_ROWS;
        /** @var EloquentCollection<int, CampaignEmail> $rows */
        $rows = $query->limit(self::MAX_EXPORT_ROWS)->get();

        return [$rows, $truncated];
    }

    /**
     * @return Collection<int, CampaignEmail>
     */
    public function dropdown(?string $status = 'all'): Collection
    {
        $query = CampaignEmail::query()->orderByDesc('updated_at');

        if ($status === 'draft') {
            $query->where('status', BulkEmailStatus::DRAFT);
        } elseif ($status === 'queued') {
            $query->where('status', BulkEmailStatus::QUEUED);
        } elseif ($status === 'sent') {
            $query->where('status', BulkEmailStatus::SENT);
        } elseif ($status === 'partially_sent') {
            $query->where('status', BulkEmailStatus::PARTIALLY_SENT);
        } elseif ($status === 'failed') {
            $query->where('status', BulkEmailStatus::FAILED);
        }

        return $query->get(['uuid', 'title', 'campaign_uuid', 'status', 'is_active']);
    }

    private function dispatchSendJobs(CampaignEmail $email, Admin $actor, Request $request): void
    {
        $recipients = $this->resolveRecipients($email);
        $unique = $recipients->unique('email')->filter(fn (array $r) => $r['email'] !== '' && $r['email'] !== null)->values();
        $addresses = $unique->pluck('email')->all();

        $email->forceFill([
            'total_recipients' => count($addresses),
            'successful_count' => 0,
            'failed_count' => 0,
            'status' => BulkEmailStatus::QUEUED,
            'sent_by_admin_uuid' => $actor->uuid,
        ])->save();

        GeneralHelper::storeAuditLog(
            UserTypeEnum::ADMIN,
            AuditActionEnum::BULK_EMAIL_SENT,
            $request,
            $actor->uuid,
            [
                'campaign_email_uuid' => $email->uuid,
                'recipient_count' => count($addresses),
            ],
            'Bulk email queued for sending.',
            CampaignEmail::class,
            $email->uuid,
            ModuleEnums::email_campaigns,
            200,
        );

        if (count($addresses) === 0) {
            $email->forceFill([
                'status' => BulkEmailStatus::SENT,
                'sent_at' => now(),
            ])->save();

            return;
        }

        foreach ($unique as $recipient) {
            SendCampaignBulkEmailJob::dispatch(
                $email->uuid,
                (string) $recipient['email'],
                $recipient['name'] ?? null
            );
        }
    }

    /**
     * @return Collection<int, array{email: string, name: ?string}>
     */
    private function resolveRecipients(CampaignEmail $email): Collection
    {
        $keys = $email->recipient_audience;
        if (! is_array($keys) || $keys === []) {
            return collect();
        }

        $allDonorsValue = BulkEmailAudience::ALL_DONORS->value;
        foreach ($keys as $key) {
            if ((string) $key === $allDonorsValue) {
                return $this->resolveRecipientsForAudience($email, BulkEmailAudience::ALL_DONORS)
                    ->filter(fn (array $r) => $r['email'] !== '' && $r['email'] !== null)
                    ->unique(fn (array $r) => strtolower((string) $r['email']))
                    ->values();
            }
        }

        $merged = collect();
        foreach ($keys as $key) {
            $audience = BulkEmailAudience::tryFrom((string) $key);
            if ($audience === null) {
                continue;
            }
            $merged = $merged->merge($this->resolveRecipientsForAudience($email, $audience));
        }

        return $merged
            ->filter(fn (array $r) => $r['email'] !== '' && $r['email'] !== null)
            ->unique(fn (array $r) => strtolower((string) $r['email']))
            ->values();
    }

    /**
     * @return Collection<int, array{email: string, name: ?string}>
     */
    private function resolveRecipientsForAudience(CampaignEmail $email, BulkEmailAudience $audience): Collection
    {
        $campaignUuid = $email->campaign_uuid;

        if ($audience === BulkEmailAudience::MEMBERS_ONLY) {
            return User::query()
                ->whereNotNull('email')
                ->get(['email', 'firstname', 'lastname'])
                ->map(fn (User $u): array => [
                    'email' => (string) $u->email,
                    'name' => trim(implode(' ', array_filter([$u->firstname ?? '', $u->lastname ?? '']))) ?: null,
                ]);
        }

        if ($audience === BulkEmailAudience::CORPORATE) {
            return User::query()
                ->whereNotNull('email')
                ->whereHas('donorType', fn (Builder $b) => $b->where('slug', 'corporate_donor'))
                ->get(['email', 'firstname', 'lastname', 'organization_name'])
                ->map(fn (User $u): array => [
                    'email' => (string) $u->email,
                    'name' => ($u->organization_name !== null && $u->organization_name !== '')
                        ? (string) $u->organization_name
                        : (trim(implode(' ', array_filter([$u->firstname ?? '', $u->lastname ?? '']))) ?: null),
                ]);
        }

        if ($audience === BulkEmailAudience::ANONYMOUS_DONORS) {
            return Transaction::query()
                ->where('campaign_uuid', $campaignUuid)
                ->where('status', TransactionStatus::SUCCESSFUL)
                ->where('is_anonymous', true)
                ->whereNotNull('donor_email')
                ->get(['donor_email', 'donor_name'])
                ->map(fn (Transaction $t): array => [
                    'email' => (string) $t->donor_email,
                    'name' => $t->donor_name,
                ]);
        }

        if ($audience === BulkEmailAudience::FRIENDS_OF_ICOBA) {
            return Transaction::query()
                ->where('campaign_uuid', $campaignUuid)
                ->where('status', TransactionStatus::SUCCESSFUL)
                ->whereNull('user_uuid')
                ->whereNotNull('donor_email')
                ->get(['donor_email', 'donor_name'])
                ->map(fn (Transaction $t): array => [
                    'email' => (string) $t->donor_email,
                    'name' => $t->donor_name,
                ]);
        }

        if ($audience === BulkEmailAudience::ALL_DONORS) {
            $fromTransactions = Transaction::query()
                ->where('campaign_uuid', $campaignUuid)
                ->where('status', TransactionStatus::SUCCESSFUL)
                ->with(['donor'])
                ->get()
                ->flatMap(function (Transaction $t): array {
                    $rows = [];
                    if ($t->donor !== null && $t->donor->email !== null && $t->donor->email !== '') {
                        $rows[] = [
                            'email' => (string) $t->donor->email,
                            'name' => trim(implode(' ', array_filter([$t->donor->firstname ?? '', $t->donor->lastname ?? '']))) ?: null,
                        ];
                    }
                    if ($t->donor_email !== null && $t->donor_email !== '') {
                        $rows[] = [
                            'email' => (string) $t->donor_email,
                            'name' => $t->donor_name,
                        ];
                    }

                    return $rows;
                });

            $memberEmails = User::query()
                ->whereNotNull('email')
                ->get(['email', 'firstname', 'lastname'])
                ->map(fn (User $u): array => [
                    'email' => (string) $u->email,
                    'name' => trim(implode(' ', array_filter([$u->firstname ?? '', $u->lastname ?? '']))) ?: null,
                ]);

            return $fromTransactions->merge($memberEmails)->unique('email')->values();
        }

        return collect();
    }

    /**
     * @return list<string>
     */
    private function normalizeRecipientAudiences(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $v) {
            $s = (string) $v;
            if ($s === '') {
                continue;
            }
            if (BulkEmailAudience::tryFrom($s) === null) {
                continue;
            }
            $out[] = $s;
        }

        $out = array_values(array_unique($out));

        if (in_array(BulkEmailAudience::ALL_DONORS->value, $out, true)) {
            return [BulkEmailAudience::ALL_DONORS->value];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(array $validated): Builder
    {
        $sortBy = (string) ($validated['sort_by'] ?? 'updated_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
        $query = CampaignEmail::query()->with('campaign:uuid,name,campaign_id');

        $this->applyDateRange($query, $validated, 'created_at');

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('title', 'like', '%'.$search.'%')
                    ->orWhere('uuid', 'like', '%'.$search.'%');
            });
        }

        $campaignUuid = data_get($validated, 'filters.campaign_uuid');
        if (is_string($campaignUuid) && $campaignUuid !== '') {
            $query->where('campaign_uuid', $campaignUuid);
        }

        $status = data_get($validated, 'filters.status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $audience = data_get($validated, 'filters.audience');
        if (is_string($audience) && $audience !== '') {
            $query->whereJsonContains('recipient_audience', $audience);
        }

        $creator = data_get($validated, 'filters.created_by_admin_uuid');
        if (is_string($creator) && $creator !== '') {
            $query->where('created_by_admin_uuid', $creator);
        }

        $active = data_get($validated, 'filters.is_active');
        if ($active === '1' || $active === 1 || $active === true || $active === 'true') {
            $query->where('is_active', true);
        } elseif ($active === '0' || $active === 0 || $active === false || $active === 'false') {
            $query->where('is_active', false);
        }

        if (! in_array($sortBy, ['title', 'status', 'created_at', 'updated_at', 'sent_at'], true)) {
            $sortBy = 'updated_at';
        }

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

    private function resolveEmail(string $emailId): CampaignEmail
    {
        $email = CampaignEmail::query()
            ->where(function (Builder $builder) use ($emailId): void {
                $builder->where('uuid', $emailId);
                if (is_numeric($emailId)) {
                    $builder->orWhere('id', (int) $emailId);
                }
            })
            ->first();

        if ($email === null) {
            throw (new ModelNotFoundException)->setModel(CampaignEmail::class, [$emailId]);
        }

        return $email;
    }

    private function resolveCampaign(string $campaignUuid): Campaign
    {
        $campaign = Campaign::query()
            ->where('uuid', $campaignUuid)
            ->first();

        if ($campaign === null) {
            throw (new ModelNotFoundException)->setModel(Campaign::class, [$campaignUuid]);
        }

        return $campaign;
    }
}
