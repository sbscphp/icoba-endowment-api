<?php

namespace App\Repositories\Contracts\Auth;

use App\Models\OneTimePassword;

interface OtpRepositoryInterface
{
    public function create(array $data): OneTimePassword;

    public function latestValid(int $userId, string $purpose = 'login'): ?OneTimePassword;

    public function incrementAttempts(int $otpId): void;

    public function markUsed(int $otpId): void;

    public function findByUuid(string $challengeId): ?OneTimePassword;

    public function invalidateAll(int $userId, string $purpose): void;

    public function invalidateById(int $id): void;

    public function countRecentResends(int $userId, string $purpose, int $minutes): int;
}
