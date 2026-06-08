<?php

namespace App\Mail;

use App\Models\ContactSubmission;
use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactSubmissionAdminNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ContactSubmission $submission,
        public readonly Theme $mailTheme,
        public readonly string $adminViewUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'New contact submission — '.$this->submission->full_name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact.admin-notification',
            with: [
                'submission' => $this->submission,
                'theme' => $this->mailTheme,
                'adminViewUrl' => $this->adminViewUrl,
                'userTypeLabel' => $this->submission->user_type->label(),
            ],
        );
    }
}
