<?php

namespace App\Services\Admin\Report\WeeklyDigest;

use App\Mail\WeeklyDigestReportMail;
use App\Models\Admin;
use App\Services\Theme\ThemeResolver;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Orchestrates the weekly digest: build data, render documents, keep a copy,
 * and email active admins.
 *
 * A report period is always a full Monday–Sunday week. The period is
 * identified by its end date (the Sunday); any date inside the week resolves
 * to that week.
 */
class WeeklyDigestReportService
{
    public function __construct(
        private readonly WeeklyDigestReportBuilder $builder,
        private readonly WeeklyDigestDocumentRenderer $renderer,
        private readonly ThemeResolver $themeResolver,
    ) {}

    /**
     * @return array{report: array<string, mixed>, pdf: string, csv: string, pdf_path: string|null, csv_path: string|null}
     */
    public function generate(?CarbonInterface $periodEnd = null, bool $store = true): array
    {
        $report = $this->builder->build($periodEnd);
        $pdf = $this->renderer->renderPdf($report);
        $csv = $this->renderer->renderOverdueCsv($report);

        $pdfPath = null;
        $csvPath = null;
        if ($store) {
            [$pdfPath, $csvPath] = $this->store($report['period_start'], $report['period_end'], $pdf, $csv);
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
     * @return array{period_start: string, period_end: string, recipients: list<string>, sent: bool, pdf_path: string|null, csv_path: string|null, overdue_donors: int}
     */
    public function generateAndSend(?CarbonInterface $periodEnd = null, ?array $recipientsOverride = null): array
    {
        $recipients = $recipientsOverride !== null
            ? $this->normalizeAddresses($recipientsOverride)
            : $this->recipients();

        $generated = $this->generate($periodEnd);
        $report = $generated['report'];

        if ($recipients === []) {
            Log::warning('Weekly digest report generated but no recipients were resolved.', [
                'period_start' => $report['period_start'],
                'period_end' => $report['period_end'],
            ]);

            return [
                'period_start' => $report['period_start'],
                'period_end' => $report['period_end'],
                'recipients' => [],
                'sent' => false,
                'pdf_path' => $generated['pdf_path'],
                'csv_path' => $generated['csv_path'],
                'overdue_donors' => (int) ($report['overdue']['totals']['donors'] ?? 0),
            ];
        }

        Mail::to($recipients)->send(new WeeklyDigestReportMail(
            report: $report,
            pdfContents: $generated['pdf'],
            csvContents: $generated['csv'],
            mailTheme: $this->themeResolver->resolveForMail(),
        ));

        Log::info('Weekly digest report sent.', [
            'period_start' => $report['period_start'],
            'period_end' => $report['period_end'],
            'recipients' => count($recipients),
            'overdue_donors' => (int) ($report['overdue']['totals']['donors'] ?? 0),
        ]);

        return [
            'period_start' => $report['period_start'],
            'period_end' => $report['period_end'],
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

        $extra = (array) config('reports.weekly_digest.extra_recipients', []);

        return $this->normalizeAddresses(array_merge($adminEmails, $extra));
    }

    public static function pdfFilename(string $periodStart, string $periodEnd): string
    {
        return 'weekly-digest-'.$periodStart.'-to-'.$periodEnd.'.pdf';
    }

    public static function csvFilename(string $periodEnd): string
    {
        return 'overdue-pledges-'.$periodEnd.'.csv';
    }

    /**
     * Resolve the Sunday that ends the report week.
     *
     * Without a date this is the most recently completed week (the Sunday
     * before the current week). With a date, it is the Sunday of the week
     * containing that date.
     */
    public static function resolvePeriodEnd(?string $date): CarbonInterface
    {
        $timezone = (string) config('app.timezone', 'UTC');
        if ($date === null || trim($date) === '') {
            return now($timezone)->startOfWeek(CarbonInterface::MONDAY)->subDay()->startOfDay();
        }

        return Carbon::parse(trim($date), $timezone)->endOfWeek(CarbonInterface::SUNDAY)->startOfDay();
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
    private function store(string $periodStart, string $periodEnd, string $pdf, string $csv): array
    {
        $disk = (string) config('reports.weekly_digest.storage_disk', 'local');
        $directory = trim((string) config('reports.weekly_digest.storage_path', 'reports/weekly-digest'), '/');
        $pdfPath = $directory.'/'.self::pdfFilename($periodStart, $periodEnd);
        $csvPath = $directory.'/'.self::csvFilename($periodEnd);

        try {
            Storage::disk($disk)->put($pdfPath, $pdf);
            Storage::disk($disk)->put($csvPath, $csv);
        } catch (\Throwable $e) {
            Log::warning('Weekly digest report could not be stored: '.$e->getMessage(), [
                'disk' => $disk,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);

            return [null, null];
        }

        return [$pdfPath, $csvPath];
    }
}
