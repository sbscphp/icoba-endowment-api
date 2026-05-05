<?php

namespace App\Services\Auth;

use App\Enums\OtpPurposeEnum;
use App\Exceptions\ApiException;
use App\Models\AuthChallenge;
use Illuminate\Support\Facades\Log;

class ChallengeTokenService
{
    public function issue(AuthChallenge $challenge, int $ttlSeconds): string
    {
        $issuedAt = now()->timestamp;

        return encrypt([
            'challenge_uuid' => (string) $challenge->uuid,
            'subject_type' => (string) $challenge->subject_type,
            'subject_id' => (string) $challenge->subject_id,
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
        $raw = $token;
        // Laravel encrypt() output is base64-based; form-urlencoded posts often turn '+' into
        // spaces, which breaks decrypt(). JSON bodies are unaffected.
        $token = str_replace(' ', '+', trim($token));

        $fingerprint = hash('sha256', $token);
        $requestContext = [
            'expected_purpose' => $expectedPurpose->value,
            'path' => request()?->path(),
            'token_length' => strlen($token),
            'had_leading_or_trailing_ws' => strlen($raw) !== strlen(trim($raw)),
            'inner_space_count_in_raw_token' => substr_count(trim((string) $raw), ' '),
            'token_fingerprint_sha256_12' => substr($fingerprint, 0, 12),
        ];

        try {
            $payload = decrypt($token);
        } catch (\Throwable $e) {
            $this->logDecodeFailure('decrypt_failed', array_merge($requestContext, [
                'exception' => $e::class,
                'exception_message' => $e->getMessage(),
            ]));

            throw new ApiException('Invalid or expired verification session.', 422);
        }

        if (! is_array($payload)) {
            $this->logDecodeFailure('payload_not_array', array_merge($requestContext, [
                'payload_type' => get_debug_type($payload),
            ]));

            throw new ApiException('Invalid or expired verification session.', 422);
        }

        $purpose = $payload['purpose'] ?? null;
        if ($purpose !== $expectedPurpose->value) {
            $this->logDecodeFailure('purpose_mismatch', array_merge($requestContext, [
                'decoded_purpose' => is_scalar($purpose) ? (string) $purpose : get_debug_type($purpose),
            ]));

            throw new ApiException('Invalid or expired verification session.', 422);
        }

        $exp = (int) ($payload['exp'] ?? 0);
        $nowTs = now()->timestamp;
        if ($nowTs > $exp) {
            $this->logDecodeFailure('token_expired', array_merge($requestContext, [
                'exp' => $exp,
                'now' => $nowTs,
                'skew_seconds' => $nowTs - $exp,
            ]));

            throw new ApiException('Invalid or expired verification session.', 422);
        }

        $missing = [];
        foreach (['challenge_uuid', 'subject_type', 'subject_id'] as $key) {
            $normalized = $this->coerceTokenClaim($payload[$key] ?? null);
            if ($normalized === '') {
                $missing[] = $key;

                continue;
            }
            $payload[$key] = $normalized;
        }

        if ($missing !== []) {
            $this->logDecodeFailure('missing_or_invalid_claims', array_merge($requestContext, [
                'missing_keys' => $missing,
            ]));

            throw new ApiException('Invalid or expired verification session.', 422);
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function logDecodeFailure(string $reason, array $context): void
    {
        Log::warning('Challenge token decode rejected.', array_merge(['reason' => $reason], $context));
    }

    /**
     * Encrypted payloads may restore UUID identifiers as objects (historical Ramsey UUID assignment on models).
     */
    private function coerceTokenClaim(mixed $value): string
    {
        if ($value instanceof \BackedEnum) {
            $value = $value->value;
        }

        if ($value instanceof \Stringable) {
            return (string) $value;
        }

        if (is_object($value) && method_exists($value, '__toString')) {
            return (string) $value;
        }

        return is_string($value) ? $value : '';
    }
}
