<?php

namespace App\Jobs;

use App\Mail\ContactSubmissionAcknowledgementMail;
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

class SendContactSubmissionAcknowledgementEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $submissionUuid,
    ) {}

    public function uniqueId(): string
    {
        return 'contact-submission-acknowledgement:'.$this->submissionUuid;
    }

    public function handle(ThemeResolver $themeResolver): void
    {
        $submission = ContactSubmission::query()
            ->where('uuid', $this->submissionUuid)
            ->first();

        if ($submission === null || $submission->email_sent_at !== null) {
            return;
        }

        $recipientEmail = trim((string) $submission->email);
        if ($recipientEmail === '') {
            return;
        }

        try {
            $theme = $themeResolver->resolveForMail();

            Mail::to($recipientEmail)->send(new ContactSubmissionAcknowledgementMail(
                submission: $submission,
                mailTheme: $theme,
            ));

            $submission->forceFill(['email_sent_at' => now()])->save();
        } catch (\Throwable $e) {
            Log::warning('Contact submission acknowledgement email failed: '.$e->getMessage(), [
                'submission_uuid' => $this->submissionUuid,
                'to' => $recipientEmail,
            ]);
        }
    }
}
