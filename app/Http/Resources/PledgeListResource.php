<?php

namespace App\Http\Resources;

use App\Models\Pledge;
use App\Services\Pledge\PledgeScheduleService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Pledge
 */
class PledgeListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $schedule = $this->resource->getAttribute('schedule_view');
        $scheduleService = app(PledgeScheduleService::class);

        $row = [
            'pledge_uuid' => $this->uuid,
            'campaign' => $this->campaign !== null ? [
                'uuid' => $this->campaign->uuid,
                'name' => $this->campaign->name,
            ] : null,
            'committed_amount' => (string) $this->committed_amount,
            'committed_amount_ngn' => $this->committed_amount_ngn !== null ? (string) $this->committed_amount_ngn : null,
            'exchange_rate_to_naira' => $this->exchange_rate_to_naira !== null ? (string) $this->exchange_rate_to_naira : null,
            'currency' => $this->currency,
            'payment_plan_type' => $this->payment_plan_type instanceof \BackedEnum ? $this->payment_plan_type->value : $this->payment_plan_type,
            'installment_count' => $this->installment_count,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'is_paused' => $scheduleService->isPledgePaused($this->resource),
            'paused_at' => $scheduleService->pledgePausedAt($this->resource),
            'resume_date' => $scheduleService->pledgeResumeDate($this->resource),
            'fulfilled_amount' => $this->resource->getAttribute('fulfilled_amount'),
            'remaining_amount' => $this->resource->getAttribute('remaining_amount'),
            'schedule' => is_array($schedule) ? $schedule : null,
            'is_anonymous' => (bool) $this->is_anonymous,
            'created_at' => $this->created_at,
        ];

        if ($this->user_uuid !== null) {
            $row['user'] = $this->pledgeUserPayload();
        }

        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function pledgeUserPayload(): array
    {
        if ($this->relationLoaded('donor') && $this->donor !== null) {
            return [
                'uuid' => $this->donor->uuid,
                'firstname' => $this->donor->firstname,
                'lastname' => $this->donor->lastname,
                'email' => $this->donor->email,
                'phone_number' => $this->donor->phone_number,
            ];
        }

        return ['uuid' => $this->user_uuid];
    }
}
