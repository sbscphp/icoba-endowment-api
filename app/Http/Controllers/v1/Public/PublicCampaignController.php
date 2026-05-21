<?php

namespace App\Http\Controllers\v1\Public;

use App\Helpers\GeneralHelper;
use App\Http\Controllers\Controller;
use App\Http\Requests\Public\PublicCampaignDropdownRequest;
use App\Http\Requests\Public\PublicCampaignListRequest;
use App\Http\Resources\PublicCampaignDropdownResource;
use App\Http\Resources\PublicCampaignListResource;
use App\Responser\JsonResponser;
use App\Services\Public\PublicCampaignService;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Resources\Json\JsonResource;

class PublicCampaignController extends Controller
{
    public function __construct(
        private readonly PublicCampaignService $publicCampaignService,
    ) {}

    public function index(PublicCampaignListRequest $request)
    {
        try {
            $paginator = $this->publicCampaignService->list($request->validated());

            return JsonResponser::send(
                false,
                'Campaigns retrieved.',
                $this->paginatedPayload($paginator, PublicCampaignListResource::class)
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicCampaignController@index');
        }
    }

    public function dropdown(PublicCampaignDropdownRequest $request)
    {
        try {
            $campaigns = $this->publicCampaignService->dropdown($request->validated());

            return JsonResponser::send(
                false,
                'Campaign dropdown retrieved.',
                PublicCampaignDropdownResource::collection($campaigns)->resolve()
            );
        } catch (\Throwable $th) {
            return GeneralHelper::handleControllerThrowable($th, 'Public\PublicCampaignController@dropdown');
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
