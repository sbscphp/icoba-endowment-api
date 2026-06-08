<?php

namespace App\Jobs;

use App\Mail\ContactSubmissionAdminNotificationMail;
use App\Models\ContactSubmission;
use App\Services\Theme\ThemeResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendContactSubmissionAdminNotificationEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $submissionUuid,
    ) {}

    public function uniqueId(): string
    {
        return 'contact-submission-admin-notification:'.$this->submissionUuid;
    }

    public function handle(ThemeResolver $themeResolver): void
    {
        $submission = ContactSubmission::query()
            ->where('uuid', $this->submissionUuid)
            ->first();

        if ($submission === null || $submission->admin_notified_at !== null) {
            return;
        }

        $notifyEmail = strtolower(trim((string) config('endowment.contact_submission_notify_email')));
        if ($notifyEmail === '') {
            return;
        }

        try {
            $theme = $themeResolver->resolveForMail();
            $adminViewUrl = rtrim((string) config('app.admin_frontend_url'), '/')
                .'/contact-submissions/'.$submission->uuid;

            Mail::to($notifyEmail)->send(new ContactSubmissionAdminNotificationMail(
                submission: $submission,
                mailTheme: $theme,
                adminViewUrl: $adminViewUrl,
            ));

            $submission->forceFill(['admin_notified_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('Contact submission admin notification email failed: '.$e->getMessage(), [
                'submission_uuid' => $this->submissionUuid,
                'to' => $notifyEmail,
            ]);
        }
    }
}
