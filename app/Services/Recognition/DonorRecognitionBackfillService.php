<?php

namespace App\Services\Recognition;

use App\Enums\TransactionStatus;
use App\Jobs\SendDonorRecognitionEmailJob;
use App\Models\CertificateTemplate;
use App\Models\DonorRecognition;
use App\Models\TierConfiguration;
use App\Models\Transaction;
use App\Services\Donation\DonorCumulativeTotalService;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;

final class DonorRecognitionBackfillService
{
    public function __construct(
        private readonly DonorRecognitionService $recognitionService,
        private readonly DonorCumulativeTotalService $cumulativeTotalService,
    ) {}

    /**
     * @return list<TierConfiguration>
     */
    public function tiersEligibleForBackfill(?string $tierUuid = null): array
    {
        $query = TierConfiguration::query()
            ->where('is_active', true)
            ->orderBy('sort_order');

        if ($tierUuid !== null && $tierUuid !== '') {
            $query->where(function ($builder) use ($tierUuid): void {
                $builder->where('uuid', $tierUuid)
                    ->orWhere('name', $tierUuid);
            });
        }

        return $query->get()
            ->filter(fn (TierConfiguration $tier): bool => $this->recognitionService->resolveActiveTemplateForTier($tier) !== null)
            ->values()
            ->all();
    }

    /**
     * @return array{
     *     tier_uuid: string,
     *     tier_name: string,
     *     template_uuid: string,
     *     scanned: int,
     *     eligible: int,
     *     issued: int,
     *     skipped_no_trigger: int,
     *     dry_run: bool
     * }
     */
    public function backfillTier(
        TierConfiguration $tier,
        bool $dryRun = false,
        ?int $chunkSize = null,
        bool $dispatchEmail = true,
        ?int $limit = null,
    ): array {
        $template = $this->recognitionService->resolveActiveTemplateForTier($tier);
        if ($template === null) {
            return [
                'tier_uuid' => $tier->uuid,
                'tier_name' => (string) $tier->name,
                'template_uuid' => '',
                'scanned' => 0,
                'eligible' => 0,
                'issued' => 0,
                'skipped_no_trigger' => 0,
                'dry_run' => $dryRun,
            ];
        }

        $chunkSize = max(50, min((int) ($chunkSize ?? config('recognitions.backfill_chunk_size', 500)), 2000));
        $dispatchEmail = $dispatchEmail && (bool) config('recognitions.backfill_dispatch_email', true);

        $scanned = 0;
        $eligible = 0;
        $issued = 0;
        $skippedNoTrigger = 0;

        foreach ($this->missingDonorKeysForTier($tier, $chunkSize) as $donorKeyChunk) {
            $donorKeys = $donorKeyChunk->values()->all();
            if ($donorKeys === []) {
                continue;
            }

            $scanned += count($donorKeys);

            $triggerTransactions = $this->latestTriggerTransactionsForDonorKeys($donorKeys);
            $cumulativeTotals = $this->cumulativeTotalsForDonorKeys($donorKeys);

            foreach ($donorKeys as $donorKey) {
                if ($limit !== null && $eligible >= $limit) {
                    break 2;
                }

                $cumulativeTotal = (float) ($cumulativeTotals[$donorKey] ?? 0);
                if ($cumulativeTotal < (float) $tier->min_amount) {
                    continue;
                }

                $triggerTransaction = $triggerTransactions[$donorKey] ?? null;
                if ($triggerTransaction === null) {
                    $skippedNoTrigger++;

                    continue;
                }

                $context = $this->cumulativeTotalService->resolveContextFromTransaction($triggerTransaction);
                if ($context['is_anonymous'] || blank($context['awardee_name'])) {
                    $skippedNoTrigger++;

                    continue;
                }

                $eligible++;

                if ($dryRun) {
                    continue;
                }

                $recognition = $this->recognitionService->issueRecognitionForTier(
                    donorKey: $donorKey,
                    userUuid: $context['user_uuid'],
                    donorEmail: $context['donor_email'],
                    awardeeName: (string) $context['awardee_name'],
                    tier: $tier,
                    template: $template,
                    cumulativeTotal: $cumulativeTotal,
                    triggerTransaction: $triggerTransaction,
                );

                if ($recognition === null) {
                    continue;
                }

                $issued++;

                if ($dispatchEmail) {
                    SendDonorRecognitionEmailJob::dispatch($recognition->uuid, $triggerTransaction->uuid);
                }
            }
        }

        return [
            'tier_uuid' => $tier->uuid,
            'tier_name' => (string) $tier->name,
            'template_uuid' => $template->uuid,
            'scanned' => $scanned,
            'eligible' => $eligible,
            'issued' => $issued,
            'skipped_no_trigger' => $skippedNoTrigger,
            'dry_run' => $dryRun,
        ];
    }

    /**
     * Dry-run preview rows for console output (capped).
     *
     * @return list<array<string, string|float|null>>
     */
    public function previewMissingForTier(TierConfiguration $tier, int $limit = 100, ?int $chunkSize = null): array
    {
        $chunkSize = max(50, min((int) ($chunkSize ?? config('recognitions.backfill_chunk_size', 500)), 2000));
        $rows = [];

        foreach ($this->missingDonorKeysForTier($tier, $chunkSize) as $donorKeyChunk) {
            $donorKeys = $donorKeyChunk->values()->all();
            if ($donorKeys === []) {
                continue;
            }

            $triggerTransactions = $this->latestTriggerTransactionsForDonorKeys($donorKeys);
            $cumulativeTotals = $this->cumulativeTotalsForDonorKeys($donorKeys);

            foreach ($donorKeys as $donorKey) {
                if (count($rows) >= $limit) {
                    break 2;
                }

                $cumulativeTotal = (float) ($cumulativeTotals[$donorKey] ?? 0);
                $triggerTransaction = $triggerTransactions[$donorKey] ?? null;
                $awardeeName = null;
                $triggerUuid = null;

                if ($triggerTransaction !== null) {
                    $context = $this->cumulativeTotalService->resolveContextFromTransaction($triggerTransaction);
                    $awardeeName = $context['awardee_name'];
                    $triggerUuid = $triggerTransaction->uuid;
                }

                $rows[] = [
                    'donor_key' => $donorKey,
                    'awardee_name' => $awardeeName,
                    'cumulative_ngn' => $cumulativeTotal,
                    'trigger_transaction_uuid' => $triggerUuid,
                    'tier' => (string) $tier->name,
                ];
            }
        }

        return $rows;
    }

    /**
     * @return LazyCollection<int, Collection<int, string>>
     */
    private function missingDonorKeysForTier(TierConfiguration $tier, int $chunkSize): LazyCollection
    {
        $keySql = DonorCumulativeTotalService::DONOR_KEY_SQL;
        $effectiveNgnSql = $this->cumulativeTotalService->effectiveAmountNgnSql('transactions');
        $minAmount = (float) $tier->min_amount;

        return LazyCollection::make(function () use ($tier, $chunkSize, $keySql, $effectiveNgnSql, $minAmount): \Generator {
            $lastKey = null;

            while (true) {
                $qualifiedSubquery = Transaction::query()
                    ->countableTowardRevenue()
                    ->selectRaw('('.$keySql.') as donor_key')
                    ->selectRaw('SUM(('.$effectiveNgnSql.')) as cumulative_total')
                    ->groupBy('donor_key')
                    ->havingRaw('cumulative_total >= ?', [$minAmount]);

                $chunkQuery = DB::query()
                    ->fromSub($qualifiedSubquery, 'qualified_donors')
                    ->select('qualified_donors.donor_key')
                    ->whereNotExists(function (Builder $query) use ($tier): void {
                        $query->selectRaw('1')
                            ->from('donor_recognitions')
                            ->whereColumn('donor_recognitions.donor_key', 'qualified_donors.donor_key')
                            ->where('donor_recognitions.tier_uuid', $tier->uuid);
                    })
                    ->whereExists(function (Builder $query): void {
                        $namedKeySql = <<<'SQL'
CASE
  WHEN named_tx.user_uuid IS NOT NULL THEN named_tx.user_uuid
  WHEN named_tx.donor_email IS NOT NULL AND named_tx.donor_email != '' THEN LOWER(TRIM(named_tx.donor_email))
  ELSE named_tx.uuid
END
SQL;

                        $query->selectRaw('1')
                            ->from('transactions as named_tx')
                            ->where('named_tx.status', TransactionStatus::SUCCESSFUL->value)
                            ->where('named_tx.is_anonymous', false)
                            ->whereRaw('('.$namedKeySql.') = qualified_donors.donor_key')
                            ->where(function (Builder $inner): void {
                                $inner->whereRaw("NULLIF(TRIM(named_tx.donor_name), '') IS NOT NULL")
                                    ->orWhereNotNull('named_tx.user_uuid');
                            });
                    })
                    ->orderBy('qualified_donors.donor_key');

                if ($lastKey !== null) {
                    $chunkQuery->where('qualified_donors.donor_key', '>', $lastKey);
                }

                /** @var list<string> $keys */
                $keys = $chunkQuery
                    ->limit($chunkSize)
                    ->pluck('donor_key')
                    ->all();

                if ($keys === []) {
                    return;
                }

                $lastKey = $keys[array_key_last($keys)];

                yield collect($keys);
            }
        });
    }

    /**
     * @param  list<string>  $donorKeys
     * @return array<string, float>
     */
    private function cumulativeTotalsForDonorKeys(array $donorKeys): array
    {
        if ($donorKeys === []) {
            return [];
        }

        $keySql = DonorCumulativeTotalService::DONOR_KEY_SQL;
        $effectiveNgnSql = $this->cumulativeTotalService->effectiveAmountNgnSql('transactions');

        $placeholders = implode(', ', array_fill(0, count($donorKeys), '?'));

        $rows = Transaction::query()
            ->countableTowardRevenue()
            ->selectRaw('('.$keySql.') as donor_key')
            ->selectRaw('SUM(('.$effectiveNgnSql.')) as cumulative_total')
            ->whereRaw('('.$keySql.') IN ('.$placeholders.')', $donorKeys)
            ->groupBy('donor_key')
            ->get();

        $totals = [];
        foreach ($rows as $row) {
            $totals[(string) $row->donor_key] = (float) $row->cumulative_total;
        }

        return $totals;
    }

    /**
     * @param  list<string>  $donorKeys
     * @return array<string, Transaction>
     */
    private function latestTriggerTransactionsForDonorKeys(array $donorKeys): array
    {
        if ($donorKeys === []) {
            return [];
        }

        $keySql = DonorCumulativeTotalService::DONOR_KEY_SQL;

        $placeholders = implode(', ', array_fill(0, count($donorKeys), '?'));

        $ranked = Transaction::query()
            ->countableTowardRevenue()
            ->select('transactions.*')
            ->selectRaw('('.$keySql.') as donor_key')
            ->selectRaw('ROW_NUMBER() OVER (PARTITION BY ('.$keySql.') ORDER BY transactions.paid_at DESC, transactions.id DESC) as row_num')
            ->where('transactions.is_anonymous', false)
            ->whereRaw('('.$keySql.') IN ('.$placeholders.')', $donorKeys);

        $uuids = DB::query()
            ->fromSub($ranked, 'ranked_transactions')
            ->where('row_num', 1)
            ->pluck('uuid')
            ->all();

        if ($uuids === []) {
            return [];
        }

        $transactions = Transaction::query()
            ->with(['donor:uuid,firstname,lastname,email,organization_name'])
            ->whereIn('uuid', $uuids)
            ->get()
            ->keyBy(fn (Transaction $transaction): string => $this->cumulativeTotalService->resolveDonorKeyFromTransaction($transaction));

        return $transactions->all();
    }
}
