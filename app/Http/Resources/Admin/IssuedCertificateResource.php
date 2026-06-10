<?php

namespace App\Http\Resources;

use App\Models\DonorRecognition;
use App\Services\Admin\IssuedCertificate\IssuedCertificateService;
use App\Services\Recognition\DonorRecognitionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DonorRecognition
 */
class IssuedCertificateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $gateway = $this->triggerTransaction?->gateway;
        $recognitionPayload = app(DonorRecognitionService::class)->recognitionPayload($this->resource);

        return [
            ...$recognitionPayload,
            'status' => $this->status instanceof \BackedEnum ? $this->status->value : $this->status,
            'donor_email' => $this->donor_email,
            'paid_into' => IssuedCertificateService::paidIntoLabel(is_string($gateway) ? $gateway : null),
            'gateway' => $gateway,
            'trigger_transaction' => $this->triggerTransaction !== null ? [
                'transaction_uuid' => $this->triggerTransaction->uuid,
                'transaction_id' => $this->triggerTransaction->transaction_id,
                'amount' => (string) $this->triggerTransaction->amount,
                'currency' => $this->triggerTransaction->currency,
                'amount_in_naira' => $this->triggerTransaction->amount_in_naira !== null
                    ? (string) $this->triggerTransaction->amount_in_naira
                    : null,
                'gateway' => $this->triggerTransaction->gateway,
                'gateway_reference' => $this->triggerTransaction->gateway_reference,
                'paid_at' => $this->triggerTransaction->paid_at,
                'status' => $this->triggerTransaction->status instanceof \BackedEnum
                    ? $this->triggerTransaction->status->value
                    : $this->triggerTransaction->status,
            ] : null,
            'user' => $this->user !== null ? [
                'uuid' => $this->user->uuid,
                'firstname' => $this->user->firstname,
                'lastname' => $this->user->lastname,
                'email' => $this->user->email,
            ] : null,
            'email_sent_at' => $this->email_sent_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
