<?php

namespace App\Http\Controllers\v1\Customer\Donation;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Donation\DonationIntentRequest;
use App\Http\Resources\TransactionResource;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Admin\Transaction\TransactionService;
use App\Services\Donation\DonationIntentService;

class DonationIntentController extends Controller
{
    public function __construct(
        private readonly DonationIntentService $donationIntentService,
        private readonly TransactionService $transactionService,
    ) {}

    public function store(DonationIntentRequest $request)
    {
        try {
            $validated = $request->validated();
            if ($request->user() instanceof User) {
                $validated['user_uuid'] = $request->user()->uuid;
            }

            $transaction = $this->donationIntentService->createPendingIntent($validated);
            $transaction = $this->transactionService->findTransaction($transaction->uuid);

            return JsonResponser::send(false, 'Donation intent created.', TransactionResource::make($transaction)->resolve(), 201);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Donation\DonationIntentController@store');
        }
    }
}
