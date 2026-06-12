<?php

namespace App\Mail;

use App\Models\DonorRecognition;
use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DonorRecognitionRevokedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly DonorRecognition $recognition,
        public readonly Theme $mailTheme,
        public readonly string $recipientName,
        public readonly string $tierName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Certificate revoked: '.$this->recognition->recognition_number,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.donation.recognition-revoked',
            with: [
                'recognition' => $this->recognition,
                'theme' => $this->mailTheme,
                'recipientName' => $this->recipientName,
                'tierName' => $this->tierName,
                'subject' => 'Certificate revoked: '.$this->recognition->recognition_number,
            ],
        );
    }
}
