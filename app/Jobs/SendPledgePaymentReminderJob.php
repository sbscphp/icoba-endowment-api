<?php

namespace App\Jobs;

use App\Enums\ModuleEnums;
use App\Enums\PledgeStatus;
use App\Mail\PledgePaymentReminderMail;
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

class SendPledgePaymentReminderJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 86400;

    public function __construct(
        public readonly string $pledgeUuid,
        public readonly string $scheduleItemId,
        public readonly string $dueDate,
    ) {}

    public function uniqueId(): string
    {
        return 'pledge-payment-reminder:'.$this->pledgeUuid.':'.$this->scheduleItemId.':'.$this->dueDate;
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

        if ($scheduleService->isPledgePaused($pledge)) {
            return;
        }

        if ($scheduleService->wasPaymentReminderSent($pledge, $this->scheduleItemId, $this->dueDate)) {
            return;
        }

        $view = $scheduleService->buildForPledge($pledge);
        $item = collect($view['items'])->firstWhere('id', $this->scheduleItemId);
        if ($item === null || ($item['due_date'] ?? null) !== $this->dueDate) {
            return;
        }

        if ((float) $item['remaining_amount'] <= 0.00001) {
            return;
        }

        $recipientEmail = trim((string) ($pledge->donor_email ?? $pledge->donor?->email ?? ''));
        if ($recipientEmail === '') {
            return;
        }

        $recipientName = trim((string) ($pledge->donor_name ?? ''));
        if ($recipientName === '' && $pledge->donor !== null) {
            $recipientName = trim(implode(' ', array_filter([
                (string) ($pledge->donor->firstname ?? ''),
                (string) ($pledge->donor->lastname ?? ''),
            ])));
        }
        if ($recipientName === '') {
            $recipientName = 'Donor';
        }

        try {
            Mail::to($recipientEmail)->send(new PledgePaymentReminderMail(
                pledge: $pledge,
                installment: $item,
                mailTheme: $themeResolver->resolveForMail(),
                recipientName: $recipientName,
                campaignName: $pledge->campaign?->name ?? 'General Endowment Fund',
                dueDate: $this->dueDate,
            ));

            $scheduleService->markPaymentReminderSent(
                $pledge->fresh() ?? $pledge,
                $this->scheduleItemId,
                $this->dueDate
            );

            $frontendBase = rtrim((string) config('app.frontend_url'), '/');
            $actionUrl = $frontendBase !== ''
                ? $frontendBase.'/pledges/'.$pledge->uuid
                : null;

            $notificationDispatch->notifyDonor(
                $pledge->user_uuid,
                $pledge->donor_email ?? $pledge->donor?->email,
                new GenericDatabaseNotification(
                    module: ModuleEnums::pledges->value,
                    event: 'pledge.payment_reminder',
                    title: 'Pledge payment reminder',
                    message: 'A pledge installment is due on '.$this->dueDate.'. We have emailed you the payment details.',
                    meta: [
                        'pledge_uuid' => $pledge->uuid,
                        'schedule_item_id' => $this->scheduleItemId,
                        'due_date' => $this->dueDate,
                        'remaining_amount' => (string) ($item['remaining_amount'] ?? ''),
                        'campaign_name' => $pledge->campaign?->name ?? 'General Endowment Fund',
                    ],
                    actionUrl: $actionUrl,
                    icon: '/icons/pledge-reminder.png',
                    severity: 'warning',
                    tags: ['pledge', 'reminder'],
                    sendMail: false,
                ),
            );
        } catch (\Throwable $e) {
            Log::warning('Pledge payment reminder email failed: '.$e->getMessage(), [
                'pledge_uuid' => $this->pledgeUuid,
                'schedule_item_id' => $this->scheduleItemId,
                'due_date' => $this->dueDate,
                'to' => $recipientEmail,
            ]);
        }
    }
}
