<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Resources\Public\PublicReceiptResource;
use App\Models\Transaction;
use App\Responser\JsonResponser;
use App\Services\Receipt\ReceiptPdfService;
use App\Services\Receipt\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ReceiptDownloadController extends Controller
{
    public function __construct(
        private readonly ReceiptPdfService $receiptPdfService,
        private readonly ReceiptService $receiptService,
    ) {}

    /**
     * Guest-facing receipt details as JSON. Same access rules as the PDF download:
     * an optional `token` query parameter is validated when supplied.
     */
    public function show(Request $request, string $receiptNumber): JsonResponse
    {
        try {
            $transaction = $this->resolveAuthorizedTransaction($request, $receiptNumber);
            $transaction->loadMissing('campaign', 'donor.donorType', 'donorType');

            return JsonResponser::send(false, 'Receipt retrieved.', PublicReceiptResource::make($transaction)->resolve());
        } catch (\Throwable $th) {
            if ($th instanceof HttpException) {
                throw $th;
            }

            return GeneralHelper::handleControllerThrowable($th, 'Public\ReceiptDownloadController@show');
        }
    }

    public function guestPdf(Request $request, string $receiptNumber): Response
    {
        try {
            $transaction = $this->resolveAuthorizedTransaction($request, $receiptNumber);

            return $this->receiptPdfService->streamDonationReceipt($transaction);
        } catch (\Throwable $th) {
            if ($th instanceof HttpException) {
                throw $th;
            }

            /** @var JsonResponse $resp */
            $resp = GeneralHelper::handleControllerThrowable($th, 'Public\ReceiptDownloadController@guestPdf');

            return $resp;
        }
    }

    public function guestTaxPdf(Request $request, string $receiptNumber): Response
    {
        try {
            $transaction = $this->resolveAuthorizedTransaction($request, $receiptNumber);

            return $this->receiptPdfService->streamTaxReceipt($transaction);
        } catch (\Throwable $th) {
            if ($th instanceof HttpException) {
                throw $th;
            }

            /** @var JsonResponse $resp */
            $resp = GeneralHelper::handleControllerThrowable($th, 'Public\ReceiptDownloadController@guestTaxPdf');

            return $resp;
        }
    }

    private function resolveAuthorizedTransaction(Request $request, string $receiptNumber): Transaction
    {
        $transaction = $this->receiptService->resolveByReceiptNumber($receiptNumber);

        $token = trim((string) $request->query('token', ''));
        if ($token === '') {
            return $transaction;
        }

        if ($transaction->receipt_token === null || ! hash_equals((string) $transaction->receipt_token, $token)) {
            abort(403, 'Invalid receipt token.');
        }

        return $transaction;
    }
}
