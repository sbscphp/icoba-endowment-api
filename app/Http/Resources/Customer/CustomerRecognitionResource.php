<?php

namespace App\Http\Resources\Customer;

use App\Models\DonorRecognition;
use App\Services\Recognition\DonorRecognitionService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin DonorRecognition
 */
class CustomerRecognitionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DonorRecognition $recognition */
        $recognition = $this->resource;

        return app(DonorRecognitionService::class)->recognitionPayload($recognition);
    }
}
