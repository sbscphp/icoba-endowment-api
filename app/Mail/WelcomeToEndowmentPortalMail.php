<?php

namespace App\Mail;

use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WelcomeToEndowmentPortalMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $recipientName,
        public readonly Theme $mailTheme,
        public readonly string $loginUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to the ICOBA Endowment portal',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.auth.welcome-endowment',
            with: [
                'recipientName' => $this->recipientName,
                'theme' => $this->mailTheme,
                'loginUrl' => $this->loginUrl,
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
