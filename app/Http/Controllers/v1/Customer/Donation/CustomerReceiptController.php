<?php

namespace App\Http\Controllers\v1\Customer\Donation;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Services\Receipt\ReceiptPdfService;
use App\Services\Receipt\ReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerReceiptController extends Controller
{
    public function __construct(
        private readonly ReceiptPdfService $receiptPdfService,
        private readonly ReceiptService $receiptService,
    ) {}

    public function download(Request $request, string $transactionUuid): Response
    {
        try {
            $transaction = $this->resolveOwnedTransaction($request, $transactionUuid);

            return $this->receiptPdfService->streamDonationReceipt($transaction);
        } catch (\Throwable $th) {
            if ($th instanceof HttpException) {
                throw $th;
            }

            /** @var JsonResponse $resp */
            $resp = GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\CustomerReceiptController@download');

            return $resp;
        }
    }

    public function downloadTax(Request $request, string $transactionUuid): Response
    {
        try {
            $transaction = $this->resolveOwnedTransaction($request, $transactionUuid);

            return $this->receiptPdfService->streamTaxReceipt($transaction);
        } catch (\Throwable $th) {
            if ($th instanceof HttpException) {
                throw $th;
            }

            /** @var JsonResponse $resp */
            $resp = GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\CustomerReceiptController@downloadTax');

            return $resp;
        }
    }

    private function resolveOwnedTransaction(Request $request, string $transactionUuid): Transaction
    {
        $user = $request->user();
        if ($user === null) {
            abort(401);
        }

        return $this->receiptService->resolveOwnedTransaction($user, $transactionUuid);
    }
}
