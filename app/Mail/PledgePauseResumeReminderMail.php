<?php

namespace App\Mail;

use App\Models\Pledge;
use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PledgePauseResumeReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $nextInstallment
     */
    public function __construct(
        public readonly Pledge $pledge,
        public readonly Theme $mailTheme,
        public readonly string $recipientName,
        public readonly string $campaignName,
        public readonly string $resumeDate,
        public readonly string $reminderKind,
        public readonly ?array $nextInstallment = null,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->reminderKind === 'on_resume_date'
            ? 'Your pledge has resumed — '.$this->mailTheme->brand_name
            : 'Your pledge will resume soon — '.$this->mailTheme->brand_name;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pledge.pause-resume-reminder',
            with: [
                'pledge' => $this->pledge,
                'theme' => $this->mailTheme,
                'recipientName' => $this->recipientName,
                'campaignName' => $this->campaignName,
                'resumeDate' => $this->resumeDate,
                'reminderKind' => $this->reminderKind,
                'nextInstallment' => $this->nextInstallment,
            ],
        );
    }
}
