<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use InvalidArgumentException;

/**
 * Wraps the encrypted envelope exchanged with API clients.
 *
 * Inbound shape:  {"payload": "<base64-ciphertext>"}
 * Outbound shape: {"response": "<base64-ciphertext>"}  [optional "preview" in non-production when enabled]
 */
class EncryptedPayloadResource extends JsonResource
{
    private function __construct(
        public readonly string $payload,
    ) {
        parent::__construct($payload);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromRequestData(array $data): self
    {
        $payload = $data['payload'] ?? null;

        if (! is_string($payload) || trim($payload) === '') {
            throw new InvalidArgumentException(
                'Request envelope must contain a non-empty string "payload" field.'
            );
        }

        return new self($payload);
    }

    public static function forResponse(string $encryptedPayload): self
    {
        return new self($encryptedPayload);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public static function isEncryptedEnvelope(array $data): bool
    {
        $payload = $data['payload'] ?? null;

        return is_string($payload) && trim($payload) !== '';
    }

    /**
     * @return array<string, string>
     */
    public function toArray(Request $request): array
    {
        return $this->toResponseArray();
    }

    /**
     * @return array<string, string>
     */
    public function toResponseArray(): array
    {
        return ['response' => $this->payload];
    }
}
