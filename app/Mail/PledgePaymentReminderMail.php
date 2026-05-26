<?php

namespace App\Mail;

use App\Models\Pledge;
use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PledgePaymentReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $installment
     */
    public function __construct(
        public readonly Pledge $pledge,
        public readonly array $installment,
        public readonly Theme $mailTheme,
        public readonly string $recipientName,
        public readonly string $campaignName,
        public readonly string $dueDate,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Upcoming pledge payment reminder — '.$this->mailTheme->brand_name,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pledge.payment-reminder',
            with: [
                'pledge' => $this->pledge,
                'installment' => $this->installment,
                'theme' => $this->mailTheme,
                'recipientName' => $this->recipientName,
                'campaignName' => $this->campaignName,
                'dueDate' => $this->dueDate,
            ],
        );
    }
}
