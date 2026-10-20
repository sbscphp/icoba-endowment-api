<?php

namespace App\Mail;

use App\Models\Theme;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class GivingIdentityReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $summary
     */
    public function __construct(
        public readonly array $summary,
        public readonly string $generatedAt,
        public readonly string $xlsxContents,
        public readonly string $filename,
        public readonly Theme $mailTheme,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Giving identity report — '.$this->generatedAt,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reports.giving-identities',
            with: [
                'summary' => $this->summary,
                'generatedAt' => $this->generatedAt,
                'theme' => $this->mailTheme,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => $this->xlsxContents, $this->filename)
                ->withMime('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'),
        ];
    }
}
