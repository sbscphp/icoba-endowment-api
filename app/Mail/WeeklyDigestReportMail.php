<?php

namespace App\Mail;

use App\Models\Theme;
use App\Services\Admin\Report\WeeklyDigest\WeeklyDigestReportService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WeeklyDigestReportMail extends Mailable
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
        $period = (string) ($this->report['period_label'] ?? $this->report['period_end'] ?? '');

        return new Envelope(
            subject: 'Weekly donations & pledges digest — '.$period,
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.reports.weekly-digest',
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
        $end = (string) ($this->report['period_end'] ?? now()->toDateString());
        $start = (string) ($this->report['period_start'] ?? $end);

        return [
            Attachment::fromData(fn (): string => $this->pdfContents, WeeklyDigestReportService::pdfFilename($start, $end))
                ->withMime('application/pdf'),
            Attachment::fromData(fn (): string => $this->csvContents, WeeklyDigestReportService::csvFilename($end))
                ->withMime('text/csv'),
        ];
    }
}
