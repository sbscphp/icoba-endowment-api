<?php

namespace App\Http\Resources\Admin;

use App\Enums\DonationPurpose;
use App\Enums\House;
use App\Enums\WivesType;
use App\Models\DonorType;
use App\Models\GraduationSet;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Transaction
 */
class TransactionListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'transaction_uuid' => $this->uuid,
            'transaction_id' => $this->transaction_id,
            'donor_name' => $this->resolveDonorName(),
            'donor_email' => $this->donor_email ?? $this->donor?->email,
            'donor_type' => $this->donorTypePayload(),
            'set' => $this->setPayload($this->donor?->graduationSet ?? $this->pledge?->graduationSet),
            'affiliated_set' => $this->setPayload($this->donor?->affiliatedGraduationSet ?? $this->givingIdentity?->affiliatedGraduationSet),
            'house' => House::payload($this->donor?->house ?? $this->givingIdentity?->house),
            'is_igbobian_owned' => (bool) ($this->donor?->is_igbobian_owned ?? $this->givingIdentity?->is_igbobian_owned ?? false),
            'wives_type' => WivesType::payload($this->donor?->wives_type ?? $this->givingIdentity?->wives_type),
            'is_anonymous' => (bool) $this->is_anonymous,
            'purpose' => DonationPurpose::payload($this->purpose),
            'is_test' => (bool) $this->is_test,
            'linked_campaign' => $this->campaign !== null ? [
                'campaign_id' => $this->campaign->uuid,
                'public_campaign_code' => $this->campaign->campaign_id,
                'name' => $this->campaign->name,
            ] : null,
            'amount' => (string) $this->amount,
            'currency' => $this->currency,
            'amount_in_naira' => $this->amount_in_naira !== null ? (string) $this->amount_in_naira : null,
            'gateway' => $this->gateway,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'paid_at' => $this->paid_at,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * @return array{uuid: string, name: string|null, set_number: string|null}|null
     */
    private function setPayload(?GraduationSet $set): ?array
    {
        if ($set === null) {
            return null;
        }

        return [
            'uuid' => $set->uuid,
            'name' => $set->name,
            'set_number' => $set->set_number,
        ];
    }

    /**
     * @return array{slug: string, label: string}|null
     */
    private function donorTypePayload(): ?array
    {
        $type = $this->donorType ?? $this->donor?->donorType;
        if (! $type instanceof DonorType) {
            return null;
        }

        return [
            'slug' => (string) $type->slug,
            'label' => (string) $type->label,
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
}
