<?php

namespace App\Http\Controllers\v1\Customer\Pledge;

use App\Enums\PledgePaymentPreference;
use App\Enums\PledgeStatus;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Customer\Pledge\PledgeListRequest;
use App\Http\Requests\Customer\Pledge\PledgeStatsRequest;
use App\Http\Requests\Customer\Pledge\PledgeStoreRequest;
use App\Http\Requests\Customer\Pledge\UpdatePledgePauseRequest;
use App\Http\Requests\Customer\Pledge\UpdatePledgeScheduleRequest;
use App\Http\Resources\PledgeDetailResource;
use App\Http\Resources\PledgeListResource;
use App\Models\Campaign;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Admin\Pledge\PledgeService;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Pledge\PledgeCommittedNgnResolver;
use App\Services\Pledge\PledgeScheduleService;
use Illuminate\Http\Request;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class CustomerPledgeController extends Controller
{
    public function __construct(
        private readonly PledgeService $pledgeService,
        private readonly PledgeScheduleService $pledgeScheduleService,
        private readonly PledgeBalanceService $pledgeBalanceService,
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
            $items = collect($paginator->items());
            $schedules = $this->pledgeScheduleService->buildForPledges($items);

            $data = $items->map(function ($pledge) use ($schedules) {
                $pledge->setAttribute('schedule_view', $schedules[$pledge->uuid] ?? null);
                $pledge->setAttribute('fulfilled_amount', $this->pledgeBalanceService->fulfilledAmount($pledge));
                $pledge->setAttribute('remaining_amount', $this->pledgeBalanceService->remainingAmount($pledge));

                return PledgeListResource::make($pledge)->resolve();
            })->values()->all();

            return JsonResponser::send(false, 'Pledges retrieved.', [
                ...$paginator->toArray(),
                'data' => $data,
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
            $pledge = $this->pledgeScheduleService->persistDefaultScheduleIfMissing($pledge);

            if (! empty($v['with_placeholder_transaction'])) {
                $this->pledgeService->createPlaceholderTransaction(
                    $pledge,
                    (float) $v['committed_amount']
                );
            }

            $this->pledgeService->findByUuid($pledge->uuid);

            $pledge = $pledge->fresh(['campaign', 'donor']);
            $pledge->setAttribute('schedule_view', $this->pledgeScheduleService->buildForPledge($pledge));

            return JsonResponser::send(false, 'Pledge created.', PledgeListResource::make($pledge)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Pledge\CustomerPledgeController@store');
        }
    }

    public function updatePause(UpdatePledgePauseRequest $request, string $pledgeUuid)
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(401);
            }

            $pledge = $this->pledgeService->findByUuid($pledgeUuid);
            if ($pledge->user_uuid !== $user->uuid) {
                throw (new ModelNotFoundException)->setModel($pledge::class, [$pledgeUuid]);
            }

            $action = (string) $request->validated('action');
            $pledge = $action === 'pause'
                ? $this->pledgeScheduleService->pausePledge($pledge)
                : $this->pledgeScheduleService->resumePledge($pledge);

            $pledge->load(['campaign:uuid,name,campaign_id', 'donor:uuid,firstname,lastname,email,phone_number']);
            $pledge->setAttribute('schedule_view', $this->pledgeScheduleService->buildForPledge($pledge));

            $message = $action === 'pause' ? 'Pledge paused.' : 'Pledge resumed.';

            return JsonResponser::send(false, $message, PledgeListResource::make($pledge)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Pledge\CustomerPledgeController@updatePause');
        }
    }

    public function updateSchedule(UpdatePledgeScheduleRequest $request, string $pledgeUuid)
    {
        try {
            $user = $request->user();
            if (! $user instanceof User) {
                abort(401);
            }

            $pledge = $this->pledgeService->findByUuid($pledgeUuid);
            if ($pledge->user_uuid !== $user->uuid) {
                throw (new ModelNotFoundException)->setModel($pledge::class, [$pledgeUuid]);
            }

            $pref = PledgePaymentPreference::from((string) $request->validated('payment_preference'));
            $pledge = $this->pledgeScheduleService->setPaymentPreference($pledge, $pref);

            $pledge->load(['campaign:uuid,name,campaign_id', 'donor:uuid,firstname,lastname,email,phone_number']);
            $pledge->setAttribute('schedule_view', $this->pledgeScheduleService->buildForPledge($pledge));

            return JsonResponser::send(false, 'Pledge payment preference updated.', PledgeListResource::make($pledge)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Customer\Pledge\CustomerPledgeController@updateSchedule');
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
