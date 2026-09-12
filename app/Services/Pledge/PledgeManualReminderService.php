<?php

namespace App\Services\Pledge;

use App\Enums\ModuleEnums;
use App\Enums\PledgeStatus;
use App\Mail\PledgeManualReminderMail;
use App\Models\Admin;
use App\Models\Pledge;
use App\Notifications\GenericDatabaseNotification;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Theme\ThemeResolver;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

/**
 * Admin-triggered pledge payment reminder: emails the donor about the next (or a
 * chosen) outstanding installment, drops an in-app notification, and records the
 * send on the pledge metadata so the history is visible on list and detail views.
 */
class PledgeManualReminderService
{
    private const EPSILON = 0.00001;

    private const HISTORY_LIMIT = 50;

    public function __construct(
        private readonly PledgeScheduleService $scheduleService,
        private readonly PledgeBalanceService $balanceService,
        private readonly PledgeScheduleSummaryBuilder $summaryBuilder,
        private readonly ThemeResolver $themeResolver,
        private readonly NotificationDispatchService $notificationDispatch,
    ) {}

    public function cooldownHours(): int
    {
        return max(0, (int) config('pledges.manual_reminder_cooldown_hours', 24));
    }

    /**
     * @param  array{schedule_item_id?: string|null, note?: string|null, force?: bool}  $options
     * @return array<string, mixed>
     */
    public function send(Pledge $pledge, Admin $admin, array $options = []): array
    {
        $this->assertRemindable($pledge);

        $recipientEmail = $this->recipientEmail($pledge);
        if ($recipientEmail === null) {
            throw ValidationException::withMessages([
                'pledge' => ['This pledge has no donor email address to send a reminder to.'],
            ]);
        }

        $force = (bool) ($options['force'] ?? false);
        $this->assertCooldownElapsed($pledge, $force);

        $schedule = $this->scheduleService->buildForPledge($pledge);
        $installment = $this->resolveInstallment($pledge, $schedule, $options['schedule_item_id'] ?? null);

        $fulfilled = $this->balanceService->fulfilledAmount($pledge);
        $remaining = $this->balanceService->remainingAmount($pledge);
        if ((float) $remaining <= self::EPSILON) {
            throw ValidationException::withMessages([
                'pledge' => ['This pledge has been fully paid; there is nothing to remind the donor about.'],
            ]);
        }

        $summary = $this->summaryBuilder->build($pledge, $schedule);
        $note = trim((string) ($options['note'] ?? ''));
        $note = $note !== '' ? $note : null;
        $recipientName = $this->recipientName($pledge);
        $campaignName = $pledge->campaign?->name ?? 'General Endowment Fund';
        $frontendBase = rtrim((string) config('app.frontend_url'), '/');
        $portalUrl = $frontendBase !== '' ? $frontendBase.'/pledges/'.$pledge->uuid : null;

        $isOverdue = $installment !== null
            && is_string($installment['due_date'] ?? null)
            && $installment['due_date'] <= now((string) config('app.timezone', 'UTC'))->toDateString();

        Mail::to($recipientEmail)->send(new PledgeManualReminderMail(
            pledge: $pledge,
            installment: $installment,
            mailTheme: $this->themeResolver->resolveForMail(),
            recipientName: $recipientName,
            campaignName: $campaignName,
            fulfilledAmount: $fulfilled,
            remainingAmount: $remaining,
            overdueInstallments: (int) $summary['overdue_installments'],
            isOverdue: $isOverdue,
            note: $note,
            portalUrl: $portalUrl,
        ));

        $sentAt = now();
        $record = [
            'sent_at' => $sentAt->toIso8601String(),
            'admin_uuid' => $admin->uuid,
            'admin_name' => $admin->displayName(),
            'recipient_email' => $recipientEmail,
            'schedule_item_id' => $installment['id'] ?? null,
            'due_date' => $installment['due_date'] ?? null,
            'installment_sequence' => $installment['sequence'] ?? null,
            'amount_due' => $installment['remaining_amount'] ?? $remaining,
            'is_overdue' => $isOverdue,
            'note' => $note,
            'forced' => $force,
        ];
        $this->appendHistory($pledge, $record);

        try {
            $this->notificationDispatch->notifyDonor(
                $pledge->user_uuid,
                $recipientEmail,
                new GenericDatabaseNotification(
                    module: ModuleEnums::pledges->value,
                    event: 'pledge.manual_reminder',
                    title: $isOverdue ? 'Overdue pledge payment reminder' : 'Pledge payment reminder',
                    message: $this->notificationMessage($campaignName, $installment, $remaining, $pledge->currency, $isOverdue),
                    meta: [
                        'pledge_uuid' => $pledge->uuid,
                        'schedule_item_id' => $installment['id'] ?? null,
                        'due_date' => $installment['due_date'] ?? null,
                        'remaining_amount' => $remaining,
                        'campaign_name' => $campaignName,
                        'note' => $note,
                    ],
                    actionUrl: $portalUrl,
                    icon: '/icons/pledge-reminder.png',
                    severity: $isOverdue ? 'warning' : 'info',
                    tags: ['pledge', 'reminder'],
                    sendMail: false,
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('Pledge manual reminder in-app notification failed: '.$e->getMessage(), [
                'pledge_uuid' => $pledge->uuid,
            ]);
        }

        $history = $this->summaryBuilder->manualReminders($pledge->fresh() ?? $pledge);

        return [
            'pledge_uuid' => $pledge->uuid,
            'recipient_email' => $recipientEmail,
            'recipient_name' => $recipientName,
            'sent_at' => $record['sent_at'],
            'installment' => $installment,
            'is_overdue' => $isOverdue,
            'fulfilled_amount' => $fulfilled,
            'remaining_amount' => $remaining,
            'currency' => $pledge->currency,
            'note' => $note,
            'manual_reminders_sent' => count($history),
        ];
    }

    private function assertRemindable(Pledge $pledge): void
    {
        if ($pledge->status !== PledgeStatus::ACTIVE) {
            $status = $pledge->status instanceof PledgeStatus ? $pledge->status->value : (string) $pledge->status;
            throw ValidationException::withMessages([
                'pledge' => ["Reminders can only be sent for active pledges; this pledge is {$status}."],
            ]);
        }

        if ($this->scheduleService->isPledgePaused($pledge)) {
            $resume = $this->scheduleService->pledgeResumeDate($pledge);
            $until = $resume !== null ? ' until '.Carbon::parse($resume)->format('F j, Y') : '';
            throw ValidationException::withMessages([
                'pledge' => ["This pledge is paused{$until}; resume it before sending a reminder."],
            ]);
        }
    }

    private function assertCooldownElapsed(Pledge $pledge, bool $force): void
    {
        if ($force) {
            return;
        }

        $hours = $this->cooldownHours();
        if ($hours <= 0) {
            return;
        }

        $history = $this->summaryBuilder->manualReminders($pledge);
        $last = $history[array_key_last($history)] ?? null;
        $lastAt = is_array($last) && is_string($last['sent_at'] ?? null) ? Carbon::parse($last['sent_at']) : null;
        if ($lastAt === null) {
            return;
        }

        if ($lastAt->gt(now()->subHours($hours))) {
            throw ValidationException::withMessages([
                'pledge' => [sprintf(
                    'A reminder was already sent %s. Wait %d hour%s between reminders or send again with force=true.',
                    $lastAt->diffForHumans(),
                    $hours,
                    $hours === 1 ? '' : 's',
                )],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $schedule
     * @return array<string, mixed>|null
     */
    private function resolveInstallment(Pledge $pledge, array $schedule, ?string $scheduleItemId): ?array
    {
        $items = is_array($schedule['items'] ?? null) ? $schedule['items'] : [];

        if ($scheduleItemId !== null && $scheduleItemId !== '') {
            $item = collect($items)->first(fn (array $i): bool => (string) ($i['id'] ?? '') === $scheduleItemId);
            if ($item === null) {
                throw ValidationException::withMessages([
                    'schedule_item_id' => ['The selected installment does not exist on this pledge.'],
                ]);
            }
            if ((float) ($item['remaining_amount'] ?? 0) <= self::EPSILON) {
                throw ValidationException::withMessages([
                    'schedule_item_id' => ['The selected installment has already been paid.'],
                ]);
            }

            return $item;
        }

        foreach ($items as $item) {
            if ((float) ($item['remaining_amount'] ?? 0) > self::EPSILON) {
                return $item;
            }
        }

        return null;
    }

    private function recipientEmail(Pledge $pledge): ?string
    {
        $email = trim((string) ($pledge->donor_email ?? $pledge->donor?->email ?? ''));

        return $email !== '' ? $email : null;
    }

    private function recipientName(Pledge $pledge): string
    {
        $name = trim((string) ($pledge->donor_name ?? ''));
        if ($name === '' && $pledge->donor !== null) {
            $name = trim(implode(' ', array_filter([
                (string) ($pledge->donor->firstname ?? ''),
                (string) ($pledge->donor->lastname ?? ''),
            ])));
        }

        return $name !== '' ? $name : 'Donor';
    }

    /**
     * @param  array<string, mixed>|null  $installment
     */
    private function notificationMessage(string $campaignName, ?array $installment, string $remaining, string $currency, bool $isOverdue): string
    {
        $due = is_array($installment) && is_string($installment['due_date'] ?? null)
            ? Carbon::parse($installment['due_date'])->format('F j, Y')
            : null;
        $amount = is_array($installment)
            ? number_format((float) ($installment['remaining_amount'] ?? 0), 2).' '.strtoupper($currency)
            : number_format((float) $remaining, 2).' '.strtoupper($currency);

        if ($isOverdue && $due !== null) {
            return "Your pledge installment of {$amount} for {$campaignName} was due on {$due} and is still outstanding.";
        }

        if ($due !== null) {
            return "A reminder about your pledge installment of {$amount} for {$campaignName}, due on {$due}.";
        }

        return "A reminder about your outstanding pledge balance of {$amount} for {$campaignName}.";
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function appendHistory(Pledge $pledge, array $record): void
    {
        $metadata = $pledge->metadata ?? [];
        $history = $this->summaryBuilder->manualReminders($pledge);
        $history[] = $record;
        if (count($history) > self::HISTORY_LIMIT) {
            $history = array_slice($history, -self::HISTORY_LIMIT);
        }
        $metadata['manual_reminders'] = array_values($history);

        // Persist via a clean instance: the given model may carry computed display
        // attributes (fulfilled_amount, schedule_summary, ...) that are not columns.
        $persisted = $pledge->fresh() ?? $pledge;
        $persisted->update(['metadata' => $metadata]);
        $pledge->setAttribute('metadata', $metadata);
        $pledge->syncOriginalAttribute('metadata');
    }
}
