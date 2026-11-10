<?php

namespace App\Mail;

use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountDeletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly Theme $mailTheme,
        public readonly int $pledgesCancelled,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your '.$this->mailTheme->brand_name.' account has been deleted',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.account-deleted',
            with: [
                'recipientName' => $this->recipientName,
                'theme' => $this->mailTheme,
                'pledgesCancelled' => $this->pledgesCancelled,
            ],
        );
    }
}
