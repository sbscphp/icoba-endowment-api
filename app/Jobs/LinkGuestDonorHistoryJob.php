<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Donation\GuestDonorHistoryLinkerService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class LinkGuestDonorHistoryJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly string $userUuid,
    ) {}

    public function uniqueId(): string
    {
        return 'link-guest-history:'.$this->userUuid;
    }

    public function handle(GuestDonorHistoryLinkerService $linker): void
    {
        $user = User::query()->where('uuid', $this->userUuid)->first();
        if ($user === null) {
            return;
        }

        try {
            $result = $linker->linkForUser($user);

            if ($result['transactions'] > 0 || $result['pledges'] > 0 || $result['pledge_transactions'] > 0 || $result['recognitions'] > 0) {
                Log::info('Linked guest donor history to new user.', [
                    'user_uuid' => $this->userUuid,
                    'email' => $user->email,
                    ...$result,
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('Failed to link guest donor history: '.$e->getMessage(), [
                'user_uuid' => $this->userUuid,
                'email' => $user->email,
            ]);

            throw $e;
        }
    }
}
