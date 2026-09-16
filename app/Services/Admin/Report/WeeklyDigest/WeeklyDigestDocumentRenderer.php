<?php

namespace App\Services\Admin\Report\WeeklyDigest;

use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

/**
 * Turns the digest dataset into the PDF and CSV attachments.
 */
class WeeklyDigestDocumentRenderer
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function renderPdf(array $report): string
    {
        $html = view('pdf.admin-weekly-digest', [
            'report' => $report,
            'logoBase64' => $this->logoBase64(),
            'maxOverdueDonors' => max(1, (int) config('reports.weekly_digest.pdf_max_overdue_donors', 200)),
            'maxListRows' => max(1, (int) config('reports.weekly_digest.pdf_max_list_rows', 50)),
        ])->render();

        $options = new Options;
        $options->set('isPhpEnabled', true);
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * One row per overdue pledge, with the donor contact repeated so the sheet
     * can be sorted and filtered freely.
     *
     * @param  array<string, mixed>  $report
     */
    public function renderOverdueCsv(array $report): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [
            'Donor', 'Email', 'Phone', 'Donor Type', 'Graduation Set', 'Registered', 'Anonymous (public)',
            'Campaign', 'Campaign Code', 'Currency', 'Payment Plan', 'Installments', 'Installments Paid',
            'Committed', 'Committed (NGN)', 'Honored', 'Honored (NGN)', 'Pending', 'Pending (NGN)',
            'Overdue', 'Overdue (NGN)', 'Overdue Installments', 'Earliest Missed Due Date', 'Days Overdue',
            'Last Payment Date', 'Reminders Sent', 'Pledged On', 'Pledge Reference',
        ]);

        foreach ($report['overdue']['donors'] ?? [] as $group) {
            $donor = $group['donor'];
            foreach ($group['pledges'] as $pledge) {
                fputcsv($handle, [
                    $donor['name'],
                    $donor['email'],
                    $donor['phone'],
                    $donor['donor_type'],
                    $donor['graduation_set'],
                    $donor['is_registered'] ? 'Yes' : 'No',
                    $pledge['is_anonymous'] ? 'Yes' : 'No',
                    $pledge['campaign'],
                    $pledge['campaign_code'],
                    $pledge['currency'],
                    $pledge['payment_plan'],
                    $pledge['installment_count'],
                    $pledge['installments_paid'],
                    $pledge['committed'],
                    $pledge['committed_ngn'],
                    $pledge['honored'],
                    $pledge['honored_ngn'],
                    $pledge['pending'],
                    $pledge['pending_ngn'],
                    $pledge['overdue'],
                    $pledge['overdue_ngn'],
                    $pledge['overdue_installments'],
                    $pledge['earliest_due_date'],
                    $pledge['days_overdue'],
                    $pledge['last_payment_at'],
                    $pledge['reminders_sent'],
                    $pledge['pledged_on'],
                    $pledge['pledge_uuid'],
                ]);
            }
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function logoBase64(): ?string
    {
        $slug = Str::slug((string) config('app.name'));
        $candidates = [
            public_path('assets/logo/'.$slug.'.png'),
            public_path('assets/logo/icoba-endowment.png'),
        ];

        foreach ($candidates as $path) {
            if (File::exists($path)) {
                return 'data:image/png;base64,'.base64_encode(File::get($path));
            }
        }

        return null;
    }
}
