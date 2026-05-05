<?php

namespace App\Services\Auth;

use App\Enums\OtpPurposeEnum;
use App\Exceptions\ApiException;
use App\Models\AuthChallenge;

class ChallengeTokenService
{
    public function issue(AuthChallenge $challenge, int $ttlSeconds): string
    {
        $issuedAt = now()->timestamp;

        return encrypt([
            'challenge_uuid' => $challenge->uuid,
            'subject_type' => $challenge->subject_type,
            'subject_id' => $challenge->subject_id,
            'purpose' => $challenge->purpose instanceof OtpPurposeEnum ? $challenge->purpose->value : (string) $challenge->purpose,
            'iat' => $issuedAt,
            'exp' => $issuedAt + $ttlSeconds,
        ]);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function decode(string $token, OtpPurposeEnum $expectedPurpose): array
    {
        try {
            $payload = decrypt($token);
        } catch (\Throwable) {
            throw new ApiException('Invalid or expired verification session.', 422);
        }

        if (! is_array($payload) || ($payload['purpose'] ?? null) !== $expectedPurpose->value) {
            throw new ApiException('Invalid or expired verification session.', 422);
        }

        if (now()->timestamp > (int) ($payload['exp'] ?? 0)) {
            throw new ApiException('Invalid or expired verification session.', 422);
        }

        foreach (['challenge_uuid', 'subject_type', 'subject_id'] as $key) {
            if (! is_string($payload[$key] ?? null) || $payload[$key] === '') {
                throw new ApiException('Invalid or expired verification session.', 422);
            }
        }

        return $payload;
    }
}
