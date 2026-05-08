<?php

namespace App\Mail;

use App\Enums\EmailDesignTemplate;
use App\Models\Campaign;
use App\Models\CampaignEmail;
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
        $template = $this->campaignEmail->design_template;
        $view = match ($template) {
            EmailDesignTemplate::BENTO => 'mail.campaign.bento',
            EmailDesignTemplate::CORE => 'mail.campaign.core',
            EmailDesignTemplate::MINIMAL => 'mail.campaign.minimal',
            default => 'mail.campaign.classic',
        };

        return new Content(
            view: $view,
            with: [
                'campaignName' => $this->campaign->name,
                'recipientName' => $this->recipientName,
                'bodyHtml' => $this->campaignEmail->content,
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
