<?php

namespace App\Http\Controllers\v1\Customer;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\CustomerTransactionListRequest;
use App\Http\Resources\Customer\CustomerTransactionResource;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Customer\CustomerDonationDashboardService;
use Illuminate\Http\Request;

class CustomerDashboardController extends Controller
{
    public function __construct(
        private readonly CustomerDonationDashboardService $dashboardService,
    ) {}

    public function summary(Request $request)
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(401);
            }
            $campaignUuid = $request->query('campaign_uuid');
            $payload = $this->dashboardService->dashboardSummary($user, is_string($campaignUuid) ? $campaignUuid : null);

            return JsonResponser::send(false, 'Dashboard summary retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\CustomerDashboardController@summary');
        }
    }

    public function transactionHistory(CustomerTransactionListRequest $request)
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(401);
            }

            $validated = $request->validated();
            $filters = [
                'per_page' => $validated['per_page'] ?? 15,
                'campaign_uuid' => $validated['campaign_uuid'] ?? null,
                'search' => $validated['search'] ?? null,
                'filter' => $validated['filter'] ?? 'all',
            ];

            $paginator = $this->dashboardService->transactionHistory($user, $filters);

            return JsonResponser::send(false, 'Transactions retrieved.', [
                ...$paginator->toArray(),
                'data' => CustomerTransactionResource::collection($paginator->items())->resolve(),
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\CustomerDashboardController@transactionHistory');
        }
    }
}
