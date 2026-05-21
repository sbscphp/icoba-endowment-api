<?php

namespace App\Http\Controllers\v1\Admin\Pledge;

use App\Enums\PledgeStatus;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pledge\PledgeListRequest;
use App\Http\Requests\Admin\Pledge\PledgeStatsRequest;
use App\Http\Requests\Admin\Pledge\PledgeStoreRequest;
use App\Http\Resources\PledgeDetailResource;
use App\Http\Resources\PledgeListResource;
use App\Models\Campaign;
use App\Responser\JsonResponser;
use App\Services\Admin\Pledge\PledgeService;
use App\Services\Pledge\PledgeCommittedNgnResolver;
use Illuminate\Http\Request;

class PledgeController extends Controller
{
    public function __construct(
        private readonly PledgeService $pledgeService,
    ) {}

    public function stats(PledgeStatsRequest $request)
    {
        try {
            $payload = $this->pledgeService->stats($request->validated());

            return JsonResponser::send(false, 'Pledge statistics retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Pledge\PledgeController@stats');
        }
    }

    public function index(PledgeListRequest $request)
    {
        try {
            $paginator = $this->pledgeService->list($request->validated());

            return JsonResponser::send(false, 'Pledges retrieved.', [
                ...$paginator->toArray(),
                'data' => PledgeListResource::collection($paginator->items())->resolve(),
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Pledge\PledgeController@index');
        }
    }

    public function store(PledgeStoreRequest $request)
    {
        try {
            $v = $request->validated();
            $campaignUuid = $v['campaign_uuid'] ?? Campaign::defaultCampaign()->uuid;

            $fx = PledgeCommittedNgnResolver::atCapture(
                (float) $v['committed_amount'],
                (string) $v['currency']
            );

            $data = [
                'campaign_uuid' => $campaignUuid,
                'user_uuid' => $v['user_uuid'] ?? null,
                'donor_type_uuid' => $v['donor_type_uuid'] ?? null,
                'graduation_set_uuid' => $v['graduation_set_uuid'] ?? null,
                'donor_name' => $v['donor_name'] ?? null,
                'donor_email' => $v['donor_email'] ?? null,
                'donor_phone' => $v['donor_phone'] ?? null,
                'is_anonymous' => (bool) ($v['is_anonymous'] ?? false),
                'committed_amount' => $v['committed_amount'],
                'currency' => (string) $v['currency'],
                'committed_amount_ngn' => $fx['committed_amount_ngn'],
                'exchange_rate_to_naira' => $fx['exchange_rate_to_naira'],
                'payment_plan_type' => $v['payment_plan_type'],
                'installment_count' => $v['installment_count'] ?? null,
                'schedule' => $v['schedule'] ?? null,
                'status' => PledgeStatus::ACTIVE,
                'metadata' => $v['metadata'] ?? null,
            ];

            $pledge = $this->pledgeService->createPledge($data);

            if (! empty($v['with_placeholder_transaction'])) {
                $this->pledgeService->createPlaceholderTransaction(
                    $pledge,
                    (float) $v['committed_amount']
                );
            }

            $this->pledgeService->findByUuid($pledge->uuid);

            return JsonResponser::send(false, 'Pledge created.', PledgeListResource::make($pledge->fresh(['campaign', 'donor']))->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Pledge\PledgeController@store');
        }
    }

    public function show(Request $request, string $pledgeUuid)
    {
        try {
            $perPage = max(1, min((int) $request->query('per_page', 15), 100));
            $detail = $this->pledgeService->detailWithLedger($pledgeUuid, $perPage);

            return JsonResponser::send(false, 'Pledge retrieved.', PledgeDetailResource::make($detail)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Pledge\PledgeController@show');
        }
    }
}
