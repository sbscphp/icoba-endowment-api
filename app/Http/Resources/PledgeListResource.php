<?php

namespace App\Http\Resources;

use App\Enums\DonationPurpose;
use App\Enums\DonorTypeSlug;
use App\Enums\House;
use App\Enums\WivesType;
use App\Models\DonorType;
use App\Models\GraduationSet;
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
        $summary = $this->resource->getAttribute('schedule_summary');
        $scheduleService = app(PledgeScheduleService::class);

        $row = [
            'pledge_uuid' => $this->uuid,
            'pledge_id' => $this->pledge_id,
            'campaign' => $this->campaign !== null ? [
                'uuid' => $this->campaign->uuid,
                'name' => $this->campaign->name,
                'campaign_id' => $this->campaign->campaign_id ?? null,
                'status' => $this->campaign->status instanceof \BackedEnum ? $this->campaign->status->value : $this->campaign->status,
                'allow_anonymous_donation' => (bool) $this->campaign->allow_anonymous_donation,
            ] : null,
            'donor' => $this->donorPayload(),
            'committed_amount' => (string) $this->committed_amount,
            'amount_pledged' => (string) $this->committed_amount,
            'amount_pledge' => (string) $this->committed_amount,
            'committed_amount_ngn' => $this->committed_amount_ngn !== null ? (string) $this->committed_amount_ngn : null,
            'exchange_rate_to_naira' => $this->exchange_rate_to_naira !== null ? (string) $this->exchange_rate_to_naira : null,
            'currency' => $this->currency,
            'payment_plan_type' => $this->payment_plan_type instanceof \BackedEnum ? $this->payment_plan_type->value : $this->payment_plan_type,
            'installment_count' => $this->installment_count,
            'transaction_count' => $this->transaction_count,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'is_paused' => $scheduleService->isPledgePaused($this->resource),
            'paused_at' => $scheduleService->pledgePausedAt($this->resource),
            'resume_date' => $scheduleService->pledgeResumeDate($this->resource),
            'fulfilled_amount' => $this->resource->getAttribute('fulfilled_amount'),
            'amount_fulfilled' => $this->resource->getAttribute('fulfilled_amount'),
            'amount_fufiled' => $this->resource->getAttribute('fulfilled_amount'),
            'remaining_amount' => $this->resource->getAttribute('remaining_amount'),
            'amount_pending' => $this->resource->getAttribute('remaining_amount'),
            // 'amount_penidng' => $this->resource->getAttribute('remaining_amount'),
            'summary' => is_array($summary) ? $summary : null,
            'schedule' => is_array($schedule) ? $schedule : null,
            'is_anonymous' => (bool) $this->is_anonymous,
            'purpose' => DonationPurpose::payload($this->purpose),
            'is_test' => (bool) $this->is_test,
            'fulfilled_at' => $this->fulfilled_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];

        if ($this->user_uuid !== null) {
            $row['user'] = $this->pledgeUserPayload();
        }

        return $row;
    }

    /**
     * Donor contact block resolved from the linked account when present, falling
     * back to the details captured on the pledge itself (guest pledges).
     *
     * @return array<string, mixed>
     */
    private function donorPayload(): array
    {
        $donor = $this->relationLoaded('donor') ? $this->donor : null;

        $name = null;
        if ($donor !== null) {
            $name = trim(implode(' ', array_filter([
                (string) ($donor->firstname ?? ''),
                (string) ($donor->lastname ?? ''),
            ])));
            $name = $name !== '' ? $name : null;
        }
        $name = $name ?? ($this->donor_name !== null && trim((string) $this->donor_name) !== '' ? $this->donor_name : null);

        return [
            'name' => $name,
            'email' => $this->donor_email ?? $donor?->email,
            'phone' => $this->donor_phone ?? $donor?->phone_number,
            'organization_name' => $donor?->organization_name ?? null,
            'is_registered' => $this->user_uuid !== null,
            'is_anonymous' => (bool) $this->is_anonymous,
            'donor_type' => $this->resolveDonorTypePayload(),
            'graduation_set' => $this->resolveGraduationSetPayload(),
            'affiliated_set' => $this->resolveAffiliatedSetPayload(),
            'house' => House::payload($donor?->house ?? $this->givingIdentity?->house),
            'is_igbobian_owned' => (bool) ($donor?->is_igbobian_owned ?? $this->givingIdentity?->is_igbobian_owned ?? false),
            'wives_type' => WivesType::payload($donor?->wives_type ?? $this->givingIdentity?->wives_type),
        ];
    }

    /**
     * Affiliated Igbobian's set (wives of ICOBA / Igbobian-owned corporates):
     * donor account first, then the giving identity.
     *
     * @return array{uuid: string, name: string|null, set_number: string|null}|null
     */
    private function resolveAffiliatedSetPayload(): ?array
    {
        $donor = $this->relationLoaded('donor') ? $this->donor : null;
        $set = $donor?->affiliatedGraduationSet ?? $this->givingIdentity?->affiliatedGraduationSet;

        if (! $set instanceof GraduationSet) {
            return null;
        }

        return [
            'uuid' => $set->uuid,
            'name' => $set->name,
            'set_number' => $set->set_number,
        ];
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

    /**
     * @return array{donor_type_id: string, slug: string, label: string}|null
     */
    private function resolveDonorTypePayload(): ?array
    {
        $type = $this->resolveDonorTypeModel();
        if ($type === null) {
            return null;
        }

        return [
            'donor_type_id' => $type->uuid,
            'slug' => (string) $type->slug,
            'label' => (string) $type->label,
        ];
    }

    private function resolveDonorTypeModel(): ?DonorType
    {
        if ($this->relationLoaded('donorType') && $this->donorType !== null) {
            return $this->donorType;
        }

        if ($this->relationLoaded('donor') && $this->donor !== null
            && $this->donor->relationLoaded('donorType') && $this->donor->donorType !== null) {
            return $this->donor->donorType;
        }

        return null;
    }

    /**
     * @return array{graduation_set_id: string, name: string, set_number: string}|null
     */
    private function resolveGraduationSetPayload(): ?array
    {
        $type = $this->resolveDonorTypeModel();
        if ($type === null || $type->slug !== DonorTypeSlug::ICOBA_ALUMNI->value) {
            return null;
        }

        $set = $this->resolveGraduationSetModel();
        if ($set === null) {
            return null;
        }

        return [
            'graduation_set_id' => $set->uuid,
            'name' => $set->name,
            'set_number' => $set->set_number,
        ];
    }

    private function resolveGraduationSetModel(): ?GraduationSet
    {
        if ($this->relationLoaded('graduationSet') && $this->graduationSet !== null) {
            return $this->graduationSet;
        }

        if ($this->relationLoaded('donor') && $this->donor !== null
            && $this->donor->relationLoaded('graduationSet') && $this->donor->graduationSet !== null) {
            return $this->donor->graduationSet;
        }

        return null;
    }
}
