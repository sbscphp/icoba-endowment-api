<?php

namespace App\Services\Recognition;

use App\Enums\IssuedCertificateStatus;
use App\Enums\TransactionStatus;
use App\Helpers\GeneralHelper;
use App\Jobs\SendDonorRecognitionEmailJob;
use App\Models\CertificateTemplate;
use App\Models\DonorRecognition;
use App\Models\TierConfiguration;
use App\Models\Transaction;
use App\Http\Requests\Concerns\ListingFilterRules;
use App\Models\User;
use App\Services\Donation\DonorCumulativeTotalService;
use App\Services\Tier\TierResolutionService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DonorRecognitionService
{
    public function __construct(
        private readonly DonorCumulativeTotalService $cumulativeTotalService,
        private readonly TierResolutionService $tierResolution,
    ) {}

    /**
     * Evaluate cumulative tier recognitions after a successful donation.
     *
     * @return list<string> Recognition UUIDs newly issued
     */
    public function evaluateAfterTransaction(Transaction $transaction): array
    {
        if ($transaction->status !== TransactionStatus::SUCCESSFUL) {
            return [];
        }

        $context = $this->cumulativeTotalService->resolveContextFromTransaction($transaction);
        if ($context['is_anonymous'] || blank($context['awardee_name'])) {
            return [];
        }

        $donorKey = (string) $context['donor_key'];
        $cumulativeTotal = $this->cumulativeTotalService->cumulativeTotalNgnForDonorKey($donorKey);
        $qualifiedTiers = $this->tierResolution->resolveQualifiedTiersForCumulativeAmount($cumulativeTotal);

        if ($qualifiedTiers->isEmpty()) {
            return [];
        }

        $existingTierUuids = DonorRecognition::query()
            ->where('donor_key', $donorKey)
            ->pluck('tier_uuid')
            ->all();

        $issuedRecognitionUuids = [];

        foreach ($qualifiedTiers as $tier) {
            if (in_array($tier->uuid, $existingTierUuids, true)) {
                continue;
            }

            $template = $this->resolveActiveTemplateForTier($tier);
            if ($template === null) {
                continue;
            }

            $recognition = $this->issueRecognitionForTier(
                donorKey: $donorKey,
                userUuid: $context['user_uuid'],
                donorEmail: $context['donor_email'],
                awardeeName: (string) $context['awardee_name'],
                tier: $tier,
                template: $template,
                cumulativeTotal: $cumulativeTotal,
                triggerTransaction: $transaction,
            );

            if ($recognition !== null) {
                $issuedRecognitionUuids[] = $recognition->uuid;
                SendDonorRecognitionEmailJob::dispatch($recognition->uuid, $transaction->uuid);
            }
        }

        return $issuedRecognitionUuids;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, DonorRecognition>
     */
    public function listForUser(User $user, array $filters = []): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $perPage = max(1, min((int) ($filters['per_page'] ?? 15), 100));
        $email = strtolower(trim((string) $user->email));

        $query = DonorRecognition::query()
            ->with(['tier:uuid,name,tier_badge_url,min_amount', 'certificateTemplate:uuid,name'])
            ->where(function ($query) use ($user, $email): void {
                $query->where('user_uuid', $user->uuid);
                if ($email !== '') {
                    $query->orWhere(function ($inner) use ($email): void {
                        $inner->whereNull('user_uuid')
                            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$email]);
                    });
                }
            });

        $dateWindow = ListingFilterRules::resolveDateWindow($filters);
        if ($dateWindow['start'] !== null) {
            $query->where('issued_at', '>=', $dateWindow['start']);
        }
        if ($dateWindow['end'] !== null) {
            $query->where('issued_at', '<=', $dateWindow['end']);
        }

        return $query
            ->orderByDesc('issued_at')
            ->paginate($perPage);
    }

    public function resolveOwnedRecognition(User $user, string $recognitionUuid): DonorRecognition
    {
        $email = strtolower(trim((string) $user->email));

        return DonorRecognition::query()
            ->with(['tier', 'certificateTemplate'])
            ->where('uuid', $recognitionUuid)
            ->where(function ($query) use ($user, $email): void {
                $query->where('user_uuid', $user->uuid);
                if ($email !== '') {
                    $query->orWhere(function ($inner) use ($email): void {
                        $inner->whereNull('user_uuid')
                            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$email]);
                    });
                }
            })
            ->firstOrFail();
    }

    public function resolveByRecognitionNumber(string $recognitionNumber): DonorRecognition
    {
        return DonorRecognition::query()
            ->with(['tier', 'certificateTemplate'])
            ->where('recognition_number', strtoupper(trim($recognitionNumber)))
            ->firstOrFail();
    }

    public function ensureDownloadToken(DonorRecognition $recognition): DonorRecognition
    {
        if (filled($recognition->download_token)) {
            return $recognition;
        }

        $recognition->forceFill([
            'download_token' => Str::random(48),
        ])->save();

        return $recognition->refresh();
    }

    public function guestCertificateDownloadUrl(DonorRecognition $recognition): string
    {
        $recognition = $this->ensureDownloadToken($recognition);

        return rtrim((string) config('app.url'), '/')
            .'/api/v1/public/recognitions/'.$recognition->recognition_number.'/download'
            .'?token='.urlencode((string) $recognition->download_token);
    }

    /**
     * @return array<string, mixed>
     */
    public function recognitionPayload(DonorRecognition $recognition): array
    {
        $recognition->loadMissing('tier:uuid,name,tier_badge_url', 'certificateTemplate:uuid,name');

        return [
            'recognition_uuid' => $recognition->uuid,
            'recognition_number' => $recognition->recognition_number,
            'awardee_name' => $recognition->awardee_name,
            'tier' => [
                'uuid' => $recognition->tier?->uuid,
                'name' => $recognition->tier?->name,
                'tier_badge_url' => $recognition->tier?->tier_badge_url,
            ],
            'certificate_template' => $recognition->certificateTemplate !== null ? [
                'uuid' => $recognition->certificateTemplate->uuid,
                'name' => $recognition->certificateTemplate->name,
            ] : null,
            'cumulative_amount_ngn' => (string) $recognition->cumulative_amount_ngn,
            'initial_amount' => $recognition->initial_amount !== null ? (string) $recognition->initial_amount : null,
            'initial_currency' => $recognition->initial_currency,
            'issued_at' => $recognition->issued_at,
            'download_url' => $this->guestCertificateDownloadUrl($recognition),
        ];
    }

    public function issueRecognitionForTier(
        string $donorKey,
        ?string $userUuid,
        ?string $donorEmail,
        string $awardeeName,
        TierConfiguration $tier,
        CertificateTemplate $template,
        float $cumulativeTotal,
        Transaction $triggerTransaction,
    ): ?DonorRecognition {
        return DB::transaction(function () use (
            $donorKey,
            $userUuid,
            $donorEmail,
            $awardeeName,
            $tier,
            $template,
            $cumulativeTotal,
            $triggerTransaction,
        ): ?DonorRecognition {
            $existing = DonorRecognition::query()
                ->where('donor_key', $donorKey)
                ->where('tier_uuid', $tier->uuid)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return null;
            }

            $design = is_array($template->design) ? $template->design : [];

            return DonorRecognition::query()->create([
                'recognition_number' => $this->generateUniqueRecognitionNumber(),
                'donor_key' => $donorKey,
                'user_uuid' => $userUuid,
                'donor_email' => $donorEmail,
                'awardee_name' => $awardeeName,
                'tier_uuid' => $tier->uuid,
                'certificate_template_uuid' => $template->uuid,
                'trigger_transaction_uuid' => $triggerTransaction->uuid,
                'cumulative_amount_ngn' => $cumulativeTotal,
                'initial_amount' => $triggerTransaction->amount,
                'initial_currency' => strtoupper((string) $triggerTransaction->currency),
                'issued_at' => now(),
                'status' => IssuedCertificateStatus::AUTO_ISSUED,
                'snapshot' => [
                    'tier_name' => $tier->name,
                    'template_name' => $template->name,
                    'design' => $design,
                    'initial_amount' => (string) $triggerTransaction->amount,
                    'initial_currency' => strtoupper((string) $triggerTransaction->currency),
                ],
            ]);
        });
    }

    public function resolveActiveTemplateForTier(TierConfiguration $tier): ?CertificateTemplate
    {
        return CertificateTemplate::query()
            ->where('tier_uuid', $tier->uuid)
            ->where('is_active', true)
            ->orderByDesc('updated_at')
            ->first();
    }

    public function generateUniqueRecognitionNumber(): string
    {
        return $this->generateUniqueRecognitionNumberInternal();
    }

    private function generateUniqueRecognitionNumberInternal(): string
    {
        $year = now()->format('Y');
        $prefix = sprintf('ICOBA-REC-%s-', $year);
        $pool = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

        for ($attempt = 0; $attempt < 10; $attempt++) {
            $suffix = '';
            for ($i = 0; $i < 8; $i++) {
                $suffix .= $pool[random_int(0, strlen($pool) - 1)];
            }

            $recognitionNumber = $prefix.$suffix;
            if (! DonorRecognition::query()->where('recognition_number', $recognitionNumber)->exists()) {
                return $recognitionNumber;
            }
        }

        $generated = GeneralHelper::getModelUniqueRandomId([
            'modelNamespace' => DonorRecognition::class,
            'modelField' => 'recognition_number',
            'prefix' => $prefix,
            'idLength' => 8,
            'idType' => 'numalpha',
        ]);

        if (is_string($generated)) {
            return strtoupper($generated);
        }

        return $prefix.strtoupper(bin2hex(random_bytes(4)));
    }
}
