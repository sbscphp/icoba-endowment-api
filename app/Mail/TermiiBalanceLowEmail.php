<?php

namespace App\Mail;

use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TermiiBalanceLowEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly array $data,
        public readonly Theme $mailTheme,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Termii Balance Low Alert',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.termii-balance-low',
            with: [
                'data' => $this->data,
                'theme' => $this->mailTheme,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
