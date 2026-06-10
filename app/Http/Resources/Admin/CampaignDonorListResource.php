<?php

namespace App\Http\Resources\Admin;

use App\Enums\DonorTypeSlug;
use App\Models\DonorType;
use App\Models\GraduationSet;
use App\Models\Transaction;
use App\Services\Tier\TierResolutionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class CampaignDonorListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tier = app(TierResolutionService::class)->resolveTierForAmount(
            $this->amount_in_naira !== null ? (float) $this->amount_in_naira : null
        );

        return [
            'transaction_id' => $this->transaction_id,
            'donor_name' => $this->resolveDonorName(),
            'is_anonymous' => (bool) $this->is_anonymous,
            'donation_value' => (string) $this->amount,
            'donation_currency' => $this->currency,
            'transaction_date' => $this->paid_at ?? $this->created_at,
            'donor_type' => $this->resolveDonorTypePayload(),
            'graduation_set' => $this->resolveGraduationSetPayload(),
            'transaction_status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'donor_tier' => $tier !== null ? [
                'tier_id' => $tier->uuid,
                'name' => $tier->name,
            ] : null,
            'payment_gateway' => $this->gateway,
            'amount_in_naira' => $this->amount_in_naira !== null ? (string) $this->amount_in_naira : null,
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
        if ($this->donor !== null && $this->donor->relationLoaded('graduationSet') && $this->donor->graduationSet !== null) {
            return $this->donor->graduationSet;
        }

        if ($this->pledge !== null && $this->pledge->relationLoaded('graduationSet') && $this->pledge->graduationSet !== null) {
            return $this->pledge->graduationSet;
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $guestProfile = is_array($metadata['guest_donor_profile'] ?? null) ? $metadata['guest_donor_profile'] : [];
        $setUuid = $guestProfile['graduation_set_uuid'] ?? null;
        $setName = $guestProfile['graduation_set_name'] ?? null;
        if (is_string($setUuid) && $setUuid !== '') {
            return new GraduationSet([
                'uuid' => $setUuid,
                'name' => is_string($setName) ? $setName : '',
                'set_number' => (string) ($guestProfile['set_number'] ?? ''),
            ]);
        }

        return null;
    }
}
