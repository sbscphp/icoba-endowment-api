<?php

namespace App\Http\Resources;

use App\Models\Pledge;
use App\Models\Transaction;
use App\Services\Pledge\PledgeScheduleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @property array{pledge: Pledge, fulfilled_amount: string, remaining_amount: string, schedule?: array<string, mixed>, summary?: array<string, mixed>, payment_summary?: array<string, mixed>, reminders?: array<string, mixed>, ledger: LengthAwarePaginator<int, Transaction>} $resource
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
        if (isset($this->resource['summary']) && is_array($this->resource['summary'])) {
            $pledge->setAttribute('schedule_summary', $this->resource['summary']);
        }

        $payload = [
            'pledge' => PledgeListResource::make($pledge)->resolve(),
            'ledger' => $paginatorArray,
        ];

        if (isset($this->resource['summary']) && is_array($this->resource['summary'])) {
            $payload['summary'] = $this->resource['summary'];
        }

        if (isset($this->resource['payment_summary'])) {
            $payload['payment_summary'] = $this->resource['payment_summary'];
        }

        if (isset($this->resource['reminders'])) {
            $payload['reminders'] = $this->resource['reminders'];
        }

        return $payload;
    }
}
