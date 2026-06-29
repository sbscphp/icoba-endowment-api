<?php

namespace Tests\Unit\Services\Payment;

use App\Services\Bank\BankAccountRegistry;
use App\Services\Payment\CheckoutRedirectResolver;
use App\Services\Payment\FcmbCheckoutService;
use Tests\TestCase;

class FcmbCheckoutServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.fcmb.base_url' => 'https://dev.clnx.io',
            'services.fcmb.business_id' => 'test-business-id',
            'services.fcmb.secret_key' => 'test-secret-key',
            'bank_accounts.accounts' => [
                [
                    'account_key' => 'fcmb_ngn',
                    'currency' => 'NGN',
                    'currency_symbol' => '₦',
                    'account_number' => '2007877660',
                ],
            ],
        ]);
    }

    public function test_verify_webhook_hash_accepts_valid_payload(): void
    {
        $payload = [
            'amount' => 109.5,
            'reference' => 'WTU-c47c8029-59c5-47f8-9c76-c3fa31f2c846',
            'invoiceRequestReference' => '1905d354-18ae-428c-849b-ef4d3d93e297',
            'transactionDate' => '2025-12-29T03:57:26',
        ];

        $plain = implode('|', [
            '109.5',
            $payload['reference'],
            $payload['invoiceRequestReference'],
            $payload['transactionDate'],
            'test-secret-key',
        ]);
        $payload['hash'] = hash('sha512', $plain);

        $service = $this->makeService();

        $this->assertTrue($service->verifyWebhookHash($payload));
    }

    public function test_verify_webhook_hash_rejects_tampered_payload(): void
    {
        $payload = [
            'amount' => 100,
            'reference' => 'WTU-test',
            'invoiceRequestReference' => '1905d354-18ae-428c-849b-ef4d3d93e297',
            'transactionDate' => '2025-12-29T03:57:26',
            'hash' => hash('sha512', 'invalid'),
        ];

        $this->assertFalse($this->makeService()->verifyWebhookHash($payload));
    }

    private function makeService(): FcmbCheckoutService
    {
        return new FcmbCheckoutService(
            new CheckoutRedirectResolver,
            new BankAccountRegistry,
        );
    }
}
