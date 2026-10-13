<?php

namespace App\Http\Controllers\v1\Customer\Donation;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Donation\DonationFeeQuoteRequest;
use App\Responser\JsonResponser;
use App\Services\Payment\Fee\PaymentFeeQuoteService;

/**
 * Prices a donation before checkout so the frontend can show donation, service fee and total.
 * The gateway is not needed: the fee is the same whichever one the donor picks.
 */
class DonationFeeQuoteController extends Controller
{
    public function __construct(
        private readonly PaymentFeeQuoteService $feeQuoteService,
    ) {}

    public function show(DonationFeeQuoteRequest $request)
    {
        try {
            $validated = $request->validated();
            $quote = $this->feeQuoteService->quote((float) $validated['amount'], (string) $validated['currency']);

            return JsonResponser::send(false, 'Fee quote retrieved.', $quote->toArray());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\DonationFeeQuoteController@show');
        }
    }
}
