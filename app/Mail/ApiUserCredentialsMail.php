<?php

namespace App\Mail;

use App\Models\ApiUser;
use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApiUserCredentialsMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly ApiUser $apiUser,
        public readonly Theme $mailTheme,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your API credentials — '.config('app.name'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.api.api-user-credentials',
            with: [
                'apiUser' => $this->apiUser,
                'theme' => $this->mailTheme,
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
