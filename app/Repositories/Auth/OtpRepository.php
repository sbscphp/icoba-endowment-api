<?php


namespace App\Repositories\Auth;

use App\Models\OneTimePassword;
use App\Models\User;
use App\Repositories\Contracts\Auth\OtpRepositoryInterface;
use App\Repositories\Contracts\User\UserRepositoryInterface;
use Illuminate\Database\Eloquent\Collection;

class OtpRepository implements OtpRepositoryInterface
{
    public function create(array $data): OneTimePassword
    {
        return OneTimePassword::create($data);
    }

    public function latestValid(int $userId, string $purpose = 'login'): ?OneTimePassword
    {
        return OneTimePassword::where('user_id', $userId)
            ->where('purpose', $purpose)
            ->where('used', false)
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->first();
    }

    public function incrementAttempts(int $otpId): void
    {
        OneTimePassword::where('id', $otpId)->increment('attempts');
    }

    public function markUsed(int $otpId): void
    {
        OneTimePassword::where('id', $otpId)->update(['used' => true, 'used_at' => now()]);
    }

    public function invalidateAll(int $userId, string $purpose): void
    {
        OneTimePassword::where('user_id', $userId)
            ->where('purpose', $purpose)
            ->whereNull('used_at')
            ->update(['used_at' => now()]);
    }

    public function findByUuid(string $challengeId): ?OneTimePassword
    {
        return OneTimePassword::where('uuid', $challengeId)->first();
    }

    public function invalidateById(int $id): void
    {
        OneTimePassword::whereKey($id)->update([
            'used_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function countRecentResends(int $userId, string $purpose, int $minutes): int
    {
        return OneTimePassword::query()
            ->where('user_id', $userId)
            ->where('purpose', $purpose)
            ->where('created_at', '>=', now()->subMinutes($minutes))
            ->count();
    }
}
