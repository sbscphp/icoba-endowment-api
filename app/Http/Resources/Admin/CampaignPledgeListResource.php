<?php

namespace App\Http\Resources\Admin;

use App\Enums\DonorTypeSlug;
use App\Models\DonorType;
use App\Models\GraduationSet;
use App\Models\Pledge;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Pledge
 */
class CampaignPledgeListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'pledge_uuid' => $this->uuid,
            'donor_name' => $this->resolveDonorName(),
            'is_anonymous' => (bool) $this->is_anonymous,
            'donor_email' => (bool) $this->is_anonymous ? null : ($this->donor_email ?? $this->donor?->email),
            'donor_phone' => (bool) $this->is_anonymous ? null : ($this->donor_phone ?? $this->donor?->phone_number),
            'donor_type' => $this->resolveDonorTypePayload(),
            'graduation_set' => $this->resolveGraduationSetPayload(),
            'committed_amount' => (string) $this->committed_amount,
            'currency' => $this->currency,
            'committed_amount_ngn' => $this->committed_amount_ngn !== null ? (string) $this->committed_amount_ngn : null,
            'fulfilled_amount' => $this->resource->getAttribute('fulfilled_amount'),
            'remaining_amount' => $this->resource->getAttribute('remaining_amount'),
            'payment_plan_type' => $this->payment_plan_type instanceof \BackedEnum ? $this->payment_plan_type->value : $this->payment_plan_type,
            'installment_count' => $this->installment_count,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }

    private function resolveDonorName(): ?string
    {
        if ((bool) $this->is_anonymous) {
            return 'Anonymous';
        }

        if ($this->donor !== null) {
            $name = trim(implode(' ', array_filter([
                (string) ($this->donor->firstname ?? ''),
                (string) ($this->donor->lastname ?? ''),
            ])));
            if ($name !== '') {
                return $name;
            }
        }

        return $this->donor_name;
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

        if ($this->donor !== null && $this->donor->relationLoaded('donorType') && $this->donor->donorType !== null) {
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

        if ($this->donor !== null && $this->donor->relationLoaded('graduationSet') && $this->donor->graduationSet !== null) {
            return $this->donor->graduationSet;
        }

        return null;
    }
}
