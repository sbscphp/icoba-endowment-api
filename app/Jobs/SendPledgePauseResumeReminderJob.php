<?php

namespace App\Jobs;

use App\Enums\ModuleEnums;
use App\Enums\PledgeScheduleItemStatus;
use App\Enums\PledgeStatus;
use App\Mail\PledgePauseResumeReminderMail;
use App\Models\Pledge;
use App\Notifications\GenericDatabaseNotification;
use App\Services\Notifications\NotificationDispatchService;
use App\Services\Pledge\PledgeScheduleService;
use App\Services\Theme\ThemeResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPledgePauseResumeReminderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 86400;

    public function __construct(
        public readonly string $pledgeUuid,
        public readonly string $resumeDate,
        public readonly string $reminderKind,
    ) {}

    public function uniqueId(): string
    {
        return 'pledge-pause-resume-reminder:'.$this->pledgeUuid.':'.$this->resumeDate.':'.$this->reminderKind;
    }

    public function handle(
        PledgeScheduleService $scheduleService,
        ThemeResolver $themeResolver,
        NotificationDispatchService $notificationDispatch,
    ): void {
        $pledge = Pledge::query()
            ->where('uuid', $this->pledgeUuid)
            ->with(['campaign:uuid,name', 'donor:uuid,firstname,lastname,email'])
            ->first();

        if ($pledge === null || $pledge->status !== PledgeStatus::ACTIVE) {
            return;
        }

        if ($scheduleService->pledgeResumeDate($pledge) !== $this->resumeDate) {
            return;
        }

        if ($scheduleService->wasPauseResumeReminderSent($pledge, $this->resumeDate, $this->reminderKind)) {
            return;
        }

        if ($this->reminderKind !== 'on_resume_date' && ! $scheduleService->isPledgePaused($pledge)) {
            return;
        }

        if ($this->reminderKind === 'on_resume_date' && $scheduleService->isPledgePaused($pledge)) {
            $pledge = $scheduleService->resumePledge($pledge);
        }

        $recipientEmail = trim((string) ($pledge->donor_email ?? $pledge->donor?->email ?? ''));
        if ($recipientEmail === '') {
            return;
        }

        $recipientName = $this->resolveRecipientName($pledge);
        $nextInstallment = $this->reminderKind === 'on_resume_date'
            ? $this->resolveNextInstallment($scheduleService, $pledge)
            : null;

        try {
            Mail::to($recipientEmail)->send(new PledgePauseResumeReminderMail(
                pledge: $pledge,
                mailTheme: $themeResolver->resolveForMail(),
                recipientName: $recipientName,
                campaignName: $pledge->campaign?->name ?? 'General Endowment Fund',
                resumeDate: $this->resumeDate,
                reminderKind: $this->reminderKind,
                nextInstallment: $nextInstallment,
            ));

            $scheduleService->markPauseResumeReminderSent(
                $pledge->fresh() ?? $pledge,
                $this->resumeDate,
                $this->reminderKind,
            );

            $this->notifyDonor(
                $notificationDispatch,
                $pledge,
                $recipientEmail,
                $nextInstallment,
            );
        } catch (\Throwable $e) {
            Log::warning('Pledge pause/resume reminder email failed: '.$e->getMessage(), [
                'pledge_uuid' => $this->pledgeUuid,
                'resume_date' => $this->resumeDate,
                'reminder_kind' => $this->reminderKind,
                'to' => $recipientEmail,
            ]);
        }
    }

    private function resolveRecipientName(Pledge $pledge): string
    {
        $recipientName = trim((string) ($pledge->donor_name ?? ''));
        if ($recipientName === '' && $pledge->donor !== null) {
            $recipientName = trim(implode(' ', array_filter([
                (string) ($pledge->donor->firstname ?? ''),
                (string) ($pledge->donor->lastname ?? ''),
            ])));
        }

        return $recipientName !== '' ? $recipientName : 'Donor';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveNextInstallment(PledgeScheduleService $scheduleService, Pledge $pledge): ?array
    {
        $view = $scheduleService->buildForPledge($pledge);

        foreach ($view['items'] as $item) {
            if (! in_array($item['status'], [
                PledgeScheduleItemStatus::PENDING->value,
                PledgeScheduleItemStatus::PARTIAL->value,
                PledgeScheduleItemStatus::OVERDUE->value,
            ], true)) {
                continue;
            }

            if ((float) $item['remaining_amount'] <= 0.00001) {
                continue;
            }

            return $item;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>|null  $nextInstallment
     */
    private function notifyDonor(
        NotificationDispatchService $notificationDispatch,
        Pledge $pledge,
        string $recipientEmail,
        ?array $nextInstallment,
    ): void {
        $frontendBase = rtrim((string) config('app.frontend_url'), '/');
        $actionUrl = $frontendBase !== ''
            ? $frontendBase.'/pledges/'.$pledge->uuid
            : null;

        if ($this->reminderKind === 'on_resume_date') {
            $message = $nextInstallment !== null
                ? 'Your pledge has resumed. Your next installment is due on '.($nextInstallment['due_date'] ?? $this->resumeDate).'.'
                : 'Your pledge has resumed. Sign in to review your payment schedule.';

            $notificationDispatch->notifyDonor(
                $pledge->user_uuid,
                $pledge->donor_email ?? $pledge->donor?->email ?? $recipientEmail,
                new GenericDatabaseNotification(
                    module: ModuleEnums::pledges->value,
                    event: 'pledge.resumed',
                    title: 'Pledge resumed',
                    message: $message,
                    meta: [
                        'pledge_uuid' => $pledge->uuid,
                        'resume_date' => $this->resumeDate,
                        'next_due_date' => $nextInstallment['due_date'] ?? null,
                        'campaign_name' => $pledge->campaign?->name ?? 'General Endowment Fund',
                    ],
                    actionUrl: $actionUrl,
                    icon: '/icons/pledge-resumed.png',
                    severity: 'info',
                    tags: ['pledge', 'resume'],
                    sendMail: false,
                ),
            );

            return;
        }

        $daysBefore = (int) strtok($this->reminderKind, '_');
        $notificationDispatch->notifyDonor(
            $pledge->user_uuid,
            $pledge->donor_email ?? $pledge->donor?->email ?? $recipientEmail,
            new GenericDatabaseNotification(
                module: ModuleEnums::pledges->value,
                event: 'pledge.resume_reminder',
                title: 'Pledge resume reminder',
                message: 'Your pledge is scheduled to automatically resume on '.$this->resumeDate
                    .($daysBefore > 0 ? ' ('.$daysBefore.' day'.($daysBefore === 1 ? '' : 's').' from now).' : '.'),
                meta: [
                    'pledge_uuid' => $pledge->uuid,
                    'resume_date' => $this->resumeDate,
                    'reminder_kind' => $this->reminderKind,
                    'campaign_name' => $pledge->campaign?->name ?? 'General Endowment Fund',
                ],
                actionUrl: $actionUrl,
                icon: '/icons/pledge-reminder.png',
                severity: 'warning',
                tags: ['pledge', 'reminder', 'pause'],
                sendMail: false,
            ),
        );
    }
}
