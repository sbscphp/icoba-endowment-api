<?php

namespace App\Services\Bank;

class BankAccountRegistry
{
    /**
     * @return array{
     *     bank_name: string,
     *     account_name: string,
     *     accounts: array<int, array{
     *         account_key: ?string,
     *         currency: string,
     *         currency_symbol: string,
     *         account_number: string,
     *     }>
     * }
     */
    public function list(): array
    {
        return [
            'bank_name' => (string) config('bank_accounts.bank_name'),
            'account_name' => (string) config('bank_accounts.account_name'),
            'accounts' => $this->accounts(),
        ];
    }

    /**
     * @return array<int, array{
     *     account_key: ?string,
     *     currency: string,
     *     currency_symbol: string,
     *     account_number: string,
     * }>
     */
    public function accounts(): array
    {
        $raw = config('bank_accounts.accounts', []);

        return collect(is_array($raw) ? $raw : [])
            ->values()
            ->map(fn (array $account): array => [
                'account_key' => isset($account['account_key']) ? (string) $account['account_key'] : null,
                'currency' => strtoupper((string) ($account['currency'] ?? '')),
                'currency_symbol' => (string) ($account['currency_symbol'] ?? ''),
                'account_number' => (string) ($account['account_number'] ?? ''),
            ])
            ->all();
    }

    /**
     * Admin-facing dropdown rows (includes paid_into label).
     *
     * @return array<int, array{
     *     account_key: ?string,
     *     currency: string,
     *     currency_symbol: string,
     *     account_number: string,
     *     paid_into: string,
     * }>
     */
    public function accountsForAdmin(): array
    {
        return array_map(function (array $account): array {
            return array_merge($account, [
                'paid_into' => $this->paidIntoLabel($account['account_key']),
            ]);
        }, $this->accounts());
    }

    /**
     * @return array{
     *     account_key: ?string,
     *     currency: string,
     *     currency_symbol: string,
     *     account_number: string,
     * }|null
     */
    public function resolveByAccountNumber(?string $accountNumber): ?array
    {
        $accountNumber = trim((string) $accountNumber);
        if ($accountNumber === '') {
            return null;
        }

        foreach ($this->accounts() as $account) {
            if ($account['account_number'] === $accountNumber) {
                return $account;
            }
        }

        return null;
    }

    /**
     * @return array{
     *     account_key: ?string,
     *     currency: string,
     *     currency_symbol: string,
     *     account_number: string,
     * }|null
     */
    public function resolveByAccountKey(?string $accountKey): ?array
    {
        $accountKey = trim((string) $accountKey);
        if ($accountKey === '') {
            return null;
        }

        foreach ($this->accounts() as $account) {
            if ($account['account_key'] === $accountKey) {
                return $account;
            }
        }

        return null;
    }

    /**
     * Default account for a given donation currency.
     *
     * @return array{
     *     account_key: ?string,
     *     currency: string,
     *     currency_symbol: string,
     *     account_number: string,
     * }|null
     */
    public function resolveByCurrency(?string $currency): ?array
    {
        $currency = strtoupper(trim((string) $currency));
        if ($currency === '') {
            return null;
        }

        foreach ($this->accounts() as $account) {
            if ($account['currency'] === $currency) {
                return $account;
            }
        }

        return null;
    }

    public function paidIntoLabel(?string $accountKey): string
    {
        $bankName = (string) config('bank_accounts.bank_name', 'First City Monument Bank');
        $shortLabel = $this->bankShortLabel($bankName);

        if ($accountKey === null || trim($accountKey) === '') {
            return 'ICOBA '.$shortLabel;
        }

        $key = strtolower(trim($accountKey));
        if (str_starts_with($key, 'fcmb')) {
            return 'ICOBA FCMB';
        }

        return 'ICOBA '.strtoupper(str_replace('_', ' ', $key));
    }

    /**
     * @return list<string>
     */
    public function accountNumbers(): array
    {
        return array_values(array_filter(array_map(
            fn (array $account): string => $account['account_number'],
            $this->accounts()
        )));
    }

    /**
     * @return list<string>
     */
    public function accountKeys(): array
    {
        return array_values(array_filter(array_map(
            fn (array $account): ?string => $account['account_key'],
            $this->accounts()
        )));
    }

    private function bankShortLabel(string $bankName): string
    {
        $lower = strtolower($bankName);
        if (str_contains($lower, 'first city')) {
            return 'FCMB';
        }

        return $bankName;
    }
}
