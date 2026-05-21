<?php

namespace App\Mail;

use App\Models\Campaign;
use App\Models\CampaignEmail;
use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CampaignBulkEmailMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly CampaignEmail $campaignEmail,
        public readonly Campaign $campaign,
        public readonly string $recipientEmail,
        public readonly Theme $mailTheme,
        public readonly ?string $recipientName = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: (string) $this->campaignEmail->title,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.campaign.message',
            with: [
                'campaignName' => $this->campaign->name,
                'recipientName' => $this->recipientName,
                'bodyHtml' => $this->campaignEmail->content,
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
