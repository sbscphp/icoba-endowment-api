<?php

namespace App\Http\Controllers\v1\Customer\Pledge;

use App\Enums\PledgeStatus;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Pledge\PledgeListRequest;
use App\Http\Requests\Customer\Pledge\PledgeStatsRequest;
use App\Http\Requests\Customer\Pledge\PledgeStoreRequest;
use App\Http\Resources\PledgeDetailResource;
use App\Http\Resources\PledgeListResource;
use App\Models\Campaign;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Admin\Pledge\PledgeService;
use App\Services\Pledge\PledgeCommittedNgnResolver;
use Illuminate\Http\Request;

class CustomerPledgeController extends Controller
{
    public function __construct(
        private readonly PledgeService $pledgeService,
    ) {}

    public function stats(PledgeStatsRequest $request)
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(401);
            }

            $payload = $this->pledgeService->statsForUser($user->uuid, $request->validated());

            return JsonResponser::send(false, 'Pledge statistics retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Pledge\CustomerPledgeController@stats');
        }
    }

    public function index(PledgeListRequest $request)
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(401);
            }

            $paginator = $this->pledgeService->listForUser($user->uuid, $request->validated());

            return JsonResponser::send(false, 'Pledges retrieved.', [
                ...$paginator->toArray(),
                'data' => PledgeListResource::collection($paginator->items())->resolve(),
            ]);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Pledge\CustomerPledgeController@index');
        }
    }

    public function store(PledgeStoreRequest $request)
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(401);
            }

            $v = $request->validated();
            $campaignUuid = $v['campaign_uuid'] ?? Campaign::defaultCampaign()->uuid;

            $displayName = isset($v['donor_name']) && is_string($v['donor_name']) && trim($v['donor_name']) !== ''
                ? $v['donor_name']
                : trim((string) (($user->firstname ?? '').' '.($user->lastname ?? '')));

            $fx = PledgeCommittedNgnResolver::atCapture(
                (float) $v['committed_amount'],
                (string) $v['currency']
            );

            $data = [
                'campaign_uuid' => $campaignUuid,
                'user_uuid' => $user->uuid,
                'donor_type_uuid' => $v['donor_type_uuid'] ?? $user->donor_type_uuid,
                'graduation_set_uuid' => $v['graduation_set_uuid'] ?? $user->graduation_set_uuid,
                'donor_name' => $displayName !== '' ? $displayName : null,
                'donor_email' => $v['donor_email'] ?? $user->email,
                'donor_phone' => $v['donor_phone'] ?? $user->phone_number,
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
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Pledge\CustomerPledgeController@store');
        }
    }

    public function show(Request $request, string $pledgeUuid)
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(401);
            }

            $perPage = max(1, min((int) $request->query('per_page', 15), 100));
            $detail = $this->pledgeService->detailWithLedgerForUser($user->uuid, $pledgeUuid, $perPage);

            return JsonResponser::send(false, 'Pledge retrieved.', PledgeDetailResource::make($detail)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Pledge\CustomerPledgeController@show');
        }
    }
}
