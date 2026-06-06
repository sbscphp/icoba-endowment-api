<?php

namespace App\Services\Donation;

use App\Models\Transaction;
use App\Models\User;
use App\Services\Receipt\ReceiptService;
use Illuminate\Support\Facades\DB;

final class DonorCumulativeTotalService
{
    public const DONOR_KEY_SQL = <<<'SQL'
CASE
  WHEN transactions.giving_identity_uuid IS NOT NULL THEN transactions.giving_identity_uuid
  WHEN transactions.user_uuid IS NOT NULL THEN transactions.user_uuid
  WHEN transactions.donor_email IS NOT NULL AND transactions.donor_email != '' THEN LOWER(TRIM(transactions.donor_email))
  ELSE transactions.uuid
END
SQL;

    public function __construct(
        private readonly ReceiptService $receiptService,
    ) {}

    public function effectiveAmountNgnSql(string $tablePrefix = 'transactions'): string
    {
        $prefix = rtrim($tablePrefix, '.').'.';

        return 'COALESCE('.$prefix.'amount_in_naira, CASE WHEN UPPER(TRIM('.$prefix.'currency)) = \'NGN\' THEN '.$prefix.'amount END)';
    }

    /**
     * @return array{
     *     donor_key: string,
     *     user_uuid: ?string,
     *     donor_email: ?string,
     *     awardee_name: ?string,
     *     is_anonymous: bool
     * }
     */
    public function resolveContextFromTransaction(Transaction $transaction): array
    {
        $transaction->loadMissing('donor:uuid,firstname,lastname,email,organization_name');

        return [
            'donor_key' => $this->resolveDonorKeyFromTransaction($transaction),
            'user_uuid' => $transaction->user_uuid,
            'donor_email' => $this->normalizeEmail($transaction->donor_email ?? $transaction->donor?->email),
            'awardee_name' => $this->receiptService->donorDisplayLine($transaction),
            'is_anonymous' => (bool) $transaction->is_anonymous,
        ];
    }

    public function resolveDonorKeyFromTransaction(Transaction $transaction): string
    {
        if ($transaction->giving_identity_uuid !== null && $transaction->giving_identity_uuid !== '') {
            return (string) $transaction->giving_identity_uuid;
        }

        if ($transaction->user_uuid !== null && $transaction->user_uuid !== '') {
            return (string) $transaction->user_uuid;
        }

        $email = $this->normalizeEmail($transaction->donor_email);
        if ($email !== null) {
            return $email;
        }

        return (string) $transaction->uuid;
    }

    public function resolveDonorKeyForUser(User $user): string
    {
        return (string) $user->uuid;
    }

    public function cumulativeTotalNgnForDonorKey(string $donorKey): float
    {
        $donorKey = trim($donorKey);
        if ($donorKey === '') {
            return 0.0;
        }

        $effectiveNgnSql = $this->effectiveAmountNgnSql('transactions');
        $keySql = self::DONOR_KEY_SQL;

        $row = DB::query()
            ->fromSub(
                Transaction::query()
                    ->countableTowardRevenue()
                    ->selectRaw('('.$keySql.') as donor_key')
                    ->selectRaw('('.$effectiveNgnSql.') as effective_amount_ngn'),
                'donor_transactions'
            )
            ->where('donor_key', $donorKey)
            ->selectRaw('COALESCE(SUM(effective_amount_ngn), 0) as total_amount_ngn')
            ->first();

        return (float) ($row->total_amount_ngn ?? 0);
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = strtolower(trim($email));

        return $normalized !== '' ? $normalized : null;
    }
}
