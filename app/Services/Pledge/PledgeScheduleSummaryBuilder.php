<?php

namespace App\Services\Pledge;

use App\Enums\PledgeScheduleItemStatus;
use App\Enums\PledgeStatus;
use App\Models\Pledge;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Derives a compact health summary (installments paid, overdue state, next
 * installment, reminder history) from a pledge and its schedule view.
 *
 * Overdue definition matches the daily digest: an installment whose due date is
 * on or before "today" and still has a remaining balance, on an active pledge
 * that is not paused. Partially paid installments count as overdue.
 */
class PledgeScheduleSummaryBuilder
{
    private const EPSILON = 0.00001;

    public function __construct(
        private readonly PledgeScheduleService $scheduleService,
    ) {}

    /**
     * @param  array<string, mixed>  $scheduleView  Output of PledgeScheduleService::buildForPledge()
     * @return array<string, mixed>
     */
    public function build(Pledge $pledge, array $scheduleView, ?string $lastPaymentAt = null, ?CarbonInterface $asOf = null): array
    {
        $asOf = ($asOf?->copy() ?? now((string) config('app.timezone', 'UTC')))->startOfDay();
        $today = $asOf->toDateString();

        $items = is_array($scheduleView['items'] ?? null) ? $scheduleView['items'] : [];
        $isActive = $pledge->status === PledgeStatus::ACTIVE;
        $isPaused = $this->scheduleService->isPledgePaused($pledge);

        $paidCount = 0;
        $overdueCount = 0;
        $overdueAmount = 0.0;
        $overdueAmountNgn = 0.0;
        $earliestOverdue = null;
        $next = null;

        foreach ($items as $item) {
            $status = (string) ($item['status'] ?? '');
            $remaining = (float) ($item['remaining_amount'] ?? 0);
            $due = $item['due_date'] ?? null;

            if ($status === PledgeScheduleItemStatus::PAID->value) {
                $paidCount++;

                continue;
            }

            if ($remaining <= self::EPSILON) {
                continue;
            }

            if ($next === null) {
                $next = $this->compactItem($item);
            }

            if (! $isActive || $isPaused || ! is_string($due) || $due === '') {
                continue;
            }

            if ($due <= $today) {
                $overdueCount++;
                $overdueAmount += $remaining;
                $overdueAmountNgn += (float) ($item['remaining_amount_ngn'] ?? 0);
                if ($earliestOverdue === null || $due < $earliestOverdue) {
                    $earliestOverdue = $due;
                }
            }
        }

        $daysOverdue = $earliestOverdue !== null
            ? (int) Carbon::parse($earliestOverdue)->startOfDay()->diffInDays($asOf) + 1
            : 0;

        $manual = $this->manualReminders($pledge);
        $lastManual = $manual[array_key_last($manual)] ?? null;
        $automaticSent = data_get($pledge->metadata, 'payment_reminders_sent', []);

        return [
            'installments_total' => count($items),
            'installments_paid' => $paidCount,
            'installments_outstanding' => max(0, count($items) - $paidCount),
            'is_overdue' => $overdueCount > 0,
            'overdue_installments' => $overdueCount,
            'overdue_amount' => $this->money($overdueAmount),
            'overdue_amount_ngn' => $this->money($overdueAmountNgn),
            'earliest_overdue_due_date' => $earliestOverdue,
            'days_overdue' => $daysOverdue,
            'next_installment' => $next,
            'last_payment_at' => $lastPaymentAt,
            'automatic_reminders_sent' => is_array($automaticSent) ? count($automaticSent) : 0,
            'manual_reminders_sent' => count($manual),
            'last_reminder_sent_at' => is_array($lastManual) ? ($lastManual['sent_at'] ?? null) : null,
        ];
    }

    /**
     * Manual (admin-triggered) reminder history stored on the pledge metadata, oldest first.
     *
     * @return list<array<string, mixed>>
     */
    public function manualReminders(Pledge $pledge): array
    {
        $history = data_get($pledge->metadata, 'manual_reminders', []);
        if (! is_array($history)) {
            return [];
        }

        return array_values(array_filter($history, 'is_array'));
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function compactItem(array $item): array
    {
        return [
            'id' => (string) ($item['id'] ?? ''),
            'sequence' => (int) ($item['sequence'] ?? 0),
            'due_date' => $item['due_date'] ?? null,
            'pledged_amount' => $item['pledged_amount'] ?? null,
            'paid_amount' => $item['paid_amount'] ?? null,
            'remaining_amount' => $item['remaining_amount'] ?? null,
            'remaining_amount_ngn' => $item['remaining_amount_ngn'] ?? null,
            'currency' => $item['currency'] ?? null,
            'status' => $item['status'] ?? null,
        ];
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
