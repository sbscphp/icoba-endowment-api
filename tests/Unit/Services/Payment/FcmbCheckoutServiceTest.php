<?php

namespace Tests\Unit\Services\Payment;

use App\Models\Transaction;
use App\Services\Payment\CheckoutRedirectResolver;
use App\Services\Payment\FcmbCheckoutService;
use Illuminate\Support\Str;
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

    public function test_initialize_payload_includes_return_url_with_gateway_and_reference(): void
    {
        $transaction = $this->makeTransaction();

        $payload = $this->makeService()->buildInitializePayload(
            $transaction,
            null,
            'https://frontend.example.com/donate/success',
        );

        $this->assertSame(
            'https://frontend.example.com/donate/success?payment_gateway=fcmb&reference='.$transaction->uuid,
            $payload['returnUrl'],
        );
        $this->assertSame($transaction->uuid, $payload['invoiceRequestReference']);
        $this->assertSame([['label' => 'transaction_uuid', 'value' => $transaction->uuid]], $payload['customFields']);
        $this->assertSame('NGN', $payload['currency']);
        $this->assertSame('Ada', $payload['firstName']);
        $this->assertSame('Lovelace', $payload['lastName']);
        $this->assertSame('donor@example.com', $payload['email']);
    }

    public function test_initialize_payload_appends_return_query_to_existing_query_string(): void
    {
        $transaction = $this->makeTransaction();

        $payload = $this->makeService()->buildInitializePayload(
            $transaction,
            null,
            'https://frontend.example.com/donate/success?campaign=alumni',
        );

        $this->assertSame(
            'https://frontend.example.com/donate/success?campaign=alumni&payment_gateway=fcmb&reference='.$transaction->uuid,
            $payload['returnUrl'],
        );
    }

    public function test_initialize_payload_hash_excludes_return_url(): void
    {
        $transaction = $this->makeTransaction();

        $payload = $this->makeService()->buildInitializePayload(
            $transaction,
            null,
            'https://frontend.example.com/donate/success',
        );

        $expected = hash('sha512', implode('|', [
            '5000',
            'donor@example.com',
            $transaction->uuid,
            'test-secret-key',
        ]));

        $this->assertSame($expected, $payload['hash']);
    }

    private function makeTransaction(): Transaction
    {
        $transaction = new Transaction([
            'amount' => 5000,
            'currency' => 'NGN',
            'donor_email' => 'donor@example.com',
            'donor_name' => 'Ada Lovelace',
        ]);
        $transaction->uuid = (string) Str::uuid();

        return $transaction;
    }

    private function makeService(): FcmbCheckoutService
    {
        return new FcmbCheckoutService(new CheckoutRedirectResolver);
    }
}
