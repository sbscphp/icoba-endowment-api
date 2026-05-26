<?php

namespace App\Services\Public;

use App\Services\Bank\BankAccountRegistry;

class PublicBankAccountService
{
    public function __construct(
        private readonly BankAccountRegistry $registry,
    ) {}

    /**
     * @return array{bank_name: string, account_name: string, accounts: array<int, array<string, mixed>>}
     */
    public function list(): array
    {
        return $this->registry->list();
    }
}
