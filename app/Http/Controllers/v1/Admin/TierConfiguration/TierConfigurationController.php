<?php

namespace App\Http\Controllers\v1\Admin\TierConfiguration;

use App\Enums\TierBenefit;
use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DateRangeStatsRequest;
use App\Http\Requests\Admin\Tier\CreateTierConfigurationRequest;
use App\Http\Requests\Admin\Tier\TierListRequest;
use App\Http\Requests\Admin\Tier\UpdateTierConfigurationRequest;
use App\Http\Resources\TierConfigurationListResource;
use App\Http\Resources\TierConfigurationResource;
use App\Responser\JsonResponser;
use App\Services\Admin\TierConfiguration\TierConfigurationService;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

class TierConfigurationController extends Controller
{
    public function __construct(
        private readonly TierConfigurationService $tierConfigurationService,
    ) {}

    public function stats(DateRangeStatsRequest $request)
    {
        try {
            return JsonResponser::send(false, 'Tier stats retrieved.', $this->tierConfigurationService->stats($request->validated()));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@stats');
        }
    }

    public function index(TierListRequest $request)
    {
        try {
            $paginator = $this->tierConfigurationService->list($request->validated());

            return JsonResponser::send(false, 'Tier configurations retrieved.', $this->paginatedPayload($paginator, TierConfigurationListResource::class));
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@index');
        }
    }

    public function store(CreateTierConfigurationRequest $request)
    {
        try {
            $tier = $this->tierConfigurationService->create($request->validated());

            return JsonResponser::send(false, 'Tier configuration created successfully.', TierConfigurationResource::make($tier)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@store');
        }
    }

    public function show(string $tierId)
    {
        try {
            $tier = $this->tierConfigurationService->findTier($tierId);
            $tier->loadCount('certificateTemplates as templates_count');

            return JsonResponser::send(false, 'Tier configuration retrieved.', TierConfigurationResource::make($tier)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@show');
        }
    }

    public function update(UpdateTierConfigurationRequest $request, string $tierId)
    {
        try {
            $tier = $this->tierConfigurationService->update($tierId, $request->validated());
            $tier->loadCount('certificateTemplates as templates_count');

            return JsonResponser::send(false, 'Tier configuration updated.', TierConfigurationResource::make($tier)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@update');
        }
    }

    public function toggleStatus(string $tierId)
    {
        try {
            $tier = $this->tierConfigurationService->toggleActiveStatus($tierId);
            $tier->loadCount('certificateTemplates as templates_count');
            $message = (bool) $tier->is_active ? 'Tier configuration activated.' : 'Tier configuration deactivated.';

            return JsonResponser::send(false, $message, TierConfigurationResource::make($tier)->resolve());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@toggleStatus');
        }
    }

    public function destroy(string $tierId)
    {
        try {
            $result = $this->tierConfigurationService->delete($tierId);
            $templatesCount = $result['templates_count'];
            if ($templatesCount > 0) {
                return JsonResponser::send(true, 'Tier cannot be deleted because it is linked to one or more certificate templates.', [
                    'templates_count' => $templatesCount,
                ], 422);
            }

            return JsonResponser::send(false, 'Tier configuration deleted successfully.', null);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@destroy');
        }
    }

    public function dropdown(?string $status = null)
    {
        try {
            $tiers = $this->tierConfigurationService->dropdown($status ?? 'active');
            $payload = $tiers->map(fn ($tier) => [
                'tier_id' => $tier->uuid,
                'name' => $tier->name,
                'tier_badge_url' => $tier->tier_badge_url,
                'is_active' => (bool) $tier->is_active,
            ])->values()->all();

            return JsonResponser::send(false, 'Tier dropdown retrieved.', $payload);
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@dropdown');
        }
    }

    public function benefitOptions()
    {
        try {
            return JsonResponser::send(false, 'Tier benefit options retrieved.', TierBenefit::options());
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Admin\TierConfiguration\TierConfigurationController@benefitOptions');
        }
    }

    /**
     * @param  class-string<JsonResource>  $resourceClass
     * @return array<string, mixed>
     */
    private function paginatedPayload(LengthAwarePaginator $paginator, string $resourceClass): array
    {
        $payload = $paginator->toArray();
        /** @var AnonymousResourceCollection $resource */
        $resource = $resourceClass::collection($paginator);
        $payload['data'] = $resource->resolve();

        return $payload;
    }
}
