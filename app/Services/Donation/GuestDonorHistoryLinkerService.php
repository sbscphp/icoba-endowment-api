<?php

namespace App\Services\Donation;

use App\Jobs\EvaluateDonorTierRecognitionJob;
use App\Models\DonorRecognition;
use App\Models\Pledge;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class GuestDonorHistoryLinkerService
{
    /**
     * Link prior guest donations and pledges to a newly registered user by email.
     *
     * @return array{transactions: int, pledges: int, pledge_transactions: int, recognitions: int}
     */
    public function linkForUser(User $user): array
    {
        $email = strtolower(trim((string) $user->email));
        if ($email === '') {
            return [
                'transactions' => 0,
                'pledges' => 0,
                'pledge_transactions' => 0,
                'recognitions' => 0,
            ];
        }

        return DB::transaction(function () use ($user, $email): array {
            $pledgeUuids = Pledge::query()
                ->whereNull('user_uuid')
                ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$email])
                ->pluck('uuid')
                ->all();

            $pledgesLinked = 0;
            if ($pledgeUuids !== []) {
                $pledgesLinked = Pledge::query()
                    ->whereIn('uuid', $pledgeUuids)
                    ->whereNull('user_uuid')
                    ->update(['user_uuid' => $user->uuid]);
            }

            $transactionsLinked = Transaction::query()
                ->whereNull('user_uuid')
                ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$email])
                ->update(['user_uuid' => $user->uuid]);

            $pledgeTransactionsLinked = 0;
            if ($pledgeUuids !== []) {
                $pledgeTransactionsLinked = Transaction::query()
                    ->whereNull('user_uuid')
                    ->whereIn('pledge_uuid', $pledgeUuids)
                    ->update(['user_uuid' => $user->uuid]);
            }

            $recognitionsLinked = $this->linkRecognitionsForUser($user, $email);

            if ($transactionsLinked > 0) {
                $latestTransaction = Transaction::query()
                    ->countableTowardRevenue()
                    ->where('user_uuid', $user->uuid)
                    ->orderByDesc('paid_at')
                    ->first();

                if ($latestTransaction !== null) {
                    EvaluateDonorTierRecognitionJob::dispatch($latestTransaction->uuid);
                }
            }

            return [
                'transactions' => $transactionsLinked,
                'pledges' => $pledgesLinked,
                'pledge_transactions' => $pledgeTransactionsLinked,
                'recognitions' => $recognitionsLinked,
            ];
        });
    }

    private function linkRecognitionsForUser(User $user, string $email): int
    {
        $recognitions = DonorRecognition::query()
            ->whereNull('user_uuid')
            ->whereRaw('LOWER(TRIM(donor_email)) = ?', [$email])
            ->get();

        $linked = 0;

        foreach ($recognitions as $recognition) {
            $duplicate = DonorRecognition::query()
                ->where('donor_key', $user->uuid)
                ->where('tier_uuid', $recognition->tier_uuid)
                ->where('id', '!=', $recognition->id)
                ->exists();

            if ($duplicate) {
                $recognition->delete();

                continue;
            }

            $recognition->forceFill([
                'user_uuid' => $user->uuid,
                'donor_key' => $user->uuid,
            ])->save();

            $linked++;
        }

        return $linked;
    }
}
