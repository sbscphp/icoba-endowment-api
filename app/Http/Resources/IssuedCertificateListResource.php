<?php

namespace App\Http\Resources;

use App\Models\DonorRecognition;
use App\Services\Admin\IssuedCertificate\IssuedCertificateService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DonorRecognition
 */
class IssuedCertificateListResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gateway = $this->triggerTransaction?->gateway;

        return [
            'recognition_uuid' => $this->uuid,
            'reference_id' => $this->recognition_number,
            'issue_date' => $this->issued_at,
            'awardee_name' => $this->awardee_name,
            'donor_email' => $this->donor_email,
            'initial_amount' => $this->initial_amount !== null ? (string) $this->initial_amount : null,
            'initial_currency' => $this->initial_currency,
            'cumulative_amount_ngn' => (string) $this->cumulative_amount_ngn,
            'tier' => $this->tier !== null ? [
                'uuid' => $this->tier->uuid,
                'name' => $this->tier->name,
            ] : null,
            'paid_into' => IssuedCertificateService::paidIntoLabel(is_string($gateway) ? $gateway : null),
            'gateway' => $gateway,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
        ];
    }
}
