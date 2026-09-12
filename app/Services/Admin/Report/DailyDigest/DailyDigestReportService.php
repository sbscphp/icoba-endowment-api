<?php

namespace App\Services\Admin\Report\DailyDigest;

use App\Mail\DailyDigestReportMail;
use App\Models\Admin;
use App\Services\Theme\ThemeResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrates the daily digest: build data, render documents, keep a copy,
 * and email active admins.
 */
class DailyDigestReportService
{
    public function __construct(
        private readonly DailyDigestReportBuilder $builder,
        private readonly DailyDigestDocumentRenderer $renderer,
        private readonly ThemeResolver $themeResolver,
    ) {}

    /**
     * @return array{report: array<string, mixed>, pdf: string, csv: string, pdf_path: string|null, csv_path: string|null}
     */
    public function generate(?CarbonInterface $reportDate = null, bool $store = true): array
    {
        $report = $this->builder->build($reportDate);
        $pdf = $this->renderer->renderPdf($report);
        $csv = $this->renderer->renderOverdueCsv($report);

        $pdfPath = null;
        $csvPath = null;
        if ($store) {
            [$pdfPath, $csvPath] = $this->store($report['report_date'], $pdf, $csv);
        }

        return [
            'report' => $report,
            'pdf' => $pdf,
            'csv' => $csv,
            'pdf_path' => $pdfPath,
            'csv_path' => $csvPath,
        ];
    }

    /**
     * Generate and email the digest.
     *
     * @param  list<string>|null  $recipientsOverride  When given, only these addresses receive the email.
     * @return array{report_date: string, recipients: list<string>, sent: bool, pdf_path: string|null, csv_path: string|null, overdue_donors: int}
     */
    public function generateAndSend(?CarbonInterface $reportDate = null, ?array $recipientsOverride = null): array
    {
        $recipients = $recipientsOverride !== null
            ? $this->normalizeAddresses($recipientsOverride)
            : $this->recipients();

        $generated = $this->generate($reportDate);
        $report = $generated['report'];

        if ($recipients === []) {
            Log::warning('Daily digest report generated but no recipients were resolved.', [
                'report_date' => $report['report_date'],
            ]);

            return [
                'report_date' => $report['report_date'],
                'recipients' => [],
                'sent' => false,
                'pdf_path' => $generated['pdf_path'],
                'csv_path' => $generated['csv_path'],
                'overdue_donors' => (int) ($report['overdue']['totals']['donors'] ?? 0),
            ];
        }

        Mail::to($recipients)->send(new DailyDigestReportMail(
            report: $report,
            pdfContents: $generated['pdf'],
            csvContents: $generated['csv'],
            mailTheme: $this->themeResolver->resolveForMail(),
        ));

        Log::info('Daily digest report sent.', [
            'report_date' => $report['report_date'],
            'recipients' => count($recipients),
            'overdue_donors' => (int) ($report['overdue']['totals']['donors'] ?? 0),
        ]);

        return [
            'report_date' => $report['report_date'],
            'recipients' => $recipients,
            'sent' => true,
            'pdf_path' => $generated['pdf_path'],
            'csv_path' => $generated['csv_path'],
            'overdue_donors' => (int) ($report['overdue']['totals']['donors'] ?? 0),
        ];
    }

    /**
     * Active admins who can log in and have not switched email notifications
     * off, plus any extra addresses from config.
     *
     * @return list<string>
     */
    public function recipients(): array
    {
        $adminEmails = Admin::query()
            ->where('is_active', true)
            ->where('can_login', true)
            ->where('email_notifications_enabled', true)
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->pluck('email')
            ->all();

        $extra = (array) config('reports.daily_digest.extra_recipients', []);

        return $this->normalizeAddresses(array_merge($adminEmails, $extra));
    }

    public static function pdfFilename(string $reportDate): string
    {
        return 'daily-digest-'.$reportDate.'.pdf';
    }

    public static function csvFilename(string $reportDate): string
    {
        return 'overdue-pledges-'.$reportDate.'.csv';
    }

    public static function resolveReportDate(?string $date): CarbonInterface
    {
        $timezone = (string) config('app.timezone', 'UTC');
        if ($date === null || trim($date) === '') {
            return now($timezone)->subDay()->startOfDay();
        }

        return Carbon::parse(trim($date), $timezone)->startOfDay();
    }

    /**
     * @param  list<mixed>  $addresses
     * @return list<string>
     */
    private function normalizeAddresses(array $addresses): array
    {
        $clean = [];
        foreach ($addresses as $address) {
            $address = strtolower(trim((string) $address));
            if ($address !== '' && filter_var($address, FILTER_VALIDATE_EMAIL) !== false) {
                $clean[$address] = $address;
            }
        }

        return array_values($clean);
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function store(string $reportDate, string $pdf, string $csv): array
    {
        $disk = (string) config('reports.daily_digest.storage_disk', 'local');
        $directory = trim((string) config('reports.daily_digest.storage_path', 'reports/daily-digest'), '/');
        $pdfPath = $directory.'/'.self::pdfFilename($reportDate);
        $csvPath = $directory.'/'.self::csvFilename($reportDate);

        try {
            Storage::disk($disk)->put($pdfPath, $pdf);
            Storage::disk($disk)->put($csvPath, $csv);
        } catch (\Throwable $e) {
            Log::warning('Daily digest report could not be stored: '.$e->getMessage(), [
                'disk' => $disk,
                'report_date' => $reportDate,
            ]);

            return [null, null];
        }

        return [$pdfPath, $csvPath];
    }
}
