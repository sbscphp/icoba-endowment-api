<?php

namespace Tests\Unit\Services\Payment;

use App\Services\Payment\PaystackCheckoutTransaction;
use PHPUnit\Framework\TestCase;

final class PaystackCheckoutTransactionTest extends TestCase
{
    public function test_converts_minor_units_to_major_for_amount_and_fees(): void
    {
        $dto = PaystackCheckoutTransaction::fromPaystackData([
            'id' => 12345,
            'reference' => 'icoba_abc',
            'status' => 'success',
            'currency' => 'NGN',
            'amount' => 1045300,
            'fees' => 25680,
            'metadata' => ['transaction_uuid' => 'uuid-1'],
        ]);

        $this->assertSame('uuid-1', $dto->transactionUuid);
        $this->assertSame(10453.0, $dto->amountReceived('NGN'));
        $this->assertSame(256.8, $dto->gatewayFee('NGN'));
    }

    public function test_missing_amount_fields_yield_null(): void
    {
        $dto = PaystackCheckoutTransaction::fromPaystackData([
            'reference' => 'icoba_abc',
            'status' => 'failed',
        ]);

        $this->assertNull($dto->amountReceived('NGN'));
        $this->assertNull($dto->gatewayFee('NGN'));
    }
}
