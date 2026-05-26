<?php

namespace App\Http\Resources;

use App\Models\Pledge;
use App\Models\Transaction;
use App\Services\Pledge\PledgeScheduleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @property array{pledge: Pledge, fulfilled_amount: string, remaining_amount: string, ledger: LengthAwarePaginator<int, Transaction>} $resource
 */
class PledgeDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Pledge $pledge */
        $pledge = $this->resource['pledge'];
        /** @var LengthAwarePaginator $ledger */
        $ledger = $this->resource['ledger'];

        $paginatorArray = $ledger->toArray();
        $paginatorArray['data'] = TransactionResource::collection($ledger->getCollection())->resolve();

        $pledge->setAttribute('fulfilled_amount', $this->resource['fulfilled_amount']);
        $pledge->setAttribute('remaining_amount', $this->resource['remaining_amount']);
        $pledge->setAttribute(
            'schedule_view',
            $this->resource['schedule'] ?? app(PledgeScheduleService::class)->buildForPledge($pledge)
        );

        return [
            'pledge' => PledgeListResource::make($pledge)->resolve(),
            'ledger' => $paginatorArray,
        ];
    }
}
