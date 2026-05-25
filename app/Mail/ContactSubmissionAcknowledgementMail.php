<?php

namespace App\Mail;

use App\Models\ContactSubmission;
use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ContactSubmissionAcknowledgementMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ContactSubmission $submission,
        public readonly Theme $mailTheme,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'We received your message — '.$this->mailTheme->brand_name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.contact.acknowledgement',
            with: [
                'submission' => $this->submission,
                'theme' => $this->mailTheme,
                'recipientName' => $this->submission->full_name,
                'userTypeLabel' => $this->submission->user_type->label(),
            ],
        );
    }
}
