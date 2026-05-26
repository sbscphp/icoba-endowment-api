<?php

namespace App\Services\Public;

use Illuminate\Support\Collection;

class PublicBankAccountService
{
    /**
     * @return array{bank_name: string, account_name: string, accounts: Collection<int, array<string, string>>}
     */
    public function list(): array
    {
        $accounts = collect(config('bank_accounts.accounts', []))
            ->values()
            ->map(fn (array $account): array => [
                'currency' => $account['currency'],
                'currency_symbol' => $account['currency_symbol'],
                'account_number' => $account['account_number'],
            ]);

        return [
            'bank_name' => (string) config('bank_accounts.bank_name'),
            'account_name' => (string) config('bank_accounts.account_name'),
            'accounts' => $accounts,
        ];
    }
}
