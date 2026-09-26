<?php

namespace App\Services\Maintenance;

use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Transaction;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Manually move pending transactions to failed when no gateway callback will ever do it
 * (abandoned checkouts, dead references, offline transfers that never arrived).
 *
 * Only rows that are currently pending are touched; successful, failed, reversed and
 * superseded rows are never rewritten. The reason and timestamp are kept in the
 * transaction metadata so the change stays traceable.
 */
class TransactionFailureService
{
    public const METADATA_KEY = 'manual_failure';

    /**
     * @param  array{
     *     transactions?: list<string>,
     *     pending_before?: CarbonInterface|null,
     *     gateways?: list<string>,
     *     include_bank_transfers?: bool,
     *     reason?: string|null
     * }  $criteria
     * @return array{
     *     matched: int,
     *     updated: int,
     *     preview: list<array<string, string>>,
     *     not_found: list<string>,
     *     skipped: list<array{reference: string, status: string}>
     * }
     */
    public function markFailed(array $criteria, bool $dryRun, int $previewLimit = 50): array
    {
        $references = array_values(array_unique(array_filter(array_map(
            static fn (string $ref): string => trim($ref),
            $criteria['transactions'] ?? []
        ))));
        $pendingBefore = $criteria['pending_before'] ?? null;
        $gateways = array_values(array_filter(array_map(
            static fn (string $gateway): string => strtolower(trim($gateway)),
            $criteria['gateways'] ?? []
        )));
        $includeBankTransfers = (bool) ($criteria['include_bank_transfers'] ?? false);
        $reason = isset($criteria['reason']) && trim((string) $criteria['reason']) !== ''
            ? trim((string) $criteria['reason'])
            : null;

        if ($references === [] && $pendingBefore === null) {
            throw new InvalidArgumentException('Provide at least one of: transaction, pending-before.');
        }

        $notFound = [];
        $skipped = [];
        $pending = Transaction::query()->where('status', TransactionStatus::PENDING);

        if ($references !== []) {
            $explicit = Transaction::query()
                ->where(fn (Builder $q) => $q->whereIn('uuid', $references)->orWhereIn('transaction_id', $references))
                ->get(['uuid', 'transaction_id', 'status']);

            $seen = [];
            foreach ($explicit as $row) {
                $seen[] = $row->uuid;
                $seen[] = $row->transaction_id;
                if ($row->status !== TransactionStatus::PENDING) {
                    $skipped[] = ['reference' => (string) $row->transaction_id, 'status' => (string) $row->status?->value];
                }
            }
            $notFound = array_values(array_diff($references, $seen));

            $pending->where(fn (Builder $q) => $q->whereIn('uuid', $references)->orWhereIn('transaction_id', $references));
        }

        if ($pendingBefore !== null) {
            $pending->where('created_at', '<', $pendingBefore);
            if (! $includeBankTransfers) {
                // Offline transfers stay pending until an admin verifies them; a sweep must opt in to touch them.
                $pending->where(fn (Builder $q) => $q
                    ->whereNull('application_type')
                    ->orWhere('application_type', '!=', TransactionApplicationType::BANK_TRANSFER));
            }
        }

        if ($gateways !== []) {
            $pending->whereIn('gateway', $gateways);
        }

        $matched = (clone $pending)->count();

        $preview = (clone $pending)
            ->orderBy('created_at')
            ->limit(max(0, $previewLimit))
            ->get(['transaction_id', 'donor_email', 'amount', 'currency', 'gateway', 'application_type', 'pledge_uuid', 'created_at'])
            ->map(static fn (Transaction $t): array => [
                'transaction_id' => (string) $t->transaction_id,
                'donor_email' => (string) ($t->donor_email ?? '—'),
                'amount' => $t->currency.' '.number_format((float) $t->amount, 2),
                'gateway' => (string) ($t->gateway ?? '—'),
                'type' => (string) ($t->application_type?->value ?? '—'),
                'pledge_uuid' => (string) ($t->pledge_uuid ?? '—'),
                'created_at' => (string) $t->created_at?->toDateTimeString(),
            ])
            ->all();

        $updated = 0;
        if (! $dryRun && $matched > 0) {
            $markedAt = now()->toIso8601String();
            $updated = DB::transaction(function () use ($pending, $reason, $markedAt): int {
                $count = 0;
                (clone $pending)->lockForUpdate()->get()->each(function (Transaction $transaction) use ($reason, $markedAt, &$count): void {
                    $metadata = is_array($transaction->metadata) ? $transaction->metadata : [];
                    $metadata[self::METADATA_KEY] = array_filter([
                        'reason' => $reason,
                        'marked_at' => $markedAt,
                        'source' => 'console',
                    ], static fn ($v) => $v !== null);

                    $transaction->forceFill([
                        'status' => TransactionStatus::FAILED,
                        'metadata' => $metadata,
                    ])->save();
                    $count++;
                });

                return $count;
            });

            Log::info('Pending transactions manually marked failed.', [
                'count' => $updated,
                'reason' => $reason,
                'references' => $references,
                'pending_before' => $pendingBefore?->toIso8601String(),
                'gateways' => $gateways,
                'include_bank_transfers' => $includeBankTransfers,
            ]);
        }

        return [
            'matched' => $matched,
            'updated' => $updated,
            'preview' => $preview,
            'not_found' => $notFound,
            'skipped' => $skipped,
        ];
    }
}
