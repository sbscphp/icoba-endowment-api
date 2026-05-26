<?php

namespace Tests\Unit\Services\Bank;

use App\Services\Bank\BankAccountRegistry;
use Tests\TestCase;

class BankAccountRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('bank_accounts', [
            'bank_name' => 'First City Monument Bank',
            'account_name' => 'ICOBA Endowment Fund',
            'accounts' => [
                ['account_key' => 'fcmb_ngn', 'currency' => 'NGN', 'currency_symbol' => '₦', 'account_number' => '2007877660'],
                ['account_key' => 'fcmb_usd', 'currency' => 'USD', 'currency_symbol' => '$', 'account_number' => '2007893628'],
            ],
        ]);
    }

    public function test_resolves_by_account_number(): void
    {
        $registry = new BankAccountRegistry;

        $account = $registry->resolveByAccountNumber('2007893628');

        $this->assertNotNull($account);
        $this->assertSame('USD', $account['currency']);
        $this->assertSame('fcmb_usd', $account['account_key']);
    }

    public function test_resolves_by_currency(): void
    {
        $registry = new BankAccountRegistry;

        $account = $registry->resolveByCurrency('NGN');

        $this->assertNotNull($account);
        $this->assertSame('2007877660', $account['account_number']);
    }

    public function test_resolves_by_account_key(): void
    {
        $registry = new BankAccountRegistry;

        $account = $registry->resolveByAccountKey('fcmb_usd');

        $this->assertNotNull($account);
        $this->assertSame('USD', $account['currency']);
    }

    public function test_unknown_lookups_return_null(): void
    {
        $registry = new BankAccountRegistry;

        $this->assertNull($registry->resolveByAccountNumber('0000000000'));
        $this->assertNull($registry->resolveByCurrency('JPY'));
        $this->assertNull($registry->resolveByAccountKey('fcmb_jpy'));
    }

    public function test_paid_into_label_for_fcmb_key(): void
    {
        $registry = new BankAccountRegistry;

        $this->assertSame('ICOBA FCMB', $registry->paidIntoLabel('fcmb_ngn'));
    }
}
