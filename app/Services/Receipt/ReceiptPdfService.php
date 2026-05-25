<?php

namespace App\Services\Receipt;

use App\Enums\Currency;
use App\Enums\DonorTypeSlug;
use App\Enums\TransactionStatus;
use App\Helpers\GeneralHelper;
use App\Models\Transaction;
use App\Models\TransactionReceipt;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Tier\TierResolutionService;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\HttpFoundation\Response;

class ReceiptPdfService
{
    public function __construct(
        private readonly ReceiptService $receiptService,
    ) {}

    public function streamDonationReceipt(Transaction $transaction): Response
    {
        if ($transaction->status !== TransactionStatus::SUCCESSFUL) {
            abort(422, 'Receipt available only for successful payments.');
        }

        return $this->streamPdf(
            $transaction,
            'pdf.donation-receipt',
            $this->receiptService->donationReceiptViewData($transaction),
            'donation-receipt-'.$transaction->transaction_id.'.pdf',
        );
    }

    public function streamTaxReceipt(Transaction $transaction): Response
    {
        if ($transaction->status !== TransactionStatus::SUCCESSFUL) {
            abort(422, 'Receipt available only for successful payments.');
        }

        if (! $this->receiptService->isEligibleForTaxReceipt($transaction)) {
            abort(422, 'Tax receipt is available only for corporate donations with registration and tax identification numbers.');
        }

        return $this->streamPdf(
            $transaction,
            'pdf.tax-exemption-receipt',
            $this->receiptService->taxReceiptViewData($transaction),
            'tax-receipt-'.$transaction->transaction_id.'.pdf',
        );
    }

    public function renderTaxReceiptBinary(Transaction $transaction): string
    {
        return $this->renderPdf(
            'pdf.tax-exemption-receipt',
            $this->receiptService->taxReceiptViewData($transaction),
        );
    }

    public function renderDonationReceiptBinary(Transaction $transaction): string
    {
        if ($transaction->status !== TransactionStatus::SUCCESSFUL) {
            throw new \InvalidArgumentException('Receipt available only for successful payments.');
        }

        $this->receiptService->getOrCreateReceiptRecord($transaction);

        return $this->renderPdf(
            'pdf.donation-receipt',
            $this->receiptService->donationReceiptViewData($transaction),
        );
    }

    /**
     * @param  array<string, mixed>  $viewData
     */
    private function streamPdf(Transaction $transaction, string $view, array $viewData, string $filename): Response
    {
        if ($transaction->status !== TransactionStatus::SUCCESSFUL) {
            abort(422, 'Receipt available only for successful payments.');
        }

        $this->receiptService->getOrCreateReceiptRecord($transaction);

        return new Response($this->renderPdf($view, $viewData), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * @param  array<string, mixed>  $viewData
     */
    private function renderPdf(string $view, array $viewData): string
    {
        $html = view($view, $viewData)->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return $dompdf->output();
    }
}
