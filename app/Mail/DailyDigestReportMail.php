<?php

namespace App\Mail;

use App\Models\Theme;
use App\Services\Admin\Report\DailyDigest\DailyDigestReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class DailyDigestReportMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(
        public readonly array $report,
        public readonly string $pdfContents,
        public readonly string $csvContents,
        public readonly Theme $mailTheme,
    ) {}

    public function envelope(): Envelope
    {
        $date = (string) ($this->report['report_date_label'] ?? $this->report['report_date'] ?? '');

        return new Envelope(
            subject: 'Daily donations & pledges digest — '.$date,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reports.daily-digest',
            with: [
                'report' => $this->report,
                'theme' => $this->mailTheme,
            ],
        );
    }

    /**
     * @return list<Attachment>
     */
    public function attachments(): array
    {
        $date = (string) ($this->report['report_date'] ?? now()->toDateString());

        return [
            Attachment::fromData(fn (): string => $this->pdfContents, DailyDigestReportService::pdfFilename($date))
                ->withMime('application/pdf'),
            Attachment::fromData(fn (): string => $this->csvContents, DailyDigestReportService::csvFilename($date))
                ->withMime('text/csv'),
        ];
    }
}
