<?php

namespace App\Services\Maintenance;

use App\Enums\UserTypeEnum;
use App\Models\Pledge;
use App\Models\User;
use App\Services\Pledge\PledgeBalanceService;
use App\Services\Public\PublicEndowmentStatsService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Hard-deletes a customer account together with the rows that hang off it.
 */
final class UserDeletionService
{
    public function __construct(
        private readonly PledgeBalanceService $pledgeBalance,
    ) {}

    /**
     * Delete the user and everything tied to the account, soft-deleted rows included: pledges, transactions
     * (plus every payment made against the user's pledges), receipts, tier recognitions, OTPs, auth challenges,
     * API tokens, sessions, password reset tokens, notifications and the giving identity.
     *
     * The giving identity is kept (unlinked) when donations that are not being deleted still point at it.
     * Pledges of other donors that lose a payment get their status recomputed. Audit logs are left untouched.
     *
     * @return array{transactions: int, receipts: int, recognitions: int, pledges: int, other_pledges_refreshed: int, giving_identities: int, giving_identities_kept: int, otps: int, auth_challenges: int, tokens: int, sessions: int, password_reset_tokens: int, notifications: int}
     */
    public function delete(User $user, bool $dryRun): array
    {
        $pledges = fn (): QueryBuilder => DB::table('pledges')->where('user_uuid', $user->uuid);
        $pledgeUuids = fn (): QueryBuilder => $pledges()->select('uuid');

        // A deleted pledge takes all of its payments with it, whoever made them.
        $transactions = fn (): QueryBuilder => DB::table('transactions')
            ->where(fn (QueryBuilder $q) => $q
                ->where('user_uuid', $user->uuid)
                ->orWhereIn('pledge_uuid', $pledgeUuids()));
        $transactionUuids = fn (): QueryBuilder => $transactions()->select('uuid');

        $receipts = fn (): QueryBuilder => DB::table('transaction_receipts')
            ->whereIn('transaction_uuid', $transactionUuids());

        $recognitions = fn (): QueryBuilder => DB::table('donor_recognitions')
            ->where(fn (QueryBuilder $q) => $q
                ->where('user_uuid', $user->uuid)
                ->orWhereIn('trigger_transaction_uuid', $transactionUuids()));

        $otherPledgeUuids = $transactions()
            ->whereNotNull('pledge_uuid')
            ->whereNotIn('pledge_uuid', $pledgeUuids())
            ->distinct()
            ->pluck('pledge_uuid')
            ->all();

        $identities = fn (): QueryBuilder => DB::table('giving_identities')->where('user_uuid', $user->uuid);
        $identitiesInUse = fn (): QueryBuilder => $identities()
            ->where(fn (QueryBuilder $q) => $q
                ->whereExists(fn (QueryBuilder $t) => $t
                    ->selectRaw('1')
                    ->from('transactions')
                    ->whereColumn('transactions.giving_identity_uuid', 'giving_identities.uuid')
                    ->whereNotIn('transactions.uuid', $transactionUuids()))
                ->orWhereExists(fn (QueryBuilder $p) => $p
                    ->selectRaw('1')
                    ->from('pledges')
                    ->whereColumn('pledges.giving_identity_uuid', 'giving_identities.uuid')
                    ->whereNotIn('pledges.uuid', $pledgeUuids())));
        $keptIdentityUuids = $identitiesInUse()->pluck('uuid')->all();

        $otps = fn (): QueryBuilder => DB::table('one_time_passwords')->where('user_id', $user->id);
        $challenges = fn (): QueryBuilder => DB::table('auth_challenges')
            ->where('subject_type', UserTypeEnum::CUSTOMER->value)
            ->where('subject_id', $user->uuid);
        $sessions = fn (): QueryBuilder => DB::table('sessions')->where('user_id', $user->id);
        $resetTokens = fn (): QueryBuilder => DB::table('password_reset_tokens')->where('email', $user->email);

        $result = [
            'transactions' => $transactions()->count(),
            'receipts' => $receipts()->count(),
            'recognitions' => $recognitions()->count(),
            'pledges' => $pledges()->count(),
            'other_pledges_refreshed' => count($otherPledgeUuids),
            'giving_identities' => $identities()->count() - count($keptIdentityUuids),
            'giving_identities_kept' => count($keptIdentityUuids),
            'otps' => $otps()->count(),
            'auth_challenges' => $challenges()->count(),
            'tokens' => $user->tokens()->count(),
            'sessions' => $sessions()->count(),
            'password_reset_tokens' => $resetTokens()->count(),
            'notifications' => $user->notifications()->count(),
        ];

        if ($dryRun) {
            return $result;
        }

        DB::transaction(function () use ($user, $transactions, $pledges, $receipts, $recognitions, $identities, $keptIdentityUuids, $otherPledgeUuids, $otps, $challenges, $sessions, $resetTokens): void {
            $recognitions()->delete();
            // Explicit even though the FK cascades, so the delete does not depend on FK enforcement.
            $receipts()->delete();
            // Transactions before pledges: the transaction filter reads the user's pledges.
            $transactions()->delete();
            $pledges()->delete();
            $identities()->whereNotIn('uuid', $keptIdentityUuids)->delete();

            $otps()->delete();
            $challenges()->delete();
            $sessions()->delete();
            $resetTokens()->delete();
            $user->tokens()->delete();
            $user->notifications()->delete();

            // Eloquent delete so the roles/permissions pivot rows are detached.
            $user->delete();

            foreach (array_chunk($otherPledgeUuids, 200) as $chunk) {
                Pledge::query()->whereIn('uuid', $chunk)->get()
                    ->each(fn (Pledge $pledge) => $this->pledgeBalance->refreshPledgeStatus($pledge));
            }
        });

        PublicEndowmentStatsService::forgetCache();

        return $result;
    }
}
