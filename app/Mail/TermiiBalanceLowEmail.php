<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class TermiiBalanceLowEmail extends Mailable
{
    use Queueable, SerializesModels;

    public $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function envelope()
    {
        return new Envelope(
            subject: 'Termii Balance Low Alert',
        );
    }

    public function content()
    {
        return new Content(
            text: 'emails.termii-balance-low',
            with: [
                'data' => $this->data,
            ],
        );
    }

    public function attachments()
    {
        return [];
    }
}
