<?php

namespace App\Services\Pledge;

use App\Enums\PledgePaymentPlanType;
use App\Enums\PledgePaymentPreference;
use App\Enums\PledgeScheduleItemStatus;
use App\Enums\PledgeStatus;
use App\Enums\TransactionApplicationType;
use App\Enums\TransactionStatus;
use App\Models\Pledge;
use App\Models\Transaction;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PledgeScheduleService
{
    public function __construct(
        private readonly PledgeBalanceService $balanceService,
    ) {}

    /**
     * @param  EloquentCollection<int, Pledge>|Collection<int, Pledge>  $pledges
     * @return array<string, array<string, mixed>>
     */
    public function buildForPledges(EloquentCollection|Collection $pledges): array
    {
        if ($pledges->isEmpty()) {
            return [];
        }

        $uuids = $pledges->pluck('uuid')->filter()->values()->all();
        $transactionsByPledge = Transaction::query()
            ->whereIn('pledge_uuid', $uuids)
            ->where('status', TransactionStatus::SUCCESSFUL)
            ->where(function ($q): void {
                $q->whereNull('application_type')
                    ->orWhereNotIn('application_type', [
                        TransactionApplicationType::PLEDGE_PLACEHOLDER->value,
                    ]);
            })
            ->orderBy('paid_at')
            ->orderBy('created_at')
            ->get()
            ->groupBy('pledge_uuid');

        $out = [];
        foreach ($pledges as $pledge) {
            if (! $pledge instanceof Pledge) {
                continue;
            }
            $txns = $transactionsByPledge->get($pledge->uuid, collect());
            $out[$pledge->uuid] = $this->buildForPledge($pledge, $txns);
        }

        return $out;
    }

    /**
     * @param  Collection<int, Transaction>|null  $transactions
     * @return array<string, mixed>
     */
    public function buildForPledge(Pledge $pledge, ?Collection $transactions = null): array
    {
        $transactions = $transactions ?? $this->loadPaymentTransactions($pledge);
        $items = $this->resolveScheduleItems($pledge);
        $allocated = $this->allocatePayments($items, $transactions, $pledge);
        $schedulePaused = $this->isSchedulePaused($pledge);
        $preference = $this->paymentPreference($pledge);

        $enrichedItems = [];
        foreach ($items as $item) {
            $id = (string) $item['id'];
            $paid = $allocated['by_item'][$id] ?? ['amount' => 0.0, 'amount_ngn' => 0.0];
            $pledged = (float) $item['amount'];
            $pledgedNgn = (float) $item['amount_ngn'];
            $paidAmount = (float) $paid['amount'];
            $paidNgn = (float) $paid['amount_ngn'];
            $itemRemaining = max(0, $pledged - $paidAmount);
            $itemRemainingNgn = max(0, $pledgedNgn - $paidNgn);

            $status = $this->resolveItemStatus(
                $item,
                $paidAmount,
                $pledged,
                $schedulePaused,
                (bool) ($item['is_paused'] ?? false),
            );

            $enrichedItems[] = [
                'id' => $id,
                'sequence' => (int) $item['sequence'],
                'due_date' => $item['due_date'] ?? null,
                'pledged_amount' => $this->money($pledged),
                'pledged_amount_ngn' => $this->money($pledgedNgn),
                'paid_amount' => $this->money($paidAmount),
                'paid_amount_ngn' => $this->money($paidNgn),
                'remaining_amount' => $this->money($itemRemaining),
                'remaining_amount_ngn' => $this->money($itemRemainingNgn),
                'currency' => $pledge->currency,
                'status' => $status->value,
                'is_paused' => $status === PledgeScheduleItemStatus::PAUSED,
            ];
        }

        return [
            'installment_count' => $pledge->installment_count,
            'payment_plan_type' => $pledge->payment_plan_type instanceof \BackedEnum
                ? $pledge->payment_plan_type->value
                : $pledge->payment_plan_type,
            'payment_preference' => $preference->value,
            'schedule_paused' => $schedulePaused,
            'items' => $enrichedItems,
        ];
    }

    /**
     * Persist a generated schedule on the pledge when none was supplied at creation.
     */
    public function persistDefaultScheduleIfMissing(Pledge $pledge): Pledge
    {
        $stored = $pledge->schedule;
        if (is_array($stored) && $stored !== []) {
            return $pledge;
        }

        $items = $this->generateDefaultSchedule($pledge);
        $pledge->update([
            'schedule' => array_map(fn (array $item): array => [
                'id' => $item['id'],
                'sequence' => $item['sequence'],
                'due_date' => $item['due_date'],
                'amount' => $item['amount'],
                'amount_ngn' => $item['amount_ngn'],
                'is_paused' => false,
            ], $items),
            'installment_count' => $pledge->installment_count ?? count($items),
        ]);

        return $pledge->fresh();
    }

    public function pauseSchedule(Pledge $pledge): Pledge
    {
        $this->assertPledgeIsManageable($pledge);
        $metadata = $pledge->metadata ?? [];
        $metadata['schedule_paused'] = true;
        $metadata['schedule_paused_at'] = now()->toIso8601String();
        $pledge->update(['metadata' => $metadata]);

        return $pledge->fresh();
    }

    public function resumeSchedule(Pledge $pledge): Pledge
    {
        $this->assertPledgeIsManageable($pledge);
        $metadata = $pledge->metadata ?? [];
        $metadata['schedule_paused'] = false;
        unset($metadata['schedule_paused_at']);
        $pledge->update(['metadata' => $metadata]);

        return $pledge->fresh();
    }

    public function setPaymentPreference(Pledge $pledge, PledgePaymentPreference $preference): Pledge
    {
        $this->assertPledgeIsManageable($pledge);
        $metadata = $pledge->metadata ?? [];
        $metadata['payment_preference'] = $preference->value;
        $pledge->update(['metadata' => $metadata]);

        return $pledge->fresh();
    }

    /**
     * Validate a pledge payment amount against preference and optional installment target.
     */
    public function assertPaymentAllowed(Pledge $pledge, float $amount, ?string $scheduleItemId = null): void
    {
        $remaining = (float) $this->balanceService->remainingAmount($pledge);
        if ($remaining <= 0.00001) {
            throw ValidationException::withMessages([
                'pledge_uuid' => ['This pledge is already fulfilled.'],
            ]);
        }

        if ($amount > $remaining + 0.00001) {
            throw ValidationException::withMessages([
                'amount' => ['Payment amount cannot exceed the remaining pledge balance.'],
            ]);
        }

        $view = $this->buildForPledge($pledge);
        $preference = PledgePaymentPreference::tryFrom((string) ($view['payment_preference'] ?? ''))
            ?? PledgePaymentPreference::SCHEDULED;

        if ($preference === PledgePaymentPreference::PAY_ALL && abs($amount - $remaining) > 0.00001) {
            throw ValidationException::withMessages([
                'amount' => ['When payment preference is pay_all, amount must equal the remaining balance ('.number_format($remaining, 2, '.', '').').'],
            ]);
        }

        if ($scheduleItemId !== null && $scheduleItemId !== '') {
            $item = collect($view['items'])->firstWhere('id', $scheduleItemId);
            if ($item === null) {
                throw ValidationException::withMessages([
                    'schedule_item_id' => ['Invalid schedule installment for this pledge.'],
                ]);
            }

            $itemRemaining = (float) $item['remaining_amount'];
            if ($amount > $itemRemaining + 0.00001) {
                throw ValidationException::withMessages([
                    'amount' => ['Payment amount cannot exceed this installment remaining balance.'],
                ]);
            }
        }
    }

    /**
     * @return Collection<int, Transaction>
     */
    private function loadPaymentTransactions(Pledge $pledge): Collection
    {
        return Transaction::query()
            ->where('pledge_uuid', $pledge->uuid)
            ->where('status', TransactionStatus::SUCCESSFUL)
            ->where(function ($q): void {
                $q->whereNull('application_type')
                    ->orWhereNotIn('application_type', [
                        TransactionApplicationType::PLEDGE_PLACEHOLDER->value,
                    ]);
            })
            ->orderBy('paid_at')
            ->orderBy('created_at')
            ->get();
    }

    private function assertPledgeIsManageable(Pledge $pledge): void
    {
        if ($pledge->status !== PledgeStatus::ACTIVE) {
            throw ValidationException::withMessages([
                'pledge_uuid' => ['Only active pledges can update schedule settings.'],
            ]);
        }
    }

    private function isSchedulePaused(Pledge $pledge): bool
    {
        return (bool) data_get($pledge->metadata, 'schedule_paused', false);
    }

    private function paymentPreference(Pledge $pledge): PledgePaymentPreference
    {
        $raw = data_get($pledge->metadata, 'payment_preference');
        if (is_string($raw) && $raw !== '') {
            return PledgePaymentPreference::tryFrom($raw) ?? PledgePaymentPreference::SCHEDULED;
        }

        return PledgePaymentPreference::SCHEDULED;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function resolveScheduleItems(Pledge $pledge): array
    {
        $stored = $pledge->schedule;
        if (is_array($stored) && $stored !== []) {
            return $this->normalizeStoredItems($pledge, $stored);
        }

        return $this->generateDefaultSchedule($pledge);
    }

    /**
     * @param  list<array<string, mixed>>  $stored
     * @return list<array<string, mixed>>
     */
    private function normalizeStoredItems(Pledge $pledge, array $stored): array
    {
        $rate = $this->pledgeNgnRate($pledge);
        $items = [];
        $sequence = 0;

        foreach ($stored as $row) {
            if (! is_array($row)) {
                continue;
            }
            $sequence++;
            $amount = (float) ($row['amount'] ?? $row['pledged_amount'] ?? 0);
            if ($amount <= 0) {
                continue;
            }
            $amountNgn = isset($row['amount_ngn']) || isset($row['pledged_amount_ngn'])
                ? (float) ($row['amount_ngn'] ?? $row['pledged_amount_ngn'])
                : round($amount * $rate, 2);

            $items[] = [
                'id' => (string) ($row['id'] ?? Str::uuid()->toString()),
                'sequence' => (int) ($row['sequence'] ?? $sequence),
                'due_date' => $this->normalizeDate($row['due_date'] ?? null),
                'amount' => $amount,
                'amount_ngn' => $amountNgn,
                'is_paused' => (bool) ($row['is_paused'] ?? false),
            ];
        }

        usort($items, fn (array $a, array $b): int => $a['sequence'] <=> $b['sequence']);

        return $items;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateDefaultSchedule(Pledge $pledge): array
    {
        $count = max(1, (int) ($pledge->installment_count ?? 1));
        $plan = $pledge->payment_plan_type instanceof PledgePaymentPlanType
            ? $pledge->payment_plan_type
            : PledgePaymentPlanType::tryFrom((string) $pledge->payment_plan_type);

        if ($plan === PledgePaymentPlanType::ONE_TIME_FUTURE) {
            $count = 1;
        }

        $committed = (float) $pledge->committed_amount;
        $rate = $this->pledgeNgnRate($pledge);
        $committedNgn = (float) ($pledge->committed_amount_ngn ?? round($committed * $rate, 2));
        $baseAmount = floor(($committed / $count) * 100) / 100;
        $baseNgn = floor(($committedNgn / $count) * 100) / 100;

        $items = [];
        $allocated = 0.0;
        $allocatedNgn = 0.0;
        $start = $pledge->created_at ?? now();

        for ($i = 1; $i <= $count; $i++) {
            $isLast = $i === $count;
            $amount = $isLast ? round($committed - $allocated, 2) : $baseAmount;
            $amountNgn = $isLast ? round($committedNgn - $allocatedNgn, 2) : $baseNgn;
            $allocated += $amount;
            $allocatedNgn += $amountNgn;

            $items[] = [
                'id' => (string) Str::uuid(),
                'sequence' => $i,
                'due_date' => $this->defaultDueDate($plan, $start, $i)->toDateString(),
                'amount' => $amount,
                'amount_ngn' => $amountNgn,
                'is_paused' => false,
            ];
        }

        return $items;
    }

    private function defaultDueDate(?PledgePaymentPlanType $plan, CarbonInterface $start, int $sequence): CarbonInterface
    {
        return match ($plan) {
            PledgePaymentPlanType::MONTHLY => $start->copy()->addMonths($sequence),
            PledgePaymentPlanType::QUARTERLY => $start->copy()->addMonths($sequence * 3),
            PledgePaymentPlanType::ONE_TIME_FUTURE => $start->copy()->addMonth(),
            default => $start->copy()->addMonths($sequence),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  Collection<int, Transaction>  $transactions
     * @return array{by_item: array<string, array{amount: float, amount_ngn: float}>}
     */
    private function allocatePayments(array $items, Collection $transactions, Pledge $pledge): array
    {
        $byItem = [];
        foreach ($items as $item) {
            $byItem[(string) $item['id']] = ['amount' => 0.0, 'amount_ngn' => 0.0];
        }

        $unallocatedPool = [];

        foreach ($transactions as $transaction) {
            $meta = is_array($transaction->metadata) ? $transaction->metadata : [];
            $itemId = isset($meta['schedule_item_id']) ? (string) $meta['schedule_item_id'] : null;
            $amount = $this->transactionPledgeAmount($transaction, $meta);
            $amountNgn = $this->transactionPledgeAmountNgn($transaction, $meta, $amount, $pledge);

            if ($itemId !== null && isset($byItem[$itemId])) {
                $byItem[$itemId]['amount'] += $amount;
                $byItem[$itemId]['amount_ngn'] += $amountNgn;
            } else {
                $unallocatedPool[] = ['amount' => $amount, 'amount_ngn' => $amountNgn];
            }
        }

        foreach ($unallocatedPool as $pool) {
            foreach ($items as $item) {
                $id = (string) $item['id'];
                $pledged = (float) $item['amount'];
                $already = $byItem[$id]['amount'];
                $room = $pledged - $already;
                if ($room <= 0.00001) {
                    continue;
                }

                $apply = min($pool['amount'], $room);
                if ($apply <= 0.00001) {
                    continue;
                }

                $ratio = $pool['amount'] > 0 ? $apply / $pool['amount'] : 1;
                $applyNgn = $pool['amount_ngn'] * $ratio;

                $byItem[$id]['amount'] += $apply;
                $byItem[$id]['amount_ngn'] += $applyNgn;

                $pool['amount'] -= $apply;
                $pool['amount_ngn'] -= $applyNgn;

                if ($pool['amount'] <= 0.00001) {
                    break;
                }
            }
        }

        return ['by_item' => $byItem];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function transactionPledgeAmount(Transaction $transaction, array $meta): float
    {
        if (isset($meta['pledge_allocation_amount'])) {
            return (float) $meta['pledge_allocation_amount'];
        }

        return (float) $transaction->amount;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function transactionPledgeAmountNgn(Transaction $transaction, array $meta, float $amount, Pledge $pledge): float
    {
        if (isset($meta['pledge_allocation_amount_ngn'])) {
            return (float) $meta['pledge_allocation_amount_ngn'];
        }

        if ($transaction->amount_in_naira !== null) {
            $txnAmount = (float) $transaction->amount;
            if ($txnAmount > 0 && abs($txnAmount - $amount) > 0.00001) {
                return (float) $transaction->amount_in_naira * ($amount / $txnAmount);
            }

            return (float) $transaction->amount_in_naira;
        }

        $rate = $this->pledgeNgnRate($pledge);

        return round($amount * $rate, 2);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private function resolveItemStatus(
        array $item,
        float $paidAmount,
        float $pledgedAmount,
        bool $schedulePaused,
        bool $itemPaused,
    ): PledgeScheduleItemStatus {
        $remaining = $pledgedAmount - $paidAmount;

        if ($remaining <= 0.00001) {
            return PledgeScheduleItemStatus::PAID;
        }

        if ($paidAmount > 0.00001) {
            return PledgeScheduleItemStatus::PARTIAL;
        }

        if ($itemPaused || $schedulePaused) {
            return PledgeScheduleItemStatus::PAUSED;
        }

        $due = $item['due_date'] ?? null;
        if (is_string($due) && $due !== '' && Carbon::parse($due)->endOfDay()->isPast()) {
            return PledgeScheduleItemStatus::OVERDUE;
        }

        return PledgeScheduleItemStatus::PENDING;
    }

    private function pledgeNgnRate(Pledge $pledge): float
    {
        if ($pledge->exchange_rate_to_naira !== null && (float) $pledge->exchange_rate_to_naira > 0) {
            return (float) $pledge->exchange_rate_to_naira;
        }

        $committed = (float) $pledge->committed_amount;
        $ngn = (float) ($pledge->committed_amount_ngn ?? 0);

        return $committed > 0 ? $ngn / $committed : 1.0;
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
