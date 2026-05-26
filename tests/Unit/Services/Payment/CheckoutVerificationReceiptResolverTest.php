<?php

namespace Tests\Unit\Services\Payment;

use App\Enums\TransactionStatus;
use App\Models\Transaction;
use App\Services\Payment\CheckoutVerificationReceiptResolver;
use App\Services\Receipt\ReceiptService;
use Mockery;
use Tests\TestCase;

final class CheckoutVerificationReceiptResolverTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_returns_null_when_payment_is_not_paid(): void
    {
        $transaction = new Transaction(['status' => TransactionStatus::PENDING]);

        $resolver = new CheckoutVerificationReceiptResolver(Mockery::mock(ReceiptService::class));

        $this->assertNull($resolver->resolve('unpaid', $transaction));
    }

    public function test_returns_receipt_number_when_paid_and_successful(): void
    {
        $transaction = new Transaction(['status' => TransactionStatus::SUCCESSFUL]);

        $receiptService = Mockery::mock(ReceiptService::class);
        $receiptService->shouldReceive('ensurePublicReceiptAccess')
            ->once()
            ->with($transaction)
            ->andReturn(new Transaction([
                'status' => TransactionStatus::SUCCESSFUL,
                'receipt_number' => 'ICOBA-2026-000042',
            ]));

        $resolver = new CheckoutVerificationReceiptResolver($receiptService);

        $this->assertSame('ICOBA-2026-000042', $resolver->resolve('paid', $transaction));
    }
}
