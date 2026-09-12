<?php

namespace App\Mail;

use App\Models\Pledge;
use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PledgeManualReminderMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>|null  $installment
     */
    public function __construct(
        public readonly Pledge $pledge,
        public readonly ?array $installment,
        public readonly Theme $mailTheme,
        public readonly string $recipientName,
        public readonly string $campaignName,
        public readonly string $fulfilledAmount,
        public readonly string $remainingAmount,
        public readonly int $overdueInstallments,
        public readonly bool $isOverdue,
        public readonly ?string $note,
        public readonly ?string $portalUrl,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->isOverdue
            ? 'Overdue pledge payment reminder — '.$this->mailTheme->brand_name
            : 'Pledge payment reminder — '.$this->mailTheme->brand_name;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.pledge.manual-reminder',
            with: [
                'pledge' => $this->pledge,
                'installment' => $this->installment,
                'theme' => $this->mailTheme,
                'recipientName' => $this->recipientName,
                'campaignName' => $this->campaignName,
                'fulfilledAmount' => $this->fulfilledAmount,
                'remainingAmount' => $this->remainingAmount,
                'overdueInstallments' => $this->overdueInstallments,
                'isOverdue' => $this->isOverdue,
                'note' => $this->note,
                'portalUrl' => $this->portalUrl,
            ],
        );
    }
}
