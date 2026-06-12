<?php

namespace App\Services\Admin\IssuedCertificate;

use App\Enums\IssuedCertificateStatus;
use App\Enums\PaymentGateway;
use App\Exceptions\ApiException;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Jobs\GenerateCertificateImageJob;
use App\Jobs\SendDonorRecognitionEmailJob;
use App\Jobs\SendDonorRecognitionRevokedEmailJob;
use App\Models\DonorRecognition;
use App\Models\TierConfiguration;
use App\Models\Transaction;
use App\Services\Recognition\DonorRecognitionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class IssuedCertificateService
{
    public const MAX_EXPORT_ROWS = 5000;

    public function __construct(
        private readonly DonorRecognitionService $donorRecognitionService,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    public function stats(array $validated): array
    {
        $dateWindow = ListingFilterRules::resolveDateWindow($validated);
        $base = DonorRecognition::query();
        $this->applyIssuedAtRange($base, $dateWindow['start'], $dateWindow['end']);

        return array_merge(ListingFilterRules::periodMeta($validated), [
            'total_count' => (clone $base)->count(),
            'issued_count' => (clone $base)->where('status', IssuedCertificateStatus::AUTO_ISSUED)->count(),
            'reissued_count' => (clone $base)->where('status', IssuedCertificateStatus::REISSUED)->count(),
            'revoked_count' => (clone $base)->where('status', IssuedCertificateStatus::REVOKED)->count(),
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
     * @return array{0: EloquentCollection<int, DonorRecognition>, 1: bool}
     */
    public function exportCollection(array $validated): array
    {
        $query = $this->baseListQuery($validated);
        $total = (clone $query)->count();
        $truncated = $total > self::MAX_EXPORT_ROWS;
        /** @var EloquentCollection<int, DonorRecognition> $rows */
        $rows = $query->limit(self::MAX_EXPORT_ROWS)->get();

        return [$rows, $truncated];
    }

    public function findRecognition(string $recognitionId): DonorRecognition
    {
        $recognition = DonorRecognition::query()
            ->where(function (Builder $builder) use ($recognitionId): void {
                $builder->where('uuid', $recognitionId)
                    ->orWhere('recognition_number', strtoupper(trim($recognitionId)));
                if (is_numeric($recognitionId)) {
                    $builder->orWhere('id', (int) $recognitionId);
                }
            })
            ->with($this->detailRelations())
            ->first();

        if ($recognition === null) {
            throw (new ModelNotFoundException)->setModel(DonorRecognition::class, [$recognitionId]);
        }

        return $recognition;
    }

    public function revoke(string $recognitionId): DonorRecognition
    {
        $recognition = DB::transaction(function () use ($recognitionId): DonorRecognition {
            $recognition = $this->lockRecognition($recognitionId);

            if ($recognition->status === IssuedCertificateStatus::REVOKED) {
                throw new ApiException('This certificate has already been revoked.', 422);
            }

            $recognition->forceFill([
                'status' => IssuedCertificateStatus::REVOKED,
                'download_token' => null,
            ])->save();

            return $recognition->fresh($this->detailRelations()) ?? $recognition;
        });

        SendDonorRecognitionRevokedEmailJob::dispatch($recognition->uuid);

        return $recognition;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function reissue(string $recognitionId, array $payload = []): DonorRecognition
    {
        return DB::transaction(function () use ($recognitionId, $payload): DonorRecognition {
            $recognition = $this->lockRecognition($recognitionId);
            $recognition->loadMissing('tier');

            $tier = $recognition->tier;
            if ($tier === null) {
                throw new ApiException('Certificate tier could not be resolved.', 422);
            }

            $template = $this->donorRecognitionService->resolveActiveTemplateForTier($tier);
            if ($template === null) {
                throw new ApiException('No active certificate template is available for this tier.', 422);
            }

            $design = is_array($template->design) ? $template->design : [];
            $awardeeName = trim((string) ($payload['awardee_name'] ?? $recognition->awardee_name));
            if ($awardeeName === '') {
                throw new ApiException('Awardee name is required to reissue a certificate.', 422);
            }

            $recognition->forceFill([
                'recognition_number' => $this->donorRecognitionService->generateUniqueRecognitionNumber(),
                'awardee_name' => $awardeeName,
                'certificate_template_uuid' => $template->uuid,
                'issued_at' => now(),
                'status' => IssuedCertificateStatus::REISSUED,
                'download_token' => Str::random(48),
                'email_sent_at' => null,
                'snapshot' => [
                    'tier_name' => $tier->name,
                    'template_name' => $template->name,
                    'design' => $design,
                    'initial_amount' => $recognition->initial_amount !== null ? (string) $recognition->initial_amount : null,
                    'initial_currency' => $recognition->initial_currency,
                    'reissued_at' => now()->toIso8601String(),
                ],
            ])->save();

            $fresh = $recognition->fresh($this->detailRelations()) ?? $recognition;

            GenerateCertificateImageJob::dispatch($fresh->uuid, force: true);

            if (($payload['send_email'] ?? true) && filled($fresh->trigger_transaction_uuid)) {
                SendDonorRecognitionEmailJob::dispatch($fresh->uuid, (string) $fresh->trigger_transaction_uuid);
            }

            return $fresh;
        });
    }

    private function lockRecognition(string $recognitionId): DonorRecognition
    {
        $recognition = DonorRecognition::query()
            ->where(function (Builder $builder) use ($recognitionId): void {
                $builder->where('uuid', $recognitionId)
                    ->orWhere('recognition_number', strtoupper(trim($recognitionId)));
                if (is_numeric($recognitionId)) {
                    $builder->orWhere('id', (int) $recognitionId);
                }
            })
            ->lockForUpdate()
            ->first();

        if ($recognition === null) {
            throw (new ModelNotFoundException)->setModel(DonorRecognition::class, [$recognitionId]);
        }

        return $recognition;
    }

    /**
     * @return list<string>
     */
    public function detailRelations(): array
    {
        return [
            'tier:uuid,name,tier_badge_url,min_amount',
            'certificateTemplate:uuid,name,is_active',
            'triggerTransaction:uuid,transaction_id,amount,currency,amount_in_naira,gateway,gateway_reference,paid_at,status',
            'user:uuid,firstname,lastname,email',
        ];
    }

    public static function paidIntoLabel(?string $gateway): ?string
    {
        if ($gateway === null || trim($gateway) === '') {
            return null;
        }

        $normalized = strtolower(trim($gateway));

        return match ($normalized) {
            PaymentGateway::Paystack->value => 'ICOBA Paystack',
            PaymentGateway::Stripe->value => 'ICOBA Stripe',
            PaymentGateway::Fcmb->value => 'ICOBA FCMB',
            'access' => 'ICOBA Access',
            default => 'ICOBA '.ucfirst($normalized),
        };
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function baseListQuery(array $validated): Builder
    {
        $query = DonorRecognition::query()->with([
            'tier:uuid,name',
            'triggerTransaction:uuid,amount,currency,amount_in_naira,gateway',
        ]);

        $dateWindow = ListingFilterRules::resolveDateWindow($validated);
        $this->applyIssuedAtRange($query, $dateWindow['start'], $dateWindow['end']);

        $search = trim((string) ($validated['search'] ?? ''));
        if ($search !== '') {
            $like = '%'.$this->escapeLike($search).'%';
            $query->where(function (Builder $builder) use ($like): void {
                $builder->where('recognition_number', 'like', $like)
                    ->orWhere('awardee_name', 'like', $like)
                    ->orWhere('donor_email', 'like', $like)
                    ->orWhere('uuid', 'like', $like);
            });
        }

        $status = data_get($validated, 'filters.status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $tierUuid = data_get($validated, 'filters.tier_uuid');
        if (is_string($tierUuid) && $tierUuid !== '') {
            $query->where('tier_uuid', $tierUuid);
        }

        $gateway = data_get($validated, 'filters.gateway');
        if (is_string($gateway) && $gateway !== '') {
            $query->whereHas('triggerTransaction', fn (Builder $builder) => $builder->where('gateway', $gateway));
        }

        $sortBy = (string) ($validated['sort_by'] ?? 'issued_at');
        $sortDirection = strtolower((string) ($validated['sort_direction'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        if ($sortBy === 'tier_name') {
            $query->orderBy(
                TierConfiguration::query()
                    ->select('name')
                    ->whereColumn('uuid', 'donor_recognitions.tier_uuid')
                    ->limit(1),
                $sortDirection,
            );
        } elseif ($sortBy === 'paid_into') {
            $query->orderBy(
                Transaction::query()
                    ->select('gateway')
                    ->whereColumn('uuid', 'donor_recognitions.trigger_transaction_uuid')
                    ->limit(1),
                $sortDirection,
            );
        } elseif (in_array($sortBy, ['recognition_number', 'awardee_name', 'issued_at', 'cumulative_amount_ngn', 'status', 'created_at', 'updated_at'], true)) {
            $query->orderBy($sortBy, $sortDirection);
        } else {
            $query->orderByDesc('issued_at');
        }

        return $query;
    }

    private function applyIssuedAtRange(Builder $query, ?Carbon $start, ?Carbon $end): void
    {
        if ($start !== null) {
            $query->where('issued_at', '>=', $start);
        }

        if ($end !== null) {
            $query->where('issued_at', '<=', $end);
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $value);
    }
}
