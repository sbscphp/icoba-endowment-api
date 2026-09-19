<?php

namespace App\Services\Maintenance;

use App\Enums\TransactionStatus;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Public\PublicEndowmentStatsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Flags pledges/transactions as test or live, and hard-deletes donation data
 * (test-only or everything) together with the rows that hang off it.
 */
final class DonationTestDataService
{
    public const SCOPE_TEST = 'test';

    public const SCOPE_ALL = 'all';

    public function __construct(
        private readonly PledgeBalanceService $pledgeBalance,
    ) {}

    /**
     * Flag matching pledges and transactions. A flagged pledge carries its transactions with it.
     *
     * @param  array{transactions?: list<string>, pledges?: list<string>, emails?: list<string>, before?: ?Carbon}  $criteria
     * @return array{pledges: int, transactions: int, preview: list<array<string, string>>}
     */
    public function mark(array $criteria, bool $isTest, bool $dryRun, int $previewLimit = 50): array
    {
        $transactionRefs = array_values(array_filter($criteria['transactions'] ?? []));
        $pledgeRefs = array_values(array_filter($criteria['pledges'] ?? []));
        $emails = array_values(array_filter(array_map(
            static fn (string $email): string => strtolower(trim($email)),
            $criteria['emails'] ?? []
        )));
        $before = $criteria['before'] ?? null;

        if ($transactionRefs === [] && $pledgeRefs === [] && $emails === [] && $before === null) {
            throw new InvalidArgumentException('Provide at least one of: transaction, pledge, email, before.');
        }

        // --email / --before narrow whichever of --pledge / --transaction is given; on their own they
        // select both pledges and transactions.
        $hasSharedFilter = $emails !== [] || $before !== null;
        $selectPledges = $pledgeRefs !== [] || ($transactionRefs === [] && $hasSharedFilter);
        $selectTransactions = $transactionRefs !== [] || ($pledgeRefs === [] && $hasSharedFilter);

        $pledgeUuids = [];
        if ($selectPledges) {
            $pledgeUuids = Pledge::withTrashed()
                ->when($pledgeRefs !== [], fn (Builder $q) => $q->whereIn('uuid', $pledgeRefs))
                ->when($emails !== [], fn (Builder $q) => $this->whereDonorEmailIn($q, 'pledges', $emails))
                ->when($before !== null, fn (Builder $q) => $q->where('pledges.created_at', '<', $before))
                ->pluck('uuid')
                ->all();
        }

        $transactions = Transaction::withTrashed()
            ->where(function (Builder $outer) use ($selectTransactions, $transactionRefs, $emails, $before, $pledgeUuids): void {
                if (! $selectTransactions && $pledgeUuids === []) {
                    $outer->whereRaw('1 = 0');

                    return;
                }

                if ($selectTransactions) {
                    $outer->where(function (Builder $q) use ($transactionRefs, $emails, $before): void {
                        $q->when($transactionRefs !== [], fn (Builder $b) => $b->where(
                            fn (Builder $ref) => $ref->whereIn('uuid', $transactionRefs)->orWhereIn('transaction_id', $transactionRefs)
                        ))
                            ->when($emails !== [], fn (Builder $b) => $this->whereDonorEmailIn($b, 'transactions', $emails))
                            ->when($before !== null, fn (Builder $b) => $b->where('transactions.created_at', '<', $before));
                    });
                }

                // A flagged pledge carries all of its transactions.
                if ($pledgeUuids !== []) {
                    $outer->orWhereIn('pledge_uuid', $pledgeUuids);
                }
            });

        $pledgeCount = $pledgeUuids === []
            ? 0
            : Pledge::withTrashed()->whereIn('uuid', $pledgeUuids)->where('is_test', ! $isTest)->count();
        $transactionsToChange = (clone $transactions)->where('is_test', ! $isTest);
        $transactionCount = (clone $transactionsToChange)->count();

        $preview = (clone $transactionsToChange)
            ->orderBy('created_at')
            ->limit(max(0, $previewLimit))
            ->get(['transaction_id', 'donor_email', 'amount', 'currency', 'gateway', 'status', 'pledge_uuid', 'created_at'])
            ->map(static fn (Transaction $t): array => [
                'transaction_id' => (string) $t->transaction_id,
                'donor_email' => (string) ($t->donor_email ?? '—'),
                'amount' => $t->currency.' '.number_format((float) $t->amount, 2),
                'gateway' => (string) ($t->gateway ?? '—'),
                'status' => (string) $t->status?->value,
                'pledge_uuid' => (string) ($t->pledge_uuid ?? '—'),
                'created_at' => (string) $t->created_at?->toDateTimeString(),
            ])
            ->all();

        if (! $dryRun) {
            DB::transaction(function () use ($pledgeUuids, $transactions, $isTest): void {
                // Query-builder updates on purpose: no events, no updated_at churn.
                $transactions->toBase()->update(['is_test' => $isTest]);
                if ($pledgeUuids !== []) {
                    DB::table('pledges')->whereIn('uuid', $pledgeUuids)->update(['is_test' => $isTest]);
                }
            });
        }

        return ['pledges' => $pledgeCount, 'transactions' => $transactionCount, 'preview' => $preview];
    }

    /**
     * Hard-delete donation data, soft-deleted rows included.
     *
     * test: test transactions + test pledges. A test pledge that still has live payments is kept (and
     *       reported) — deleting it would orphan real money. Live pledges that lose test payments get
     *       their status recomputed.
     * all:  every transaction and pledge.
     *
     * Receipts go with their transactions, tier recognitions triggered by a purged transaction are removed
     * (re-issue what is still earned with `recognitions:backfill`), and giving identities left without a
     * successful payment are unlocked. Audit logs and notifications are left untouched.
     *
     * @return array{transactions: int, receipts: int, recognitions: int, pledges: int, pledges_kept_with_live_payments: list<string>, live_pledges_refreshed: int, giving_identities_unlocked: int}
     */
    public function purge(string $scope, bool $dryRun): array
    {
        if (! in_array($scope, [self::SCOPE_TEST, self::SCOPE_ALL], true)) {
            throw new InvalidArgumentException('Scope must be "test" or "all".');
        }

        $testOnly = $scope === self::SCOPE_TEST;

        $transactions = fn (): QueryBuilder => DB::table('transactions')
            ->when($testOnly, fn (QueryBuilder $q) => $q->where('is_test', true));
        $transactionUuids = fn (): QueryBuilder => $transactions()->select('uuid');

        $pledges = fn (): QueryBuilder => DB::table('pledges')
            ->when($testOnly, fn (QueryBuilder $q) => $q
                ->where('is_test', true)
                ->whereNotExists(fn (QueryBuilder $live) => $live
                    ->selectRaw('1')
                    ->from('transactions')
                    ->whereColumn('transactions.pledge_uuid', 'pledges.uuid')
                    ->where('transactions.is_test', false)));

        $keptPledges = $testOnly
            ? DB::table('pledges')
                ->where('is_test', true)
                ->whereExists(fn (QueryBuilder $live) => $live
                    ->selectRaw('1')
                    ->from('transactions')
                    ->whereColumn('transactions.pledge_uuid', 'pledges.uuid')
                    ->where('transactions.is_test', false))
                ->pluck('uuid')
                ->all()
            : [];

        $receipts = fn (): QueryBuilder => DB::table('transaction_receipts')
            ->when($testOnly, fn (QueryBuilder $q) => $q->whereIn('transaction_uuid', $transactionUuids()));

        // Under "all" every award loses its supporting donations, including ones whose trigger was already nulled.
        $recognitions = fn (): QueryBuilder => DB::table('donor_recognitions')
            ->when($testOnly, fn (QueryBuilder $q) => $q->whereIn('trigger_transaction_uuid', $transactionUuids()));

        $livePledgeUuids = $testOnly
            ? $transactions()
                ->whereNotNull('pledge_uuid')
                ->whereIn('pledge_uuid', DB::table('pledges')->select('uuid')->where('is_test', false))
                ->distinct()
                ->pluck('pledge_uuid')
                ->all()
            : [];

        $identityUuids = $transactions()
            ->whereNotNull('giving_identity_uuid')
            ->distinct()
            ->pluck('giving_identity_uuid')
            ->all();

        $result = [
            'transactions' => $transactions()->count(),
            'receipts' => $receipts()->count(),
            'recognitions' => $recognitions()->count(),
            'pledges' => $pledges()->count(),
            'pledges_kept_with_live_payments' => array_map('strval', $keptPledges),
            'live_pledges_refreshed' => count($livePledgeUuids),
            'giving_identities_unlocked' => 0,
        ];

        if ($dryRun) {
            $result['giving_identities_unlocked'] = $this->identitiesLosingAllPayments($identityUuids, $transactionUuids())->count();

            return $result;
        }

        DB::transaction(function () use (&$result, $transactions, $pledges, $receipts, $recognitions, $livePledgeUuids, $identityUuids): void {
            $recognitions()->delete();
            // Explicit even though the FK cascades, so the purge does not depend on FK enforcement.
            $receipts()->delete();
            // Transactions before pledges: transactions.pledge_uuid is nullOnDelete, not cascade.
            $transactions()->delete();
            $pledges()->delete();

            foreach (array_chunk($livePledgeUuids, 200) as $chunk) {
                Pledge::query()->whereIn('uuid', $chunk)->get()
                    ->each(fn (Pledge $pledge) => $this->pledgeBalance->refreshPledgeStatus($pledge));
            }

            $result['giving_identities_unlocked'] = $this->identitiesLosingAllPayments($identityUuids, null)
                ->update(['locked_at' => null]);
        });

        PublicEndowmentStatsService::forgetCache();

        return $result;
    }

    /**
     * Locked identities among $identityUuids with no successful payment left once $excludedTransactionUuids
     * (null = already deleted) are gone.
     *
     * @param  list<string>  $identityUuids
     */
    private function identitiesLosingAllPayments(array $identityUuids, ?QueryBuilder $excludedTransactionUuids): QueryBuilder
    {
        return DB::table('giving_identities')
            ->whereIn('uuid', $identityUuids)
            ->whereNotNull('locked_at')
            ->whereNotExists(fn (QueryBuilder $paid) => $paid
                ->selectRaw('1')
                ->from('transactions')
                ->whereColumn('transactions.giving_identity_uuid', 'giving_identities.uuid')
                ->where('transactions.status', TransactionStatus::SUCCESSFUL->value)
                ->whereNull('transactions.deleted_at')
                ->when($excludedTransactionUuids !== null, fn (QueryBuilder $q) => $q->whereNotIn('transactions.uuid', $excludedTransactionUuids)));
    }

    /**
     * @param  list<string>  $emails  lower-cased
     */
    private function whereDonorEmailIn(Builder $query, string $table, array $emails): Builder
    {
        return $query->where(function (Builder $q) use ($table, $emails): void {
            $placeholders = implode(',', array_fill(0, count($emails), '?'));
            $q->whereRaw("LOWER(TRIM({$table}.donor_email)) IN ({$placeholders})", $emails)
                ->orWhereHas('donor', fn (Builder $donor) => $donor->whereRaw("LOWER(TRIM(email)) IN ({$placeholders})", $emails));
        });
    }
}
