<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ExchangeRateStaleNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $message,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'ICOBA Endowment Exchange Rate Alert',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.exchange-rate-stale',
            with: [
                'alertMessage' => $this->message,
            ],
        );
    }

    public function attachments(): array
    {
        return [];
    }
}
