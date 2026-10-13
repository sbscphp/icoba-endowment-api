<?php

namespace Tests\Unit\Services\Payment;

use App\Services\Payment\FcmbCheckoutTransaction;
use PHPUnit\Framework\TestCase;

final class FcmbCheckoutTransactionTest extends TestCase
{
    public function test_separates_what_we_asked_for_from_what_clnx_collected(): void
    {
        $dto = FcmbCheckoutTransaction::fromClnxData([
            'invoiceRequestReference' => 'inv-1',
            'reference' => 'GAT-1',
            'status' => 'SUCCESS',
            'originalAmount' => 100,
            'amount' => 109.5,
            'customFields' => [
                ['label' => 'transaction_uuid', 'value' => 'uuid-1'],
                ['label' => 'donation_amount', 'value' => '98.04'],
            ],
        ]);

        $this->assertSame('uuid-1', $dto->transactionUuid);
        $this->assertSame(100.0, $dto->chargedAmount());
        $this->assertSame(109.5, $dto->amountReceived());
        $this->assertSame(9.5, $dto->gatewayFee());
    }

    public function test_prefers_explicit_fee_breakdown_when_present(): void
    {
        $dto = FcmbCheckoutTransaction::fromClnxData([
            'invoiceRequestReference' => 'inv-1',
            'status' => 'SUCCESS',
            'originalAmount' => 1000,
            'amount' => 1086.25,
            'serviceFee' => 75.00,
            'vatChargeFee' => 5.25,
            'stampDutyChargeFee' => 6.00,
        ]);

        $this->assertSame(86.25, $dto->gatewayFee());
    }

    public function test_amount_fields_are_optional(): void
    {
        $dto = FcmbCheckoutTransaction::fromClnxData([
            'invoiceRequestReference' => 'inv-1',
            'status' => 'FAILED',
        ]);

        $this->assertNull($dto->chargedAmount());
        $this->assertNull($dto->amountReceived());
        $this->assertNull($dto->gatewayFee());
        $this->assertTrue($dto->isFailed());
    }
}
