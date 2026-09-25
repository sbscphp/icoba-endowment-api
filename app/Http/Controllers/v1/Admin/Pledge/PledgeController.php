<?php

namespace App\Http\Controllers\v1\Admin\Pledge;

use App\Enums\AuditActionEnum;
use App\Enums\DonationPurpose;
use App\Enums\GivingIdentitySource;
use App\Enums\ModuleEnums;
use App\Enums\PledgeStatus;
use App\Enums\UserTypeEnum;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\Pledge\PledgeListRequest;
use App\Http\Requests\Admin\Pledge\PledgeReminderRequest;
use App\Http\Requests\Admin\Pledge\PledgeStatsRequest;
use App\Http\Requests\Admin\Pledge\PledgeStoreRequest;
use App\Http\Resources\PledgeDetailResource;
use App\Http\Resources\PledgeListResource;
use App\Models\Admin;
use App\Models\Pledge;
use App\Models\User;
use App\Responser\JsonResponser;
use App\Services\Admin\Pledge\PledgeService;
use App\Services\GivingIdentity\GivingIdentityResolver;
use App\Services\Pledge\PledgeCommittedNgnResolver;
use App\Services\Pledge\PledgeManualReminderService;
use App\Services\Pledge\PledgeScheduleInput;
use App\Services\Pledge\PledgeScheduleService;
use Illuminate\Http\Request;

class PledgeController extends Controller
{
    public function __construct(
        private readonly PledgeService $pledgeService,
        private readonly PledgeScheduleService $pledgeScheduleService,
        private readonly GivingIdentityResolver $givingIdentityResolver,
        private readonly PledgeManualReminderService $manualReminderService,
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
            $campaignUuid = (string) $v['campaign_uuid'];

            $fx = PledgeCommittedNgnResolver::atCapture(
                (float) $v['committed_amount'],
                (string) $v['currency']
            );

            $scheduleStorage = PledgeScheduleInput::resolveStorage($v);

            $user = null;
            if (! empty($v['user_uuid'])) {
                $user = User::query()->where('uuid', $v['user_uuid'])->first();
            }

            $identity = $this->givingIdentityResolver->resolveForPledgeData([
                'donor_email' => isset($v['donor_email']) ? strtolower(trim((string) $v['donor_email'])) : null,
                'donor_type_uuid' => $v['donor_type_uuid'] ?? null,
                'graduation_set_uuid' => $v['graduation_set_uuid'] ?? null,
                'metadata' => $scheduleStorage['metadata'] ?? null,
                'donor_name' => $v['donor_name'] ?? null,
            ], $user, GivingIdentitySource::ADMIN);

            $data = [
                'campaign_uuid' => $campaignUuid,
                'user_uuid' => $v['user_uuid'] ?? null,
                'giving_identity_uuid' => $identity?->uuid,
                'donor_type_uuid' => $v['donor_type_uuid'] ?? null,
                'graduation_set_uuid' => $v['graduation_set_uuid'] ?? null,
                'donor_name' => $v['donor_name'] ?? null,
                'donor_email' => $v['donor_email'] ?? null,
                'donor_phone' => $v['donor_phone'] ?? null,
                'is_anonymous' => (bool) ($v['is_anonymous'] ?? false),
                'purpose' => DonationPurpose::normalize($v['purpose'] ?? null),
                'committed_amount' => $v['committed_amount'],
                'currency' => (string) $v['currency'],
                'committed_amount_ngn' => $fx['committed_amount_ngn'],
                'exchange_rate_to_naira' => $fx['exchange_rate_to_naira'],
                'payment_plan_type' => $v['payment_plan_type'],
                'installment_count' => $v['installment_count'] ?? null,
                'schedule' => $scheduleStorage['schedule'],
                'status' => PledgeStatus::ACTIVE,
                'metadata' => $scheduleStorage['metadata'],
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

    /**
     * Send a payment reminder email (and in-app notification) to the pledge donor.
     */
    public function remind(PledgeReminderRequest $request, string $pledgeUuid)
    {
        try {
            $admin = $request->user();
            if (! $admin instanceof Admin) {
                abort(403, 'Forbidden.');
            }

            $v = $request->validated();
            $force = (bool) ($v['force'] ?? false);
            $pledge = $this->pledgeService->findByUuid($pledgeUuid);

            $result = $this->manualReminderService->send($pledge, $admin, [
                'schedule_item_id' => $v['schedule_item_id'] ?? null,
                'note' => $v['note'] ?? null,
                'force' => $force,
            ]);

            GeneralHelper::storeAuditLog(
                UserTypeEnum::ADMIN,
                AuditActionEnum::PLEDGE_REMINDER_SENT,
                $request,
                $admin->uuid,
                [
                    'pledge_uuid' => $pledge->uuid,
                    'recipient_email' => $result['recipient_email'],
                    'schedule_item_id' => $result['installment']['id'] ?? null,
                    'due_date' => $result['installment']['due_date'] ?? null,
                    'is_overdue' => $result['is_overdue'],
                    'forced' => $force,
                ],
                'Pledge payment reminder sent to '.$result['recipient_email'].'.',
                Pledge::class,
                $pledge->uuid,
                ModuleEnums::pledges,
                200,
            );

            return JsonResponser::send(false, 'Pledge reminder sent.', $result);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\Pledge\PledgeController@remind');
        }
    }
}
